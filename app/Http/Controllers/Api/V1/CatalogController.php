<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Pricing\Services\pricingService;
use App\Modules\Service\Repositories\ServiceRepository;
use Illuminate\Http\JsonResponse;

/**
 * The catalogue the apps read before any account exists.
 *
 * Guest mode browses services and prices, so both endpoints are public. Text is
 * localized from the `lang` header by ApiLocale + getLocalizedValue().
 */
class CatalogController extends Controller
{
    public function __construct(
        private readonly ServiceRepository $serviceRepository,
        private readonly pricingService $pricingService,
    ) {}

    /**
     * Wizard step 2: the service list with turnaround and how it is priced.
     */
    public function services(): JsonResponse
    {
        $services = $this->serviceRepository->allActive()->map(fn ($service) => [
            'id' => $service->id,
            'name' => getLocalizedValue($service, 'name'),
            'description' => $service->description ? getLocalizedValue($service, 'description') : null,
            'image' => getImageassetUrl($service->image),
            'pricing_mode' => $service->pricing_mode,
            'duration' => $service->durationLabel(),
            'duration_unit' => $service->duration_unit,
        ])->values();

        return successReturnData($services);
    }

    /**
     * The full price list behind the app's "الاسعار" screen:
     * service -> category -> item -> price.
     */
    public function catalog(): JsonResponse
    {
        return successReturnData($this->pricingService->publicCatalog());
    }
}
