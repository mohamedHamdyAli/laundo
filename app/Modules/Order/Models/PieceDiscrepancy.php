<?php

namespace App\Modules\Order\Models;

use App\Modules\Order\Enums\PieceCheckStep;
use App\Modules\Order\Enums\PieceCountSource;
use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Two counts of the same pieces that disagreed — see PieceCheck.
 *
 * At a handover (a driver counting differently from the handover before) or
 * at the laundry's review (the laundry counting differently from what it was
 * handed). Open until somebody at the platform has looked into it, said what
 * they found and how many pieces there really are.
 *
 * **Not tenant-scoped, like `OrderTask`**: it carries `order_id` and no
 * `laundry_id`, so it is only ever reached through the scoped `Order` —
 * `Order::withOpenPieceCheck()`, `$order->pieceDiscrepancies`, or a lookup
 * rooted in `Order::query()`.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $order_task_id
 * @property PieceCheckStep $step
 * @property int $counted
 * @property int $expected
 * @property PieceCountSource $expected_source
 * @property int|null $counted_by
 * @property bool $open
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $note
 * @property int|null $confirmed_count
 *
 * @method static Builder<static>|PieceDiscrepancy open()
 */
class PieceDiscrepancy extends Model
{
    protected $fillable = [
        'order_id', 'order_task_id', 'step', 'counted', 'expected', 'expected_source',
        'counted_by', 'open', 'resolved_at', 'resolved_by', 'note', 'confirmed_count',
    ];

    protected function casts(): array
    {
        return [
            'step' => PieceCheckStep::class,
            'expected_source' => PieceCountSource::class,
            'counted' => 'integer',
            'expected' => 'integer',
            'confirmed_count' => 'integer',
            'open' => 'boolean',
            'resolved_at' => 'datetime',
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
     * @return BelongsTo<OrderTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(OrderTask::class, 'order_task_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Nobody at the platform has looked at it yet.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return self::constrainOpen($query);
    }

    /**
     * The body of `open()`, callable on a relation's builder — inside
     * `withExists()` the builder is the un-generic one and the scope is not
     * visible to phpstan. One definition, so the list's row marker and the
     * filter behind the badge cannot disagree.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function constrainOpen(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('open'), true);
    }

    public function isReview(): bool
    {
        return $this->step === PieceCheckStep::LaundryReview;
    }
}
