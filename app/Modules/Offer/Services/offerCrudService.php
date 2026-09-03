<?php

namespace App\Modules\Offer\Services;

use App\Modules\Coupon\Models\Coupon;
use App\Modules\Offer\Enums\OfferTarget;
use App\Modules\Offer\Repositories\OfferRepository;
use App\Modules\Service\Models\Service;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

/**
 * Business rules for «عروض متميزة».
 */
class offerCrudService
{
    protected $offerRepository;

    protected $responseService;

    public function __construct(OfferRepository $offerRepository, ResponseService $responseService)
    {
        $this->offerRepository = $offerRepository;
        $this->responseService = $responseService;
    }

    public function getAllOffers()
    {
        return $this->offerRepository->getAll();
    }

    public function searchOffers($query)
    {
        return $this->offerRepository->search($query);
    }

    /**
     * The universal view-data assembler: the list under its plural key, and —
     * when an id is given — the single record under `row`.
     *
     * @return array<string, mixed>
     */
    public function shredData($id = null)
    {
        $data = [
            'targetTypes' => OfferTarget::cases(),
            // Active only, both of them: an offer pointing at a disabled
            // service or a switched-off code is a card that goes nowhere.
            'services' => Service::where('status', 'active')->get(['id', 'name']),
            'coupons' => Coupon::where('status', 'active')->get(['id', 'code', 'type', 'value']),
        ];

        if ($id) {
            $data['row'] = $this->offerRepository->find($id);
        }

        return $data;
    }

    public function addOffer(array $data)
    {
        return DB::transaction(function () use ($data) {
            $data = $this->normalise($data);
            $data['image'] = uploadOrUpdateImage($data['image'] ?? null, 'images/offers/image');
            $data['title'] = json_encode($data['title'], JSON_UNESCAPED_UNICODE);
            $data['description'] = json_encode($data['description'], JSON_UNESCAPED_UNICODE);

            return $this->offerRepository->create($data);
        });
    }

    public function updateOffer(array $data)
    {
        $data = $this->normalise($data);

        // The filter below drops nulls, so `normalise` clears a target with an
        // empty string instead. Without that, switching an offer back to «no
        // action» would leave the old service id behind in the column.
        $filtered = array_filter($data, fn ($v) => ! is_null($v));

        return DB::transaction(function () use ($filtered) {
            $existing = $this->offerRepository->find($filtered['id']);

            if (isset($filtered['image'])) {
                $filtered['image'] = uploadOrUpdateImage(
                    $filtered['image'],
                    'images/offers/image',
                    $existing->image
                );
            }

            $filtered['title'] = json_encode($filtered['title'], JSON_UNESCAPED_UNICODE);
            $filtered['description'] = json_encode($filtered['description'], JSON_UNESCAPED_UNICODE);

            return $this->offerRepository->update($filtered['id'], $filtered);
        });
    }

    public function deleteOffer($id)
    {
        return DB::transaction(fn () => $this->offerRepository->delete($id));
    }

    public function toggleStatus($id, $status)
    {
        $offer = $this->offerRepository->find($id);

        return $this->responseService->toggleStatus($offer, $status);
    }

    /**
     * Keep the target kind and its value telling the same story, and keep the
     * coupon link consistent with the kind.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        $type = OfferTarget::tryFrom((string) ($data['target_type'] ?? '')) ?? OfferTarget::None;

        $data['target_type'] = $type->value;
        // Empty string, not null: the update path filters nulls out.
        $data['target_value'] = $type->needsValue() ? ($data['target_value'] ?? '') : '';

        return $data;
    }
}
