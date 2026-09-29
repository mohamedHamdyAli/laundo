<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Modules\Order\Services\Turnaround;
use App\Modules\Service\Models\Service;
use App\Modules\TimeSlot\Models\TimeSlot;
use Illuminate\Validation\Validator;

/**
 * The delivery leaves the service its time, and is not past the booking window.
 *
 * A four-day wash booked to be collected and returned on the same afternoon was
 * accepted for as long as the platform ran: the only rule on the dates was
 * «delivery not before pickup». Turnaround decides the earliest the pieces can
 * come back; this refuses anything sooner, saying which day that is so the
 * customer can pick again rather than guess.
 *
 * Only when both dates are sent — an order with no delivery date yet is
 * scheduled later, and checked then.
 */
trait DeliveryAfterTurnaround
{
    protected function refuseAnEarlyDelivery(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['service_id', 'pickup_date', 'delivery_date', 'pickup_slot_id', 'delivery_slot_id'])) {
                return;
            }

            if (blank($this->input('pickup_date')) || blank($this->input('delivery_date'))) {
                return;
            }

            // One id each, or nothing to check: an array slipped past `exists`
            // would make find() hand back a collection.
            foreach (['service_id', 'pickup_slot_id', 'delivery_slot_id'] as $field) {
                if ($this->filled($field) && ! is_scalar($this->input($field))) {
                    return;
                }
            }

            $service = Service::find($this->input('service_id'));

            if (! $service) {
                return;
            }

            $turnaround = app(Turnaround::class);
            $pickupSlot = $this->filled('pickup_slot_id') ? TimeSlot::find($this->input('pickup_slot_id')) : null;
            $deliverySlot = $this->filled('delivery_slot_id') ? TimeSlot::find($this->input('delivery_slot_id')) : null;

            $problem = $turnaround->problem($service, $this->input('pickup_date'), $pickupSlot, $this->input('delivery_date'), $deliverySlot);

            if ($problem === null) {
                return;
            }

            // Too soon for the service, or past the booking window — the same
            // words `GET /delivery-window` gives the app's bottom sheet.
            $validator->errors()->add('delivery_date', $turnaround->message($service, $problem, $this->input('pickup_date'), $pickupSlot));
        });
    }
}
