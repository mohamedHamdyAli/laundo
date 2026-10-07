<?php

namespace App\Modules\Order\Models;

use App\Modules\Address\Models\Address;
use App\Modules\Complaint\Models\Complaint;
use App\Modules\Coupon\Services\ReferralService;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Offer\Models\Offer;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Payment\Models\OrderSettlement;
use App\Modules\Payment\Models\Payment;
use App\Modules\Service\Models\Service;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
use App\Trait\BelongsToLaundry;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An order.
 *
 * Tenant-scoped through BelongsToLaundry, so a laundry sees only its own work and
 * a super admin sees everything — the same rule as every other tenant-owned
 * table. Note the consequence for **unassigned** orders: with `laundry_id` null
 * they fall outside a tenant's scope entirely, which is correct. Nobody should be
 * looking at work that has not been given to them.
 *
 * @property int $id
 * @property string $code
 * @property int $user_id
 * @property int|null $laundry_id
 * @property int $service_id
 * @property OrderStatus $status
 * @property int $pickup_address_id
 * @property int $delivery_address_id
 * @property int|null $pickup_slot_id
 * @property int|null $delivery_slot_id
 * @property Carbon|null $pickup_date
 * @property Carbon|null $delivery_date
 * @property string $delivery_method
 * @property string|null $driver_note
 * @property string|null $special_instructions
 * @property int $estimated_items_count
 * @property string $estimated_subtotal
 * @property string $delivery_fee
 * @property string $discount_total
 * @property string|null $discount_laundry_share
 * @property bool $discount_covers_delivery
 * @property array{type: string, ids: list<int>, agreed: float, rate: float|null}|null $discount_scope
 * @property string $estimated_total
 * @property int|null $final_items_count
 * @property string|null $final_subtotal
 * @property string|null $final_total
 * @property string|null $tax_rate
 * @property string $estimated_tax
 * @property string|null $final_tax
 * @property string|null $review_note
 * @property int $review_round
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $review_terms_accepted_at
 * @property string|null $coupon_code
 * @property string|null $payment_method
 * @property string $payment_status
 * @property Carbon|null $paid_at
 * @property string $qr_token
 * @property int|null $recurrence_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderStatusLog> $statusLogs
 * @property-read Collection<int, OrderMedia> $media
 * @property-read Collection<int, OrderPriceQuery> $priceQueries
 * @property-read Collection<int, OrderTask> $tasks
 * @property-read Collection<int, Complaint> $complaints
 * @property-read Collection<int, Payment> $payments
 *
 * @method static Builder<static>|Order active()
 * @method static Builder<static>|Order unassigned()
 * @method static Builder<static>|Order withOpenPieceCheck()
 * @method static Builder<static>|Order search(?string $search, array $columns = [])
 */
class Order extends Model
{
    use BelongsToLaundry;
    use DashboardModel;
    use Searchable;

    protected $fillable = [
        'code', 'user_id', 'laundry_id', 'service_id', 'status',
        'pickup_address_id', 'delivery_address_id',
        'pickup_slot_id', 'delivery_slot_id', 'pickup_date', 'delivery_date',
        'delivery_method', 'pickup_method', 'offer_id',
        'driver_note', 'special_instructions', 'review_terms_accepted_at',
        'estimated_items_count', 'estimated_subtotal', 'delivery_fee',
        'discount_total', 'discount_laundry_share', 'discount_covers_delivery', 'discount_scope',
        'cash_surcharge', 'platform_fee', 'platform_fee_rate', 'price_increase_rate',
        'tax_rate', 'estimated_tax', 'estimated_total',
        'final_items_count', 'final_subtotal', 'final_tax', 'final_total', 'review_note', 'reviewed_at',
        'review_round', 'confirmed_at',
        'coupon_code', 'payment_method', 'payment_status', 'paid_at',
        'qr_token', 'recurrence_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'pickup_date' => 'date',
            'delivery_date' => 'date',
            'reviewed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'review_terms_accepted_at' => 'datetime',
            'paid_at' => 'datetime',
            'estimated_subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'discount_laundry_share' => 'decimal:2',
            'discount_covers_delivery' => 'boolean',
            'discount_scope' => 'array',
            'cash_surcharge' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'platform_fee_rate' => 'decimal:2',
            'price_increase_rate' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'estimated_tax' => 'decimal:2',
            'estimated_total' => 'decimal:2',
            'final_subtotal' => 'decimal:2',
            'final_tax' => 'decimal:2',
            'final_total' => 'decimal:2',
        ];
    }

    /**
     * The customer reference, as «طلب رقم #10244».
     *
     * Sequential from a five-digit base so it reads like the design rather than
     * exposing a raw row id, and unique-checked because two orders placed in the
     * same instant would otherwise collide.
     */
    public static function generateCode(): string
    {
        do {
            $candidate = (string) (10000 + (int) (static::withoutGlobalScopes()->max('id') ?? 0) + random_int(1, 9));
        } while (static::withoutGlobalScopes()->where('code', $candidate)->exists());

        return $candidate;
    }

    /**
     * The token the driver scans at each of the four handovers.
     */
    public static function generateQrToken(): string
    {
        do {
            $token = Str::random(40);
        } while (static::withoutGlobalScopes()->where('qr_token', $token)->exists());

        return $token;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Nullable by design: an order with no covering laundry is accepted and left
     * for operations to assign.
     */
    /**
     * @return BelongsTo<Laundry, $this>
     */
    public function laundry(): BelongsTo
    {
        return $this->belongsTo(Laundry::class, 'laundry_id');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /**
     * The «عروض متميزة» card this order came through, when it did.
     *
     * Null for an order placed straight from the wizard, which is most of them.
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class, 'offer_id');
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function pickupAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'pickup_address_id');
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'delivery_address_id');
    }

    /**
     * @return BelongsTo<TimeSlot, $this>
     */
    public function pickupSlot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class, 'pickup_slot_id');
    }

    /**
     * @return BelongsTo<TimeSlot, $this>
     */
    public function deliverySlot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class, 'delivery_slot_id');
    }

    /**
     * @return BelongsTo<OrderRecurrence, $this>
     */
    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(OrderRecurrence::class, 'recurrence_id');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /**
     * What the customer counted when ordering.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function estimatedItems(): HasMany
    {
        return $this->items()->where('phase', 'estimated');
    }

    /**
     * What the laundry counted on inspection (P7).
     *
     * @return HasMany<OrderItem, $this>
     */
    public function finalItems(): HasMany
    {
        return $this->items()->where('phase', 'final');
    }

    /**
     * @return HasMany<OrderStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class, 'order_id')->latest();
    }

    /**
     * @return HasMany<OrderMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(OrderMedia::class, 'order_id');
    }

    /**
     * Every attempt to pay for this order, successful or not.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'order_id')->latest('id');
    }

    /**
     * How this order was divided between the platform and the laundry.
     *
     * A hasOne rather than a hasMany: the unique key on `order_settlements
     * .order_id` is what stops a replayed completion paying a laundry twice, and
     * the relation says so.
     *
     * @return HasOne<OrderSettlement, $this>
     */
    public function settlement(): HasOne
    {
        return $this->hasOne(OrderSettlement::class, 'order_id');
    }

    /**
     * «ادعُ أصدقاءك» is paid here rather than at the two places that settle
     * payment.
     *
     * Cash on the doorstep and a captured card both mark an order paid, and a
     * third way will arrive. Hanging the reward off the transition itself means
     * the new one cannot be the one that forgets.
     */
    protected static function booted(): void
    {
        static::updated(function (Order $order): void {
            if ($order->wasChanged('paid_at') && $order->paid_at !== null) {
                app(ReferralService::class)->rewardFor($order);
            }
        });
    }

    /**
     * The four physical journeys, in the order they happen.
     *
     * @return HasMany<OrderTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(OrderTask::class, 'order_id')->orderBy('sequence');
    }

    /**
     * «لدي استفسار عن السعر» — questions the customer raised about the price.
     *
     * @return HasMany<OrderPriceQuery, $this>
     */
    public function priceQueries(): HasMany
    {
        return $this->hasMany(OrderPriceQuery::class, 'order_id')->latest('id');
    }

    /**
     * «شكوى» filed about this order — by the customer, or by a driver who had a
     * leg of it. Narrow it to one complainant before asking anything of it:
     * see `ComplaintService::hasComplained()`.
     *
     * @return HasMany<Complaint, $this>
     */
    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class, 'order_id');
    }

    /**
     * Orders still moving through the pipeline — the design's «نشط» tab.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            OrderStatus::Completed->value,
            OrderStatus::Cancelled->value,
            OrderStatus::Returned->value,
        ]);
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('laundry_id');
    }

    /**
     * Orders with a piece count a driver disagreed on that nobody has reviewed.
     *
     * The one definition behind the sidebar badge, both home-page queues, the
     * orders filter and its export: all of them count **orders**, so a badge
     * reading 1 opens a list of one — an order with two open legs is one order
     * to look at. Rooted here, the tenant scope applies: a laundry counts its own.
     */
    public function scopeWithOpenPieceCheck(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('id'), PieceDiscrepancy::open()->select('order_id'));
    }

    /**
     * Every time two counts of this order's pieces disagreed — at a handover or
     * at the laundry's review — open or reviewed.
     *
     * @return HasMany<PieceDiscrepancy, $this>
     */
    public function pieceDiscrepancies(): HasMany
    {
        return $this->hasMany(PieceDiscrepancy::class, 'order_id')->orderBy('id');
    }

    /**
     * The figure that actually applies: the final price once the laundry has set
     * one, the estimate until then.
     */
    public function payableTotal(): float
    {
        return (float) ($this->final_total ?? $this->estimated_total);
    }

    /**
     * The tax on the figure that actually applies.
     */
    public function payableTax(): float
    {
        return (float) ($this->hasFinalPrice() ? $this->final_tax : $this->estimated_tax);
    }

    /**
     * What the two parties divide: the payable total with the tax taken back out.
     *
     * Tax is the state's money. It passes through the platform on its way to the
     * treasury, so counting it as revenue would have the platform and the laundry
     * splitting a sum neither of them is owed.
     */
    public function preTaxTotal(): float
    {
        return round(max($this->payableTotal() - $this->payableTax(), 0.0), 2);
    }

    /**
     * The laundry's own prices for the pieces — what its share is measured on.
     *
     * **Not the same as `preTaxTotal()`**, and the difference is the whole of
     * how an order is divided. The customer's pre-tax total also carries three
     * things that are not the laundry's work:
     *
     *   - the **delivery fee** is the platform's, and the platform pays the
     *     driver out of it. Sharing it with the laundry as well meant paying for
     *     the same journey twice.
     *   - the **cash handling fee** is what it costs the platform to take notes.
     *   - the **platform fee** is the platform's charge on the order, folded
     *     into the per-piece prices so the customer sees one number. It sits
     *     inside the subtotal and it is not the laundry's, so it comes out
     *     before anything is measured. An order placed before that fee existed
     *     carries null, which subtracts nothing.
     *
     * **Before the discount.** Who pays for a coupon is decided separately —
     * `discountOnPieces()` and `laundryShareOfDiscount()` — rather than by taking
     * it off here, which used to make the laundry bear its own percentage of
     * every discount the platform handed out.
     *
     * Final if the pieces have been counted, the estimate until then, the same
     * rule every other figure on this order follows.
     */
    public function laundryBase(): float
    {
        return round(max($this->pieceSubtotal() - (float) $this->platform_fee, 0.0), 2);
    }

    /**
     * What the customer paid for the laundry's work: its base, less the whole
     * discount. The settlement's `basis` — the figure the platform's part and the
     * laundry's part add back up to.
     *
     * Can be below zero, and deliberately is not floored: a coupon larger than
     * the laundry's prices (possible once the platform fee sits inside the
     * subtotal) is a coupon the platform funds from its own fee, and flooring
     * this would lose the piastres that prove it.
     */
    public function cleaningRevenue(): float
    {
        return round($this->laundryBase() - $this->effectiveDiscount(), 2);
    }

    /**
     * The platform's fee on this order, whole.
     *
     * It used to be reduced by its own share of a discount. The discount now
     * comes entirely off `cleaningRevenue()` — the platform's side of it lands
     * in the settlement's `commission_amount` — so the fee is recorded as it was
     * charged, and the fee and the platform's part still add up to what the
     * platform keeps.
     */
    public function platformFeeEarned(): float
    {
        return round(max((float) $this->platform_fee, 0.0), 2);
    }

    /**
     * The discount as it actually came off the washing.
     *
     * Capped at the subtotal: the coupon was sized on the estimate and never
     * re-read, so a review that counted fewer pieces can leave a stored discount
     * larger than what it now applies to.
     */
    public function effectiveDiscount(): float
    {
        return round(min(max((float) $this->discount_total, 0.0), $this->pieceSubtotal()), 2);
    }

    /**
     * The part of the discount that came off the pieces rather than the
     * delivery fee.
     *
     * A coupon that «also discounts the delivery fee» was sized on the pieces
     * and the journey together; the journey's part is **always the platform's**,
     * because the delivery fee is the platform's to begin with. Split in
     * proportion to the two figures it was measured on, the same way it was
     * worked out.
     */
    public function discountOnPieces(): float
    {
        $discount = $this->effectiveDiscount();

        if (! $this->discount_covers_delivery) {
            return $discount;
        }

        $pieces = $this->pieceSubtotal();
        $measured = $pieces + max((float) $this->delivery_fee, 0.0);

        return $measured > 0 ? round($discount * ($pieces / $measured), 2) : 0.0;
    }

    /**
     * The share of the discount the laundry bears, 0–100, as copied onto the
     * order at placement. Null — an order placed before the choice existed, or
     * one with no coupon — is the platform bearing it.
     */
    public function laundryShareOfDiscount(): float
    {
        return round(max(min((float) ($this->discount_laundry_share ?? 0), 100.0), 0.0), 2);
    }

    /**
     * The pieces' total as the customer is charged it — final once counted.
     */
    private function pieceSubtotal(): float
    {
        return round(max((float) ($this->hasFinalPrice() ? $this->final_subtotal : $this->estimated_subtotal), 0.0), 2);
    }

    /**
     * The tax rate this order was placed under, as a percentage.
     *
     * The order's own, never the setting's. An order agreed at 10% stays at 10%
     * when the rate moves — the same rule that copies unit prices onto the order.
     */
    public function taxRate(): float
    {
        return (float) ($this->tax_rate ?? 0);
    }

    /**
     * The bill, as the ordered rows that make it up.
     *
     * **One source, because there were two and they disagreed.** The order
     * screen's pricing card and the printed invoice each assembled these rows
     * in their own Blade file, so the same order showed «Estimated subtotal»
     * on one and «Subtotal» on the other, and only the invoice carried the
     * subtotal-before-tax line. Two documents about one sum that do not agree
     * is the argument you lose in front of a customer.
     *
     * They are still rendered separately — one is a card in a panel and the
     * other is a printed page — but neither decides *which* rows exist or what
     * they are called.
     *
     * Zero rows are dropped rather than printed: a «Discount EGP 0.00» line
     * invites the question of which discount, and the delivery fee stays even
     * at zero because free delivery is a thing worth saying.
     *
     * @return array<int, array{key: string, label: string, amount: float, note: string|null, kind: string}>
     */
    public function moneyRows(): array
    {
        $final = $this->hasFinalPrice();
        $subtotal = (float) ($final ? $this->final_subtotal : $this->estimated_subtotal);
        $delivery = (float) $this->delivery_fee;
        $discount = (float) $this->discount_total;
        $surcharge = (float) $this->cash_surcharge;
        $tax = $this->payableTax();

        $rows = [
            ['key' => 'subtotal', 'label' => __('Subtotal'), 'amount' => $subtotal, 'note' => null, 'kind' => 'line'],
            ['key' => 'delivery_fee', 'label' => __('Delivery fee'), 'amount' => $delivery, 'note' => null, 'kind' => 'line'],
        ];

        if ($discount > 0) {
            // Carried negative so nothing downstream has to remember to subtract
            // it — a sign that lives in the renderer is a sign one renderer
            // eventually forgets.
            $rows[] = ['key' => 'discount', 'label' => __('Discount'), 'amount' => -$discount, 'note' => null, 'kind' => 'credit'];
        }

        if ($surcharge > 0) {
            // Its own line rather than folded into delivery: the customer can
            // avoid it by paying another way, and a charge you cannot see is a
            // charge you cannot avoid.
            $rows[] = ['key' => 'cash_surcharge', 'label' => __('Cash handling fee'), 'amount' => $surcharge, 'note' => null, 'kind' => 'line'];
        }

        if ($tax > 0) {
            // The subtotal-before-tax line only earns its place when something
            // moved the subtotal; with nothing between them it would restate the
            // row above it.
            if ($delivery != 0.0 || $discount > 0 || $surcharge > 0) {
                $rows[] = ['key' => 'pre_tax', 'label' => __('Subtotal before tax'), 'amount' => $this->preTaxTotal(), 'note' => null, 'kind' => 'subtotal'];
            }

            // The rate beside the amount, always. An operator reading an old
            // order needs what it was charged at, which is not necessarily what
            // the settings say today — the rate is copied onto the order at
            // placement and never re-read.
            $rows[] = [
                'key' => 'tax',
                'label' => __('Tax'),
                'amount' => $tax,
                'note' => rtrim(rtrim(number_format($this->taxRate(), 2), '0'), '.').'%',
                'kind' => 'line',
            ];
        }

        $rows[] = ['key' => 'total', 'label' => __('Total'), 'amount' => $this->payableTotal(), 'note' => null, 'kind' => 'total'];

        return $rows;
    }

    /**
     * True once the laundry has counted the pieces and set a price.
     */
    public function hasFinalPrice(): bool
    {
        return $this->final_total !== null;
    }

    /**
     * How much the review moved the bill, positive or negative.
     *
     * Null until there is a final price to compare against — an unreviewed order
     * has no difference, which is not the same as a difference of zero.
     */
    public function priceDifference(): ?float
    {
        if (! $this->hasFinalPrice()) {
            return null;
        }

        return round((float) $this->final_total - (float) $this->estimated_total, 2);
    }

    /**
     * Whether pickup and delivery are the same place, which is what the design's
     * «التوصيل لنفس العنوان» toggle controls.
     */
    public function isRoundTrip(): bool
    {
        return $this->pickup_address_id === $this->delivery_address_id;
    }
}
