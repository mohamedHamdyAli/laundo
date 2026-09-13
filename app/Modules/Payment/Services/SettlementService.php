<?php

namespace App\Modules\Payment\Services;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Enums\CommissionBasis;
use App\Modules\Payment\Models\CommissionRule;
use App\Modules\Payment\Models\OrderSettlement;
use App\Modules\Payment\Models\OrderSettlementLine;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Services\WalletService;
use App\Support\PlatformAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dividing an order between the platform and the laundry that cleaned it.
 *
 * «لو الطلب كله ب 100 وبياخد من الفيندور 10 ف ميه يبقا هيدخل ف حسابه 10 والمغسله
 * 90» — the owner's own statement of the rule, and this class is that sentence
 * with the edges filled in.
 *
 * Three things are worth stating, because each is a decision that could sensibly
 * have gone the other way:
 *
 * **The basis is the order total with the tax taken back out.** Tax is the
 * state's money passing through on its way to the treasury; splitting it would
 * have both parties drawing on a sum neither is owed. Everything else the
 * customer paid — the washing, the delivery, the cash handling fee — is in.
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
 * laundry took 90% of a fee it had not earned. The basis is now the washing
 * alone; see `basisFor()`. Every component is still stored on the row, so the
 * arithmetic can be re-cut again without archaeology.
 */
class SettlementService
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * The charges attached to this laundry.
     *
     * Active only: switching a rule off is how an operator stops charging under
     * it without detaching it from forty laundries one at a time, and «inactive
     * but still charging» would make the toggle a lie.
     *
     * @return Collection<int, CommissionRule>
     */
    public function rulesFor(?Laundry $laundry): Collection
    {
        if ($laundry === null) {
            return collect();
        }

        return CommissionRule::active()
            ->whereHas('laundries', fn ($query) => $query->whereKey($laundry->id))
            ->orderBy('id')
            ->get();
    }

    /**
     * What this laundry is charged on an order of the given size, line by line.
     *
     * **Attached rules add together** — the owner's decision, «تتجمع على بعض» —
     * and each lands as its own line so a laundry disputing the total is shown
     * the arithmetic rather than a blended number it cannot reproduce.
     *
     * **A laundry with nothing attached falls back to the general rate.** That
     * is the one place `Commission_Rate` is still read: it is the safety net for
     * a laundry nobody has configured, and it keeps the behaviour every existing
     * laundry already had. A laundry that genuinely pays nothing is expressed by
     * attaching a rule of 0, not by attaching none — which is exactly why the
     * migration that retired `laundries.commission_rate` carried a stored 0
     * across as a 0% rule rather than dropping it.
     *
     * The total is capped at the basis. Three stacking charges can otherwise sum
     * past the order, and a settlement that pays the laundry a negative number
     * is a bill for having done the work.
     *
     * @return array{total: float, lines: array<int, array<string, mixed>>}
     */
    public function commissionFor(?Laundry $laundry, float $basis): array
    {
        $basis = max(round($basis, 2), 0.0);
        $rules = $this->rulesFor($laundry);

        if ($rules->isEmpty()) {
            $configured = getSettingValue('Commission_Rate');
            $rate = ($configured === null || $configured === '') ? 0.0 : $this->clamp((float) $configured);
            $amount = round(min($basis * $rate / 100, $basis), 2);

            if ($amount <= 0) {
                return ['total' => 0.0, 'lines' => []];
            }

            return [
                'total' => $amount,
                'lines' => [[
                    'commission_rule_id' => null,
                    'name' => json_encode([
                        'en' => 'General rate',
                        'ar' => 'النسبة العامة',
                    ], JSON_UNESCAPED_UNICODE),
                    'basis' => CommissionBasis::Percent->value,
                    'rate' => $rate,
                    'amount' => $amount,
                ]],
            ];
        }

        $lines = [];
        $total = 0.0;

        foreach ($rules as $rule) {
            // Charged against the remaining basis, not the original: three rules
            // each capped at the full order could otherwise sum to three times
            // it. Whatever is left is what there is to take.
            $remaining = round($basis - $total, 2);

            if ($remaining <= 0) {
                break;
            }

            $amount = $rule->chargeOn($basis);
            $amount = round(min($amount, $remaining), 2);

            if ($amount <= 0) {
                // A rule of zero explains nothing on the settlement. It still
                // means something on the laundry — «attached, and charging
                // nothing» — but that belongs on the laundry row, not here.
                continue;
            }

            $lines[] = [
                'commission_rule_id' => $rule->id,
                // Copied, not referenced: a rename or a rate change next quarter
                // must not restate what was already charged.
                'name' => json_encode((array) $rule->name, JSON_UNESCAPED_UNICODE),
                'basis' => $rule->basis->value,
                'rate' => $rule->basis->isFixed() ? null : $rule->clampedRate(),
                'amount' => $amount,
            ];

            $total = round($total + $amount, 2);
        }

        return ['total' => $total, 'lines' => $lines];
    }

    /**
     * The blended rate a settlement came to, for display only.
     *
     * Stacking rules have no single rate — «10% plus 5 EGP» is not a percentage
     * — so this is derived from the result rather than from any rule. It exists
     * because `order_settlements.commission_rate` is what the list screen shows
     * in a narrow column; the lines are the truth.
     */
    public function effectiveRate(float $basis, float $commission): float
    {
        return $basis > 0 ? round($commission / $basis * 100, 2) : 0.0;
    }

    /**
     * What the two parties are dividing: the washing, after any discount.
     *
     * **Not the whole order.** It was, and that was wrong in a way that cost
     * money on every delivery: the customer's pre-tax total carries the delivery
     * fee, so a laundry on a 10% commission was handed 90% of a fee it had not
     * earned while the platform separately paid the driver a fifth of that same
     * fee out of its own tenth. The platform lost on every journey.
     *
     * So the delivery fee and the cash handling fee both stay with the platform
     * — it is the platform that pays the driver and the platform that pays to
     * handle notes — and the tax stays with the state. What is left is the work
     * the laundry did, and that is what gets split.
     *
     * Everything the customer paid is still accounted for. It is just no longer
     * all of it that is divided:
     *
     *     total = tax + delivery + cash surcharge + commission + laundry share
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

        $commission = $this->commissionFor($laundry, $basis);

        // Subtracted rather than computed as its own percentage, so the two
        // halves always add back to the basis to the piastre. Two independent
        // roundings would leave a remainder that belongs to nobody.
        $laundryShare = round($basis - $commission['total'], 2);

        $attributes = [
            'laundry_id' => $laundry?->id,
            'basis' => $basis,
            // Derived from the result, not from a rule: stacking charges have no
            // single rate. The lines below are the truth; this is the column the
            // narrow list cell shows.
            'commission_rate' => $this->effectiveRate($basis, $commission['total']),
            'commission_amount' => $commission['total'],
            'laundry_amount' => $laundryShare,
            'tax_amount' => $order->payableTax(),
            'status' => OrderSettlement::PENDING,
        ];

        return DB::transaction(function () use ($existing, $order, $attributes, $commission) {
            if ($existing) {
                $existing->update($attributes);
                $this->writeLines($existing, $commission['lines']);

                return $existing->refresh();
            }

            // Without the tenant scope, and so without the creating hook that
            // would overwrite `laundry_id` with the actor's own: the settlement
            // belongs to the order's laundry, and whoever completes a delivery is
            // as often the driver as anybody.
            $settlement = OrderSettlement::withoutGlobalScope('laundry')
                ->create($attributes + ['order_id' => $order->id]);

            $this->writeLines($settlement, $commission['lines']);

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
            $commission = (float) $settlement->commission_amount;
            $share = (float) $settlement->laundry_amount;

            // Zero is skipped, not credited. WalletService refuses a zero move on
            // purpose — a transaction of nothing explains nothing — and a laundry
            // on no commission produces exactly that on the platform's side.
            if ($commission > 0) {
                $this->wallets->credit(
                    $platform,
                    $commission,
                    TransactionReason::Commission,
                    $settlement,
                    __('Commission on order :code', ['code' => $order->code]),
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

    private function clamp(float $rate): float
    {
        return round(max(min($rate, 100.0), 0.0), 2);
    }
}
