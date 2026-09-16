<?php

namespace App\Modules\Order\Models;

use App\Modules\Address\Models\Address;
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
 * @property-read Collection<int, Payment> $payments
 *
 * @method static Builder<static>|Order active()
 * @method static Builder<static>|Order unassigned()
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
        'discount_total', 'cash_surcharge', 'tax_rate', 'estimated_tax', 'estimated_total',
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
            'cash_surcharge' => 'decimal:2',
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
     * What the laundry actually earned: the washing, after any discount.
     *
     * **Not the same as `preTaxTotal()`**, and the difference is the whole of
     * how an order is divided. The customer's pre-tax total also carries the
     * delivery fee and the cash handling fee, and neither of those is the
     * laundry's work:
     *
     *   - the **delivery fee** is the platform's, and the platform pays the
     *     driver out of it. Sharing it with the laundry as well meant paying for
     *     the same journey twice — on a 10% commission the platform booked a
     *     tenth of the fee and paid out a fifth of it.
     *   - the **cash handling fee** is what it costs the platform to take notes.
     *
     * The discount comes off, because a coupon reduces what was collected for
     * the washing and `OrderPricing` already caps it at the subtotal — so this
     * can never go negative.
     *
     * Final if the pieces have been counted, the estimate until then, the same
     * rule every other figure on this order follows.
     */
    public function cleaningRevenue(): float
    {
        $subtotal = (float) ($this->hasFinalPrice() ? $this->final_subtotal : $this->estimated_subtotal);

        return round(max($subtotal - (float) $this->discount_total, 0.0), 2);
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
