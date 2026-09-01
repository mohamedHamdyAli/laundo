<?php

namespace App\Modules\Order\Models;

use App\Modules\Address\Models\Address;
use App\Modules\Service\Models\Service;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A saved repeat schedule.
 *
 * It never creates an order by itself — on the due day it asks, and the answer
 * decides. See RecurrenceService.
 *
 * @property int $id
 * @property int $user_id
 * @property int $service_id
 * @property int $pickup_address_id
 * @property int|null $time_slot_id
 * @property string $frequency
 * @property int|null $day_of_week
 * @property array<int, array{item_id: int, qty: int}> $items
 * @property Carbon|null $next_prompt_on
 * @property string $status
 * @property-read Service|null $service
 * @property-read TimeSlot|null $timeSlot
 *
 * @method static Builder<static>|OrderRecurrence due(?Carbon $on = null)
 */
class OrderRecurrence extends Model
{
    // Not for a form — for PermissionGenerator, which walks config/dashboard.php
    // and only emits permissions for models carrying this trait. Without it the
    // dashboard routes would be gated on permissions that do not exist, which is
    // a 403 nobody can grant their way out of.
    use DashboardModel;

    protected $fillable = [
        'user_id', 'service_id', 'pickup_address_id', 'time_slot_id',
        'frequency', 'day_of_week', 'items', 'next_prompt_on', 'status',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'next_prompt_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function pickupAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'pickup_address_id');
    }

    /**
     * @return BelongsTo<TimeSlot, $this>
     */
    public function timeSlot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class, 'time_slot_id');
    }

    /**
     * @return HasMany<RecurrencePrompt, $this>
     */
    public function prompts(): HasMany
    {
        return $this->hasMany(RecurrencePrompt::class, 'recurrence_id')->latest('prompted_for');
    }

    public function scopeDue(Builder $query, ?Carbon $on = null): Builder
    {
        return $query->where('status', 'active')
            ->whereNotNull('next_prompt_on')
            ->whereDate('next_prompt_on', '<=', ($on ?? now())->toDateString());
    }

    /**
     * When to ask again after this cycle.
     *
     * Advanced from the cycle date rather than from "now", so a scheduler that
     * runs late does not drag the whole schedule later with it.
     */
    public function advanceFrom(Carbon $from): Carbon
    {
        return match ($this->frequency) {
            'weekly' => $from->copy()->addWeek(),
            'biweekly' => $from->copy()->addWeeks(2),
            'monthly' => $from->copy()->addMonthNoOverflow(),
            default => $from->copy()->addWeek(),
        };
    }
}
