<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Modules\Order\Services\PieceLimits;
use App\Modules\Service\Models\Service;
use App\Modules\TimeSlot\Models\TimeSlot;
use Illuminate\Validation\Validator;

/**
 * «التحكم في عدد القطع» (the client's sheet, item 19, 2026-11-08): no more
 * pieces in one order than `Max_Pieces_Per_Order`, and no more in a pickup
 * window than its `max_pieces` has room for — said beside the field, before
 * the order is placed. `OrderService::place()` asks again under the window's
 * lock (`PieceLimits`); this is the answer the customer reads.
 */
trait PiecesWithinLimits
{
    protected function refuseTooManyPieces(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['items', 'items.*.qty'])) {
                return;
            }

            // Pieces the way `place()` counts them: a quoted service has no
            // basket — its pieces are known once the laundry counts — so it is
            // held to neither limit (code review, 2026-11-08).
            $service = is_scalar($this->input('service_id')) ? Service::find($this->input('service_id')) : null;

            if (! $service || ! $service->isPerItem()) {
                return;
            }

            $pieces = collect((array) $this->input('items', []))
                ->sum(fn ($line) => is_array($line) && is_numeric($line['qty'] ?? null) ? (int) $line['qty'] : 0);
            $limits = app(PieceLimits::class);
            $max = $limits->perOrder();

            if ($max !== null && $pieces > $max) {
                $validator->errors()->add('items', __('One order can carry up to :max pieces.', ['max' => $max]));

                return;
            }

            if ($validator->errors()->hasAny(['pickup_date', 'pickup_slot_id'])
                || blank($this->input('pickup_date')) || ! $this->filled('pickup_slot_id') || ! is_scalar($this->input('pickup_slot_id'))) {
                return;
            }

            $slot = TimeSlot::find($this->input('pickup_slot_id'));
            $room = $slot ? $limits->roomInWindow($slot, $this->input('pickup_date')) : null;

            if ($room !== null && $pieces > $room) {
                $validator->errors()->add('pickup_slot_id', $room < 1
                    ? __('This pickup window is fully booked. Please choose another one.')
                    : __('This pickup window has room for :count more pieces only. Please choose another one.', ['count' => $room]));
            }
        });
    }
}
