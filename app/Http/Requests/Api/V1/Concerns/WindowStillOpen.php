<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\TimeSlot\Services\SlotClock;
use Illuminate\Validation\Validator;

/**
 * Today's window can be booked only while there is time to send somebody.
 *
 * `after_or_equal:today` refused yesterday and nothing else: at two in the
 * afternoon an order was taken for this morning's 08:00–10:00, its driver
 * «late» the moment it was placed (the owner, 2026-09-30). A window closes
 * `Slot_Booking_Cutoff_Minutes` (an hour by default) before it ends, on the
 * business's clock — `SlotClock`, the same rule `GET /time-slots` marks as
 * `closed`.
 */
trait WindowStillOpen
{
    protected function refuseAClosedWindow(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $clock = app(SlotClock::class);

            foreach (['pickup' => __('This pickup window has ended or is about to. Please choose a later one.'),
                'delivery' => __('This delivery window has ended or is about to. Please choose a later one.')] as $end => $message) {
                $dateField = $end.'_date';
                $slotField = $end.'_slot_id';

                if ($validator->errors()->hasAny([$dateField, $slotField])) {
                    continue;
                }

                if (blank($this->input($dateField)) || ! $this->filled($slotField) || ! is_scalar($this->input($slotField))) {
                    continue;
                }

                $slot = TimeSlot::find($this->input($slotField));

                if ($slot && $clock->isClosed($this->input($dateField), $slot)) {
                    $validator->errors()->add($slotField, $message);
                }
            }
        });
    }
}
