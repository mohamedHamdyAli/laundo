<?php

namespace App\Modules\Pricing\Services;

use App\Modules\ItemCategory\Repositories\ItemCategoryRepository;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Service\Repositories\ServiceRepository;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the price matrix.
 *
 * The grid is (items x per-item services). Quoted services are excluded from the
 * columns entirely — they have no per-piece price by definition.
 */
class pricingService
{
    protected $serviceRepository;

    protected $itemCategoryRepository;

    public function __construct(
        ServiceRepository $serviceRepository,
        ItemCategoryRepository $itemCategoryRepository
    ) {
        $this->serviceRepository = $serviceRepository;
        $this->itemCategoryRepository = $itemCategoryRepository;
    }

    /**
     * Everything the grid needs: the column set, the row set grouped by category,
     * and the existing prices keyed "{item_id}-{service_id}" for O(1) lookup in
     * the view.
     *
     * @return array<string, mixed>
     */
    public function shredData()
    {
        $services = $this->serviceRepository->activePerItem();
        $categories = $this->itemCategoryRepository->activeWithItems();

        $prices = [];

        foreach (ItemPrice::all() as $price) {
            $prices["{$price->item_id}-{$price->service_id}"] = $price->price;
        }

        return [
            'services' => $services,
            'itemCategories' => $categories,
            'prices' => $prices,
            'quotedServices' => $this->serviceRepository->allActive()
                ->where('pricing_mode', 'quote'),
        ];
    }

    /**
     * Saves the whole grid in one transaction.
     *
     * A blank cell means "this service is not priced for this item", which is a
     * meaningful state, so a blank deletes the row rather than storing zero — a
     * stored 0.00 would advertise the service as free.
     *
     * Shaped [item_id => [service_id => price]], but typed loosely on purpose:
     * this is request input, and `prices[5]=abc` survives the `prices.*.*` rule
     * as a scalar. The is_array() guard below is what makes that harmless.
     *
     * @param  array<int, mixed>  $grid
     */
    public function saveGrid(array $grid): int
    {
        // Only per-item services may carry prices; anything else in the payload is
        // ignored rather than trusted.
        $allowedServices = $this->serviceRepository->activePerItem()->pluck('id')->all();

        $touched = 0;

        DB::transaction(function () use ($grid, $allowedServices, &$touched) {
            foreach ($grid as $itemId => $cells) {
                if (! is_array($cells)) {
                    continue;
                }

                foreach ($cells as $serviceId => $value) {
                    if (! in_array((int) $serviceId, $allowedServices, true)) {
                        continue;
                    }

                    $blank = $value === null || $value === '';

                    if ($blank) {
                        ItemPrice::where('item_id', $itemId)->where('service_id', $serviceId)->delete();

                        continue;
                    }

                    ItemPrice::updateOrCreate(
                        ['item_id' => (int) $itemId, 'service_id' => (int) $serviceId],
                        ['price' => round((float) $value, 2)]
                    );

                    $touched++;
                }
            }
        });

        return $touched;
    }

    /**
     * The price list the customer app's "الاسعار" screen renders:
     * service -> category -> item -> price.
     *
     * Unfiltered it is the whole grid, which is what the «الاسعار» tab wants: the
     * customer is comparing services against each other and a call per service
     * would be a call per tap. The filters exist for the other caller — the order
     * wizard, which has already been told the service and needs one column of it.
     *
     * A filter that matches nothing returns an empty array rather than everything.
     * Silently widening a filter is how an app ends up showing a dry-cleaning
     * price on a wash-and-iron screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function publicCatalog(?int $serviceId = null, ?int $categoryId = null): array
    {
        $services = $this->serviceRepository->allActive()
            ->when($serviceId !== null, fn ($c) => $c->where('id', $serviceId));
        $categories = $this->itemCategoryRepository->activeWithItems()
            ->when($categoryId !== null, fn ($c) => $c->where('id', $categoryId));

        $prices = [];
        foreach (ItemPrice::all() as $price) {
            $prices["{$price->item_id}-{$price->service_id}"] = $price->price;
        }

        $out = [];

        foreach ($services as $service) {
            $entry = [
                'id' => $service->id,
                'name' => getLocalizedValue($service, 'name'),
                'pricing_mode' => $service->pricing_mode,
                'duration' => $service->durationLabel(),
                'duration_unit' => $service->duration_unit,
                'categories' => [],
            ];

            // A quoted service has no per-piece prices to list.
            if ($service->isPerItem()) {
                foreach ($categories as $category) {
                    $items = [];

                    foreach ($category->items as $item) {
                        $key = "{$item->id}-{$service->id}";

                        if (! isset($prices[$key])) {
                            continue;
                        }

                        $items[] = [
                            'id' => $item->id,
                            'name' => getLocalizedValue($item, 'name'),
                            'price' => $prices[$key],
                        ];
                    }

                    // Skip categories this service prices nothing in.
                    if ($items === []) {
                        continue;
                    }

                    $entry['categories'][] = [
                        'id' => $category->id,
                        'name' => getLocalizedValue($category, 'name'),
                        'items' => $items,
                    ];
                }
            }

            $out[] = $entry;
        }

        return $out;
    }
}
