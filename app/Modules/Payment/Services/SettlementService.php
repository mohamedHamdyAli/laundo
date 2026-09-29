<?php

namespace App\Modules\Payment\Services;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Enums\CommissionBasis;
use App\Modules\Payment\Models\CommissionRule;
use App\Modules\Payment\Models\OrderSettlement;
use App\Modules\Payment\Models\OrderSettlementLine;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Services\WalletService;
use App\Support\PlatformAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dividing an order between the platform and the laundry that cleaned it.
 *
 * «لو الطلب كله ب 100 وبياخد من الفيندور 10 ف ميه يبقا هيدخل ف حسابه 10 والمغسله
 * 90» — the owner's original statement of the rule. **It has since been turned
 * round**: the percentage set on a laundry is now what the *laundry* receives
 * — «المغسلة هي اللي هتاخد النسبة» — and the platform keeps the rest. The
 * columns kept their names (`commission_amount` is still the platform's part,
 * `laundry_amount` the laundry's); `laundry_share_rate` is the rate the laundry
 * was paid at, and null on rows settled the old way.
 *
 * Three things are worth stating, because each is a decision that could sensibly
 * have gone the other way:
 *
 * **The basis is the washing alone** — `cleaningRevenue()`, the laundry's own
 * piece prices less the discount; see `basisFor()`. The tax is the state's, the
 * delivery fee and the cash handling fee are the platform's (it pays the driver
 * out of them), and the customer's platform fee is the platform's own charge —
 * none of them is divided with the laundry. Widening the basis back to the
 * pre-tax total re-creates the bug the last paragraph below describes.
 *
 * **Nothing moves until the order completes.** A settlement is recorded the
 * moment the price is agreed, so both sides can see what is coming, and it stays
 * `pending` until the clothes are actually delivered. Paying a laundry for an
 * order that is later returned means clawing money back out of a wallet it may
 * already have left — the same reasoning that holds a driver's earnings.
 *
 * **Both credits happen in one transaction, or neither does.** A commission
 * taken without the laundry being paid is a silent theft, and a laundry paid
 * without the commission taken is a silent loss. Half a settlement is worse than
 * none, because none is visible on the screen as `pending`.
 *
 * The basis was «الطلب كله» — the whole order before tax — until the delivery
 * overlap was priced out: the platform was booking a tenth of each delivery fee
 * and paying the driver a fifth of it, so every journey lost money while the
 * laundry took 90% of a fee it had not earned. Every component is still stored
 * on the row, so the arithmetic can be re-cut again without archaeology.
 */
class SettlementService
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * The general share, from Settings, for a laundry nobody has set one for.
     *
     * Null when unset — and null is a real answer, not zero: it means nobody has
     * decided, and a settlement with no decision behind it waits rather than
     * paying the platform the whole of somebody else's work.
     */
    public function defaultShareRate(): ?float
    {
        $configured = getSettingValue('Laundry_Share_Rate');

        if ($configured === null || $configured === '') {
            return null;
        }

        // Clamped rather than trusted: the settings column is a string.
        return round(max(min((float) $configured, 100.0), 0.0), 2);
    }

    /**
     * The share rule attached to this laundry, if any.
     *
     * Active and percentage only. Switching a rule off is how an operator stops
     * paying under it without detaching forty laundries one at a time, and the
     * fixed basis was retired when the percentage moved to the laundry's side —
     * a fixed rule still in the table is history, not terms.
     *
     * **One per laundry.** The forms refuse a second, so more than one here is a
     * row somebody wrote by hand; the oldest wins, deterministically, and the log
     * says so rather than the two quietly adding up the way they used to.
     */
    public function ruleFor(?Laundry $laundry): ?CommissionRule
    {
        if ($laundry === null) {
            return null;
        }

        $rules = CommissionRule::active()
            ->where('basis', CommissionBasis::Percent->value)
            ->whereHas('laundries', fn ($query) => $query->whereKey($laundry->id))
            ->orderBy('id')
            ->get();

        if ($rules->count() > 1) {
            Log::warning('[settlement] laundry carries more than one active share, using the oldest', [
                'laundry' => $laundry->id,
                'rules' => $rules->pluck('id')->all(),
            ]);
        }

        return $rules->first();
    }

    /**
     * How an order's washing divides, and on whose terms.
     *
     * The percentage is **what the laundry receives** — «المغسلة هي اللي هتاخد
     * النسبة» — measured on its own piece prices **before any discount**, and
     * the platform keeps the rest. It was the other way round for the life of
     * the project; see the migration that turned every rule round.
     *
     * **Who pays for a discount is its own decision**, copied onto the order
     * from the coupon: the laundry bears `$laundryShareOfDiscount` per cent of
     * the part that came off the pieces, and the platform bears everything else
     * — the rest of that part, and all of any part that came off the delivery
     * fee. Two floors, both the owner's:
     *
     *   - the laundry never goes below zero. A coupon larger than its share
     *     leaves it with nothing on the order, and the platform carries the rest
     *     — nobody ends up owing for having done the work.
     *   - the platform *can* go below zero, and is not floored. A laundry on 90%
     *     with a 50% coupon the platform funds is paid its 90 in full; the
     *     platform's part comes out negative and `settleFor()` takes it from the
     *     platform's wallet.
     *
     * Its own rule first, then the general share in Settings. With neither there
     * is no split: `share_rate` comes back null, every amount zero, and
     * `settleFor()` will not move anything until somebody decides.
     *
     * The laundry's amount is the one computed; the platform's is taken by
     * subtraction, so the two always add back to what the customer paid for the
     * washing (`$base - $discount`) to the piastre.
     *
     * @return array{share_rate: float|null, laundry: float, laundry_gross: float, laundry_discount: float, commission: float, lines: array<int, array<string, mixed>>}
     */
    public function splitFor(
        ?Laundry $laundry,
        float $base,
        float $discount = 0.0,
        float $discountOnPieces = 0.0,
        float $laundryShareOfDiscount = 0.0,
    ): array {
        $base = max(round($base, 2), 0.0);
        $discount = max(round($discount, 2), 0.0);

        $rule = $this->ruleFor($laundry);
        $rate = $rule?->clampedRate() ?? ($laundry ? $this->defaultShareRate() : null);

        if ($rate === null) {
            return [
                'share_rate' => null, 'laundry' => 0.0, 'laundry_gross' => 0.0,
                'laundry_discount' => 0.0, 'commission' => 0.0, 'lines' => [],
            ];
        }

        $gross = round(min($base * $rate / 100, $base), 2);

        $bears = round(max(min($laundryShareOfDiscount, 100.0), 0.0), 2);
        $onPieces = round(min(max($discountOnPieces, 0.0), $discount), 2);

        // Floored at what the laundry was going to be paid: never below zero.
        $laundryDiscount = round(min($onPieces * $bears / 100, $gross), 2);
        $laundry = round($gross - $laundryDiscount, 2);

        return [
            'share_rate' => $rate,
            'laundry' => $laundry,
            'laundry_gross' => $gross,
            'laundry_discount' => $laundryDiscount,
            'commission' => round($base - $discount - $laundry, 2),
            // One line, naming the terms the laundry was paid on and what they
            // came to before its part of any discount. Copied rather than
            // referenced: renaming the rule next quarter must not restate what a
            // laundry was already paid.
            'lines' => [[
                'commission_rule_id' => $rule?->id,
                'name' => json_encode(
                    $rule ? (array) $rule->name : ['en' => 'General laundry share', 'ar' => 'نسبة المغسلة العامة'],
                    JSON_UNESCAPED_UNICODE
                ),
                'basis' => CommissionBasis::Percent->value,
                'rate' => $rate,
                'amount' => $gross,
            ]],
        ];
    }

    /**
     * The platform's cut as a percentage of the basis, for display only.
     *
     * Derived from the result rather than from any rule. It exists because
     * `order_settlements.commission_rate` predates the share moving to the
     * laundry's side, and rows settled before that are read through it.
     */
    public function effectiveRate(float $basis, float $commission): float
    {
        return $basis > 0 ? round($commission / $basis * 100, 2) : 0.0;
    }

    /**
     * What the two parties are dividing: what the customer paid for the
     * laundry's work — its own piece prices, less the whole discount.
     *
     * **Not the whole order.** It was, and that was wrong in a way that cost
     * money on every delivery: the customer's pre-tax total carries the delivery
     * fee, so a laundry was handed a share of a fee it had not earned while the
     * platform separately paid the driver out of that same fee.
     *
     * So the delivery fee, the cash handling fee and the customer's platform fee
     * all stay with the platform, and the tax stays with the state. The laundry's
     * share is measured on its prices before the discount (`splitFor()`); this
     * figure is the one the two parts add back up to:
     *
     *     total = tax + delivery + cash surcharge + platform fee + commission + laundry share
     */
    public function basisFor(Order $order): float
    {
        return $order->cleaningRevenue();
    }

    /**
     * Work out an order's split and record it, without moving anything.
     *
     * Idempotent on `order_id`, and deliberately **re-computed while pending**: a
     * final price set after the estimate, or a delivery fee derived when a
     * laundry is assigned late, both change what there is to divide. Once
     * settled, the row is frozen — money has moved against those figures, and a
     * row that restates itself afterwards is a row that cannot be audited.
     */
    public function recordFor(Order $order): ?OrderSettlement
    {
        $existing = OrderSettlement::withoutGlobalScope('laundry')
            ->where('order_id', $order->id)
            ->first();

        if ($existing && $existing->status !== OrderSettlement::PENDING) {
            return $existing;
        }

        $laundry = $this->laundryFor($order);
        $basis = $this->basisFor($order);

        $split = $this->splitFor(
            $laundry,
            $order->laundryBase(),
            $order->effectiveDiscount(),
            $order->discountOnPieces(),
            $order->laundryShareOfDiscount(),
        );

        $attributes = [
            'laundry_id' => $laundry?->id,
            'basis' => $basis,
            // The platform's cut as a percentage, derived from the result. The
            // laundry's own rate is the column below it.
            'commission_rate' => $this->effectiveRate($basis, $split['commission']),
            // Null when nobody has set a share for this laundry — the row then
            // waits, and `settleFor()` refuses to move anything.
            'laundry_share_rate' => $split['share_rate'],
            // The platform's part of the washing, after whatever of the discount
            // it bears. Below zero when the platform funded more of a coupon than
            // its part came to — `settleFor()` then takes it from its wallet.
            'commission_amount' => $split['commission'],
            'laundry_amount' => $split['laundry'],
            // The discount, and the part of it the laundry bore. Stored, not
            // derived: the order's coupon terms are copied and frozen, and a
            // settled row must say what it was paid on.
            'discount_amount' => $order->effectiveDiscount(),
            'laundry_discount_amount' => $split['laundry_discount'],
            'tax_amount' => $order->payableTax(),
            // The platform's own charge, already inside what the customer paid
            // and already outside `basis` — recorded so the revenue screen can
            // say what the platform earned from the customer as against what it
            // kept from the washing. The two are paid by different people and a
            // single «commission» figure cannot answer either question.
            'platform_fee_amount' => $order->platformFeeEarned(),
            'status' => OrderSettlement::PENDING,
        ];

        return DB::transaction(function () use ($existing, $order, $attributes, $split) {
            if ($existing) {
                $existing->update($attributes);
                $this->writeLines($existing, $split['lines']);

                return $existing->refresh();
            }

            // Without the tenant scope, and so without the creating hook that
            // would overwrite `laundry_id` with the actor's own: the settlement
            // belongs to the order's laundry, and whoever completes a delivery is
            // as often the driver as anybody.
            $settlement = OrderSettlement::withoutGlobalScope('laundry')
                ->create($attributes + ['order_id' => $order->id]);

            $this->writeLines($settlement, $split['lines']);

            return $settlement;
        });
    }

    /**
     * Move the money. Called when the order completes.
     *
     * @return OrderSettlement|null the settled row, or null when there was
     *                              nothing to divide
     */
    public function settleFor(Order $order): ?OrderSettlement
    {
        $settlement = $this->recordFor($order);

        if (! $settlement || $settlement->status !== OrderSettlement::PENDING) {
            return $settlement;
        }

        // No share, no split. Settling here would credit the platform the
        // whole basis and the laundry nothing — for work the laundry did, on
        // terms nobody set. It waits on the screen as pending instead, and
        // `SettlementController::settle()` pays it once a share exists.
        if ($settlement->laundry_share_rate === null && (float) $settlement->basis + (float) $settlement->discount_amount > 0) {
            Log::warning('[settlement] no laundry share set, left pending', [
                'order' => $order->id,
                'laundry' => $settlement->laundry_id,
            ]);

            return $settlement;
        }

        $platform = PlatformAccount::user();
        $owner = $this->laundryFor($order)?->owner;

        // Left pending rather than half-paid or silently dropped. An install with
        // no super admin, or a laundry whose owner account was deleted, is a
        // fault somebody has to fix — and a settlement sitting at «pending» on
        // the screen is how they find out.
        if (! $platform || ! $owner) {
            Log::warning('[settlement] no payee, left pending', [
                'order' => $order->id,
                'has_platform_account' => $platform !== null,
                'has_laundry_owner' => $owner !== null,
            ]);

            return $settlement;
        }

        return DB::transaction(function () use ($settlement, $order, $platform, $owner) {
            // Re-read under a lock and checked again. Completion reaches this
            // once, but a waiting settlement can now also be paid from a button,
            // and two clicks racing past the pending check above would credit
            // both wallets twice.
            $locked = OrderSettlement::withoutGlobalScope('laundry')
                ->lockForUpdate()
                ->find($settlement->id);

            if (! $locked || $locked->status !== OrderSettlement::PENDING) {
                return $locked ?? $settlement;
            }

            $settlement = $locked;

            $commission = (float) $settlement->commission_amount;
            $share = (float) $settlement->laundry_amount;
            $platformFee = (float) $settlement->platform_fee_amount;

            // The customer's fee first, so that a platform funding a coupon below
            // its part of the washing draws on the fee it has just been paid
            // before it draws on anything else.
            //
            // Credited, not merely recorded. The platform's wallet is the ledger
            // of what the platform earned on orders, so a fee that only ever
            // appeared on the revenue screen would leave that wallet
            // understating earnings.
            if ($platformFee > 0) {
                $this->wallets->credit(
                    $platform,
                    $platformFee,
                    TransactionReason::PlatformFee,
                    $settlement,
                    __('Platform fee on order :code', ['code' => $order->code]),
                );
            }

            // Zero is skipped, not credited. WalletService refuses a zero move on
            // purpose — a transaction of nothing explains nothing.
            if ($commission > 0) {
                $this->wallets->credit(
                    $platform,
                    $commission,
                    TransactionReason::Commission,
                    $settlement,
                    __('Commission on order :code', ['code' => $order->code]),
                );
            }

            // Below zero: the platform funded more of a discount than its part of
            // the washing came to, and pays the difference — the owner's rule,
            // «المنصة تدفع الفرق». Allowed to overdraw: the laundry is owed its
            // share in full whatever the platform's balance happens to be, and a
            // settlement that failed here would leave the laundry unpaid for a
            // coupon it never agreed to fund.
            if ($commission < 0) {
                $this->wallets->debit(
                    $platform,
                    abs($commission),
                    TransactionReason::DiscountFunded,
                    $settlement,
                    __('Discount funded on order :code', ['code' => $order->code]),
                    allowOverdraft: true,
                );
            }

            if ($share > 0) {
                $this->wallets->credit(
                    $owner,
                    $share,
                    TransactionReason::LaundryPayout,
                    $settlement,
                    __('Share of order :code', ['code' => $order->code]),
                );
            }

            $settlement->update([
                'status' => OrderSettlement::SETTLED,
                'settled_at' => now(),
            ]);

            return $settlement->refresh();
        });
    }

    /**
     * Pay a completed order whose settlement was left waiting.
     *
     * Completion is the only moment money moves on its own, and it can find
     * nothing to pay with — no share set for the laundry, or no owner account to
     * credit. The row then sits pending, and until now nothing could ever move
     * it again. This is the way back: once somebody has fixed what was missing,
     * the settlement is recomputed on today's terms and paid.
     *
     * @return string|null why it could not be paid, or null when it was
     */
    public function settleWaiting(OrderSettlement $settlement): ?string
    {
        $order = $settlement->order;

        if (! $order || $settlement->status !== OrderSettlement::PENDING) {
            return __('This settlement is not waiting to be paid.');
        }

        // An order still in progress is paid when it completes. Paying it now
        // would move money for clothes nobody has delivered.
        if ($order->status !== OrderStatus::Completed) {
            return __('This order has not completed yet. It is paid automatically when it does.');
        }

        $settled = $this->settleFor($order);

        if ($settled?->status !== OrderSettlement::SETTLED) {
            return __('Still waiting. Set a share for this laundry, or the general share in Settings, and make sure the laundry has an owner account.');
        }

        return null;
    }

    /**
     * An order that never completed owes nobody anything.
     *
     * Only a pending row is cancelled. A settled one has real transactions
     * against it, and reversing those is a refund — a deliberate act with its own
     * ledger entries, not something a status change should do behind everybody's
     * back.
     */
    public function cancelFor(Order $order): ?OrderSettlement
    {
        $settlement = OrderSettlement::withoutGlobalScope('laundry')
            ->where('order_id', $order->id)
            ->first();

        if (! $settlement || $settlement->status !== OrderSettlement::PENDING) {
            return $settlement;
        }

        $settlement->update(['status' => OrderSettlement::CANCELLED]);

        return $settlement->refresh();
    }

    /**
     * Replace a pending settlement's lines with the ones just worked out.
     *
     * Delete-then-insert rather than a per-row diff: a line has no identity of
     * its own worth preserving — it is a name, a rate and a result — and a
     * settlement being recomputed has by definition changed. Only ever reached
     * while the settlement is pending; a settled one is frozen before this.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function writeLines(OrderSettlement $settlement, array $lines): void
    {
        OrderSettlementLine::where('order_settlement_id', $settlement->id)->delete();

        foreach ($lines as $line) {
            OrderSettlementLine::create($line + ['order_settlement_id' => $settlement->id]);
        }
    }

    /**
     * The order's laundry, read past the tenant scope.
     *
     * `Laundry` filters itself to the signed-in actor's own row, and the actor
     * here is whoever moved the order — often a driver, sometimes a scheduled
     * command. Reading it scoped would return null and record a settlement with
     * no payee.
     */
    private function laundryFor(Order $order): ?Laundry
    {
        if ($order->laundry_id === null) {
            return null;
        }

        return Laundry::withoutGlobalScope('own_laundry')->find($order->laundry_id);
    }

    // `clamp()` lived here to guard the general-rate fallback. That fallback is
    // gone — the key it read is now the customer's fee — and `CommissionRule`
    // has always clamped its own rate through `clampedRate()`, so there is
    // nothing left for this to protect.
}
