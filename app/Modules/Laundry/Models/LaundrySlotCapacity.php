<?php

namespace App\Modules\Laundry\Models;

use App\Modules\TimeSlot\Models\TimeSlot;
use App\Trait\BelongsToLaundry;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One laundry's intake ceiling for one window.
 *
 * @property int $id
 * @property int $laundry_id
 * @property int $time_slot_id
 * @property int|null $capacity
 */
class LaundrySlotCapacity extends Model
{
    use BelongsToLaundry;
    use DashboardModel;

    protected $table = 'laundry_slot_capacities';

    protected $fillable = ['laundry_id', 'time_slot_id', 'capacity'];

    protected function casts(): array
    {
        return [
            // Cast so «uncapped» stays null instead of arriving as the string
            // "" that an empty form field posts and reading back as 0 — which
            // would close the window rather than open it.
            'capacity' => 'integer',
        ];
    }

    public function timeSlot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class, 'time_slot_id');
    }
}
