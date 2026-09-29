<?php

namespace App\Modules\Zone\Requests;

use App\Modules\Zone\Services\ZoneLocator;
use App\Support\Geo\Polygon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class ZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'city_id' => ($isUpdate ? 'nullable' : 'required').'|exists:cities,id',
            'name' => $isUpdate ? 'nullable|array' : 'required|array',
            'name.*' => $isUpdate ? 'nullable|string|max:191' : 'required|string|max:191',
            // The delivery rate. Nullable on purpose: an unpriced zone makes
            // DeliveryFeeCalculator report that the fee is unknown rather than
            // charge the customer nothing.
            'price_per_km' => 'nullable|numeric|min:0|max:9999.99',
            'min_delivery_fee' => 'nullable|numeric|min:0|max:9999.99',
            'sort_order' => 'nullable|integer|min:0',
            'status' => $isUpdate ? 'nullable|in:active,inactive' : 'required|in:active,inactive',
            // The drawing, as the map writes it: JSON, [[lat, lng], …]. Empty
            // is «not drawn» — the zone keeps working the old way.
            'boundary' => 'nullable|string|max:100000',
        ];
    }

    /**
     * The drawing has to be one shape with an inside, and it may not claim
     * ground another zone already has (the owner's rule): a pin in two zones
     * would have two sets of laundries and drivers, and no answer to which.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $raw = $this->input('boundary');

            if ($raw === null || $raw === '' || $validator->errors()->has('boundary')) {
                return;
            }

            $decoded = json_decode((string) $raw, true);
            $polygon = Polygon::fromArray($decoded);

            if (! $polygon) {
                $validator->errors()->add('boundary', Polygon::problem($decoded) === Polygon::TOO_MANY_CORNERS
                    ? __('A zone can have at most :count corners. Draw it with fewer.', ['count' => Polygon::MAX_POINTS])
                    : __('Draw the zone as one closed shape of at least three corners, with no edges crossing.'));

                return;
            }

            $id = $this->route('id');

            try {
                app(ZoneLocator::class)->assertNoOverlap($polygon, is_numeric($id) ? (int) $id : null);
            } catch (ValidationException $e) {
                $validator->errors()->add('boundary', (string) collect($e->errors())->flatten()->first());
            }
        }];
    }
}
