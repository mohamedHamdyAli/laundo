<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Order\Services\Turnaround;
use App\Modules\Pricing\Services\pricingService;
use App\Modules\Service\Repositories\ServiceRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            // How soon after the pickup the pieces can come back — the middle of
            // the range above, in the service's own unit: `{value: 3, unit: day}`
            // is «from the third day after the pickup day, any window», and
            // `{value: 36, unit: hour}` is «36 hours after the pickup window
            // ends». Null when the service has no turnaround. The order is
            // refused on the same rule; see Turnaround.
            'delivery_after' => app(Turnaround::class)->after($service),
        ])->values();

        return successReturnData($services);
    }

    /**
     * The price list behind the app's «الاسعار» screen:
     * service -> category -> item -> price.
     *
     * Unfiltered it is the whole grid, which is what that screen wants — the
     * customer is comparing services and a call per service would be a call per
     * tap. `service_id` and `category_id` narrow it for the order wizard, which
     * already knows the service and needs one column of the grid rather than all
     * of it.
     */
    public function catalog(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'category_id' => ['nullable', 'integer', 'exists:item_categories,id'],
        ]);

        return successReturnData($this->pricingService->publicCatalog(
            isset($data['service_id']) ? (int) $data['service_id'] : null,
            isset($data['category_id']) ? (int) $data['category_id'] : null,
        ));
    }
}
