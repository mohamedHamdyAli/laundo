<?php

namespace App\Modules\Order\Models;

use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\PieceCountSource;
use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One leg of an order's journey.
 *
 * Not tenant-scoped, and that is deliberate: a task belongs to a *driver*, and
 * drivers are not tenants. The isolation on the driver API comes from querying
 * through `$request->user()->tasks()`, the same rule as customers and their
 * orders.
 *
 * @property int $id
 * @property int $order_id
 * @property TaskType $type
 * @property int $sequence
 * @property TaskStatus $status
 * @property int|null $driver_id
 * @property Carbon|null $assigned_at
 * @property Carbon|null $due_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $piece_count
 * @property int|null $hangers_returned
 * @property int|null $hangers_confirmed what the laundry says reached it (item 11, 2026-11-08)
 * @property int|null $hangers_collected the hangers the driver received at the collection from the laundry (item 11, 2026-11-09)
 * @property Carbon|null $hangers_confirmed_at
 * @property int|null $hangers_confirmed_by
 * @property int|null $expected_piece_count
 * @property PieceCountSource|null $expected_piece_source
 * @property string|null $receiver_name
 * @property string|null $signature_path
 * @property string|null $collected_amount
 * @property TaskFailureReason|null $failure_reason
 * @property string|null $failure_note
 * @property int $attempts
 *
 * @method static Builder<static>|OrderTask open()
 * @method static Builder<static>|OrderTask queued()
 * @method static Builder<static>|OrderTask offerable()
 * @method static Builder<static>|OrderTask late()
 */
class OrderTask extends Model
{
    /*
     * The marker `PermissionGenerator` walks for. It is here so the dispatch
     * board can have a permission of its own — `order_task.view` — which is
     * what puts it in the sidebar (`MenuBuilder` builds from `{key}.view`) and
     * what lets a role be given the board without full access to orders.
     */
    use DashboardModel;

    /**
     * Two failures and a task stops going round the pool.
     *
     * A task nobody can complete is not a dispatch problem, and sending a third
     * driver to the same wrong address only spends somebody's afternoon.
     */
    public const MAX_ATTEMPTS = 2;

    /**
     * The statuses that mean "still to be done".
     *
     * Named once so `scopeOpen` and `scopeLate` cannot drift apart — a late task
     * that is not an open task would be a contradiction.
     *
     * @var array<int, string>
     */
    private const OPEN_STATUSES = ['pending', 'assigned', 'started'];

    protected $fillable = [
        'order_id', 'type', 'sequence', 'status', 'driver_id', 'assigned_at', 'due_at',
        'started_at', 'completed_at', 'piece_count', 'receiver_name', 'signature_path',
        'collected_amount', 'failure_reason', 'failure_note', 'attempts', 'note',
        'expected_piece_count', 'expected_piece_source', 'hangers_returned',
        'hangers_confirmed', 'hangers_confirmed_at', 'hangers_confirmed_by',
        'hangers_collected',
    ];

    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'failure_reason' => TaskFailureReason::class,
            'expected_piece_source' => PieceCountSource::class,
            'assigned_at' => 'datetime',
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'collected_amount' => 'decimal:2',
            'hangers_returned' => 'integer',
            'hangers_confirmed' => 'integer',
            'hangers_collected' => 'integer',
            'hangers_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    /**
     * The disagreement this leg's count raised, if it raised one — a leg
     * completes once, so there is at most one.
     *
     * @return HasOne<PieceDiscrepancy, $this>
     */
    public function discrepancy(): HasOne
    {
        return $this->hasOne(PieceDiscrepancy::class, 'order_task_id');
    }

    /**
     * What the driver takes at the door on this leg — the one answer the
     * driver app's «تفاصيل الدفع», the completion and the payment record all
     * read (2026-11-08).
     *
     * - **Pickup**: the pickup trip's fee («رسوم المشوار»), on a cash order not
     *   paid yet — the owner: «لما الدليفري يروح يستلم ياخد منه 25». An order
     *   paid online pays both trips in its total; an order priced by distance
     *   (`pickup_fee` null) collects nothing here, as before.
     * - **Delivery**: whatever is still owed (`Order::stillOwed()`), the pickup's
     *   fee already taken off.
     * - The laundry legs: nothing.
     */
    public function toCollect(): float
    {
        $order = $this->order;

        if (! $order || $order->payment_status === 'paid') {
            return 0.0;
        }

        return match ($this->type) {
            TaskType::PickupFromCustomer => $order->payment_method === 'cash' && (float) $order->pickup_fee > 0
                ? round(min((float) $order->pickup_fee, $order->stillOwed()), 2)
                : 0.0,
            TaskType::DeliverToCustomer => $order->stillOwed(),
            default => 0.0,
        };
    }

    /**
     * Whether «تأكيد» on this leg must say what changed hands
     * (`collected_amount`, 0 allowed): the delivery of an order not paid yet.
     *
     * **Not the pickup**, though it may take the trip fee: a driver app that
     * predates the trip fee sends no amount there, and refusing its pickups
     * would stop every collection until the app is updated. A pickup that
     * reports nothing leaves the fee owed, and the delivery takes it with the
     * rest (`Order::stillOwed()`).
     */
    public function requiresCollectedAmount(): bool
    {
        return $this->type === TaskType::DeliverToCustomer
            && $this->order !== null
            && $this->order->payment_status !== 'paid';
    }

    /**
     * The number that stands for this leg: what the platform found it really
     * was, if its disagreement was reviewed, and otherwise what the driver
     * counted. The next handover is measured against this, so a typo corrected
     * at review is not raised a second time downstream.
     */
    public function countedPieces(): ?int
    {
        $count = $this->discrepancy->confirmed_count ?? $this->piece_count;

        return $count === null ? null : (int) $count;
    }

    /**
     * Still to be done.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /**
     * The dispatch queue: tasks nobody holds.
     */
    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', TaskStatus::Pending->value);
    }

    /**
     * A leg that is actually waiting on a human being.
     *
     * **No driver, and not finished.** One definition, in one place, because
     * three callers need it to agree: the dispatch board's list, its three
     * counters, and the sidebar badge that points at all of them.
     *
     * They did not agree. The badge counted `queued()` — which is
     * `status = pending` and says nothing about `driver_id` — so a leg already
     * handed to a driver was counted as needing somebody, and the number beside
     * Dispatch read 15 against a board showing 19. A badge is a promise about
     * the screen behind it, and the only way to keep that promise is for the two
     * to run the same query.
     *
     * `queued()` stays what it is: dispatch uses it to mean «in the pool», which
     * is a different question from «needs a person».
     *
     * Written against the finished statuses rather than against `completed`
     * alone: a leg of a cancelled order has no driver and is not completed, so
     * the original test counted it as work waiting on somebody. Nobody is going
     * to drive it, and a dispatch board that lists it teaches an operator to
     * scroll past the ones that are real.
     */
    public function scopeNeedingAPerson(Builder $query): Builder
    {
        // A collection whose laundry has not finished is nobody's to assign yet
        // (item 18) — the board would list it and its badge count it.
        return $query->offerable()
            ->whereNull('driver_id')
            ->whereNotIn('status', [
                TaskStatus::Completed->value,
                TaskStatus::Cancelled->value,
            ]);
    }

    /**
     * «السجل» — the legs that are over, however they ended.
     *
     * One definition because the driver app's history screen and its filter have
     * to agree on what «الكل» contains.
     */
    public function scopeFinished(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TaskStatus::Completed->value,
            TaskStatus::Failed->value,
            TaskStatus::Cancelled->value,
        ]);
    }

    /**
     * The driver app's order: soonest first, and **stable**.
     *
     * The list was already sorted by `due_at` with the undated legs last. What it
     * had no answer for was a tie — and ties are the common case here, because
     * the four legs of one order share a window and a leg loses its `due_at`
     * entirely when it is postponed or when the order was placed without a
     * delivery slot. MySQL is free to return tied rows in any order it likes and
     * does not promise the same one twice, so the tail of the list reshuffled
     * between requests, and with pagination on top of it a task could arrive on
     * both page one and page two, or on neither.
     *
     * `sequence` then `id` settles it: the legs of an order read in the order
     * they happen, and two different orders fall back to which was created first.
     */
    public function scopeInDueOrder(Builder $query): Builder
    {
        return $query->orderByRaw('due_at is null, due_at')
            ->orderBy('sequence')
            ->orderBy('id');
    }

    /**
     * «متأخرة» — the window has passed and the task is not finished.
     */
    public function scopeLate(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }

    public function isLate(): bool
    {
        return $this->status->isOpen()
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /**
     * «المدة» in the history screen.
     */
    public function durationMinutes(): ?int
    {
        if (! $this->started_at || ! $this->completed_at) {
            return null;
        }

        return (int) $this->started_at->diffInMinutes($this->completed_at);
    }

    /**
     * Whether the leg before this one is done.
     *
     * The safety property of the whole phase: nothing can be delivered that was
     * never collected. Leg 1 has no predecessor and is therefore always ready.
     */
    public function predecessorComplete(): bool
    {
        if ($this->sequence <= 1) {
            return true;
        }

        return static::where('order_id', $this->order_id)
            ->where('sequence', $this->sequence - 1)
            ->where('status', TaskStatus::Completed->value)
            ->exists();
    }

    /**
     * Whether the order lets this leg go ahead yet.
     *
     * Only the collection from the laundry waits on the order: the pieces may
     * leave once the laundry has priced them and the customer has agreed, which
     * is `cleaning` (or later). It used to wait on nothing but the leg before
     * it, so on live a driver collected and delivered orders still `picked_up`:
     * back at the customer's door with no price, nothing to collect, and an
     * order that could never move again (the owner, 2026-10-01).
     *
     * **And the laundry has said it finished** (`finished`, «خلص الغسيل» —
     * the client's sheet, item 18, 2026-11-08): `cleaning` alone no longer
     * lets the collection start. `$underWay` is the one exception — a
     * collection already started at `cleaning` before the rule existed still
     * finishes, the way a leg under way always does.
     */
    public function orderAllows(bool $underWay = false): bool
    {
        if ($this->type !== TaskType::CollectFromLaundry) {
            return true;
        }

        $allowed = OrderStatus::collectable();

        if ($underWay) {
            $allowed[] = OrderStatus::Cleaning;
        }

        return in_array($this->order?->status, $allowed, true);
    }

    /**
     * «مؤجّل — بانتظار موعد من العميل»: a leg the customer has to rebook.
     *
     * Stored as `failed` — the attempt did fail, and the reports count it — but
     * it is not a failure anybody has to rescue: the customer was asked for a
     * new time (`TaskFailureReason::needsANewSlot()`). The panel shows it as a
     * postponement, never «Failed» (the owner, 2026-11-11).
     *
     * An **absence** also needs its window cleared: that is what tells it apart
     * from one that failed its way out under the old rule (two attempts —
     * before 2026-11-08 at the pickup, before 2026-11-11 at the delivery),
     * which kept its window and is an operator's rescue. A postponement has
     * always gone to the customer, window or not.
     *
     * `scopeAwaitingNewTime()` is the same question as a query — change the
     * two together.
     */
    public function awaitsNewTime(): bool
    {
        if ($this->status !== TaskStatus::Failed || ! $this->failure_reason?->needsANewSlot($this->type)) {
            return false;
        }

        if ($this->failure_reason !== TaskFailureReason::CustomerUnavailable) {
            return true;
        }

        $order = $this->order;

        if ($order === null) {
            return false;
        }

        $column = $this->type->onPickupWindow() ? 'pickup_slot_id' : 'delivery_slot_id';

        // An order loaded with a column list that left the window out would
        // read null here and call every old absence «postponed» — asked of the
        // row instead.
        if (! array_key_exists($column, $order->getAttributes())) {
            return $order->newQuery()->whereKey($order->getKey())->value($column) === null;
        }

        return $order->getAttribute($column) === null;
    }

    /**
     * `awaitsNewTime()` as a query. Through the tenant-scoped `Order`.
     */
    public function scopeAwaitingNewTime(Builder $query): Builder
    {
        // Every (reason, leg) the enum sends back to the customer — read off
        // `needsANewSlot()` so the two cannot drift apart.
        $pairs = [];

        foreach (TaskFailureReason::cases() as $reason) {
            foreach (TaskType::cases() as $type) {
                if ($reason->needsANewSlot($type)) {
                    $pairs[] = [$reason->value, $type];
                }
            }
        }

        return $query
            ->where($query->qualifyColumn('status'), TaskStatus::Failed->value)
            ->where(function (Builder $q) use ($pairs): void {
                foreach ($pairs as [$reason, $type]) {
                    $q->orWhere(fn (Builder $pair) => $pair
                        ->where($pair->qualifyColumn('failure_reason'), $reason)
                        ->where($pair->qualifyColumn('type'), $type->value)
                        // An absence only with its window cleared (see above).
                        ->when($reason === TaskFailureReason::CustomerUnavailable->value, fn (Builder $absent) => $absent
                            ->whereHas('order', fn (Builder $order) => $order->whereNull(
                                $type->onPickupWindow() ? 'pickup_slot_id' : 'delivery_slot_id'
                            ))));
                }
            });
    }

    /**
     * The collection from a laundry that has not finished yet — not offered to
     * a driver, not assignable, not started (item 18). The laundry's
     * «خلص الغسيل» offers it (`Order/Services/FinishedWashing`).
     */
    public function awaitsWashing(): bool
    {
        return $this->type === TaskType::CollectFromLaundry && ! $this->orderAllows();
    }

    /**
     * The legs that may be given to a driver now: every leg but a collection
     * whose laundry has not finished — `awaitsWashing()` as a query, for the
     * dispatch board, its badge, the sweep and the stuck-task alert.
     */
    public function scopeOfferable(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('order_tasks.type', '!=', TaskType::CollectFromLaundry->value)
            ->orWhereHas('order', fn (Builder $order) => $order
                ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, OrderStatus::collectable()))));
    }

    /**
     * Whether this leg is held for want of a laundry.
     *
     * Only the two legs that take the pieces *to* a laundry: with none set they
     * have nowhere to go. A driver assigned by hand to an unassigned order took
     * its pieces through the whole cycle to `completed` with nobody to count
     * them or be paid — order 85 on live, 2026-11-01, its settlement stuck
     * pending. Held at the start only, never at the finish: a driver already
     * holding somebody's clothes must always be able to hand them over.
     * `OrderService::assignLaundry()` releases it, in custody too.
     */
    public function needsLaundry(): bool
    {
        return $this->order !== null
            && $this->order->laundry_id === null
            && in_array($this->type, [TaskType::PickupFromCustomer, TaskType::DeliverToLaundry], true);
    }

    /**
     * Why this leg cannot start yet — the refusal's key — or null.
     */
    public function blockedReason(): ?string
    {
        if ($this->needsLaundry()) {
            return 'order_has_no_laundry';
        }

        // A collection already started at `cleaning` before «خلص الغسيل»
        // existed finishes (`TaskService::complete()`), so it is not shown blocked.
        return $this->orderAllows(underWay: $this->status === TaskStatus::Started) ? null : 'order_not_ready';
    }

    /**
     * Whether a driver may act on this now.
     */
    public function isActionable(): bool
    {
        return $this->status->isOpen() && $this->predecessorComplete() && $this->blockedReason() === null;
    }

    /**
     * Whether this task has run out of second chances.
     */
    public function isExhausted(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function signatureUrl(): ?string
    {
        return $this->signature_path ? getImageassetUrl($this->signature_path) : null;
    }
}
