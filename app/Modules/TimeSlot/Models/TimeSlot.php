<?php

namespace App\Modules\TimeSlot\Models;

use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable pickup / delivery window.
 *
 * @property int $id
 * @property string $start_time
 * @property string $end_time
 * @property string $applies_to
 * @property int|null $capacity
 * @property int $sort_order
 * @property string $status
 */
class TimeSlot extends Model
{
    use DashboardModel;

    protected $fillable = [
        'start_time',
        'end_time',
        'applies_to',
        'capacity',
        'sort_order',
        'status',
    ];

    /**
     * The window as the apps render it: "02:00 PM – 05:00 PM".
     *
     * Formatted from the raw column rather than a Carbon cast, because a `time`
     * column carries no date and casting it would invent one — which then drifts
     * with the application timezone.
     */
    public function label(): string
    {
        return $this->formatTime($this->start_time).' – '.$this->formatTime($this->end_time);
    }

    /**
     * True when this window can be used for the given purpose.
     */
    public function appliesTo(string $type): bool
    {
        return $this->applies_to === 'both' || $this->applies_to === $type;
    }

    private function formatTime(?string $time): string
    {
        if (! $time) {
            return '';
        }

        // Stored as HH:MM:SS; only hours and minutes are meaningful to a customer.
        [$h, $m] = array_pad(explode(':', $time), 2, '00');
        $hour = (int) $h;
        $suffix = $hour < 12 ? 'AM' : 'PM';
        $display = $hour % 12 === 0 ? 12 : $hour % 12;

        return sprintf('%02d:%s %s', $display, $m, $suffix);
    }
}
