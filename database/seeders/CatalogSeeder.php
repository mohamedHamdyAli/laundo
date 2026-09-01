<?php

namespace Database\Seeders;

use App\Modules\Item\Models\Item;
use App\Modules\ItemCategory\Models\ItemCategory;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Service\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue exactly as the Figma design specifies it.
 *
 * Services and their turnaround come from the order wizard's service step;
 * categories, items and prices come from the "الاسعار" screen. Household
 * textiles is quoted rather than per-item, matching "حسب النوع والحجم".
 *
 * Idempotent, and the matching deserves a note: rows are looked up with a JSON
 * path (`name->en`), never by comparing the whole `name` blob. MySQL normalizes
 * what it stores in a JSON column — it sorts the keys and inserts a space after
 * each colon — so `where('name', json_encode([...]))` can never match its own
 * output, and updateOrCreate silently duplicates every run.
 *
 *     php artisan db:seed --class=CatalogSeeder
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $services = $this->seedServices();
            $items = $this->seedItems();
            $this->seedPrices($services, $items);
        });

        $this->command?->info('Catalog seeded: '.Service::count().' services, '
            .ItemCategory::count().' categories, '.Item::count().' items, '
            .ItemPrice::count().' prices.');
    }

    /**
     * @return array<string, Service>
     */
    private function seedServices(): array
    {
        // key => [en, ar, min, max, unit, pricing_mode, description_ar]
        $rows = [
            'wash_iron' => ['Wash & Iron', 'غسيل وكي', 24, 48, 'hour', 'per_item',
                'عناية متكاملة لملابسك، غسيل دقيق وكي احترافي.'],
            'iron_only' => ['Iron Only', 'كي فقط', 24, 24, 'hour', 'per_item',
                'ملابس جاهزة ومكوية بعناية فائقة للحفاظ على جودتها.'],
            'dry_clean' => ['Dry Cleaning', 'تنظيف جاف', 48, 72, 'hour', 'per_item',
                'للأقمشة الحساسة والملابس الرسمية بأعلى معايير الجودة.'],
            // "حسب النوع والحجم" in the design — no per-piece price exists.
            'household' => ['Household Textiles', 'غسيل المفروشات', 2, 4, 'day', 'quote',
                'تنظيف عميق للبطاطين واللحاف والوسائد بأفضل جودة.'],
        ];

        $out = [];
        $order = 1;

        foreach ($rows as $key => [$en, $ar, $min, $max, $unit, $mode, $descAr]) {
            $service = Service::where('name->en', $en)->first() ?? new Service;

            $service->fill([
                'name' => json_encode(['en' => $en, 'ar' => $ar], JSON_UNESCAPED_UNICODE),
                'description' => json_encode(['en' => '', 'ar' => $descAr], JSON_UNESCAPED_UNICODE),
                'pricing_mode' => $mode,
                'duration_min' => $min,
                'duration_max' => $max,
                'duration_unit' => $unit,
                'sort_order' => $order++,
                'status' => 'active',
            ])->save();

            $out[$key] = $service;
        }

        return $out;
    }

    /**
     * @return array<string, Item>
     */
    private function seedItems(): array
    {
        // category key => [en, ar, [item key => [en, ar]]]
        $tree = [
            'shirts' => ['Shirts', 'القمصان', [
                'shirt_hanger' => ['Shirt on hanger', 'قميص على شماعة'],
                'shirt_folded' => ['Shirt folded', 'قميص مطوي'],
                'shirt_linen' => ['Linen shirt', 'قميص كتان'],
                'shirt_silk' => ['Silk shirt', 'قميص حرير'],
            ]],
            'tshirts' => ['T-Shirts', 'التيشيرتات', [
                'tshirt_hanger' => ['T-shirt on hanger', 'تيشيرت على شماعة'],
                'tshirt_folded' => ['T-shirt folded', 'تيشيرت مطوي'],
            ]],
            'bottoms' => ['Bottoms', 'الملابس السفلية', [
                'trousers' => ['Trousers', 'بنطلون'],
            ]],
            'suits' => ['Suits', 'البدل', [
                'suit' => ['Suit', 'بدلة'],
                'jacket' => ['Jacket', 'جاكيت'],
            ]],
            'dresses' => ['Dresses', 'الفساتين', [
                'dress' => ['Dress', 'فستان'],
            ]],
        ];

        $items = [];
        $catOrder = 1;

        foreach ($tree as [$catEn, $catAr, $children]) {
            $category = ItemCategory::where('name->en', $catEn)->first() ?? new ItemCategory;

            $category->fill([
                'name' => json_encode(['en' => $catEn, 'ar' => $catAr], JSON_UNESCAPED_UNICODE),
                'sort_order' => $catOrder++,
                'status' => 'active',
            ])->save();

            $itemOrder = 1;

            foreach ($children as $key => [$en, $ar]) {
                $item = Item::where('name->en', $en)->first() ?? new Item;

                $item->fill([
                    'item_category_id' => $category->id,
                    'name' => json_encode(['en' => $en, 'ar' => $ar], JSON_UNESCAPED_UNICODE),
                    'sort_order' => $itemOrder++,
                    'status' => 'active',
                ])->save();

                $items[$key] = $item;
            }
        }

        return $items;
    }

    /**
     * The price matrix. Values marked (design) are read straight off the Figma
     * "الاسعار" screen; the rest follow its per-service ratios so the grid is
     * usable end to end rather than half empty.
     *
     * @param  array<string, Service>  $services
     * @param  array<string, Item>  $items
     */
    private function seedPrices(array $services, array $items): void
    {
        //                    wash_iron  iron_only  dry_clean
        $matrix = [
            'shirt_hanger' => [17,        12,        45],   // 17 (design)
            'shirt_folded' => [19,        12,        45],   // 19 (design)
            'shirt_linen' => [24,        14,        55],   // 24 (design)
            'shirt_silk' => [30,        18,        70],   // 30 (design)
            'tshirt_hanger' => [18,        12,        40],   // 18 (design)
            'tshirt_folded' => [20,        12,        40],   // 20 (design)
            'trousers' => [35,        20,        60],   // 35 (design)
            'suit' => [95,        45,       120],   // 95 (design)
            'jacket' => [60,        30,        85],   // 60 (design)
            'dress' => [45,        25,        75],
        ];

        $columns = ['wash_iron', 'iron_only', 'dry_clean'];

        foreach ($matrix as $itemKey => $prices) {
            if (! isset($items[$itemKey])) {
                continue;
            }

            foreach ($columns as $i => $serviceKey) {
                if (! isset($services[$serviceKey], $prices[$i])) {
                    continue;
                }

                ItemPrice::updateOrCreate(
                    ['item_id' => $items[$itemKey]->id, 'service_id' => $services[$serviceKey]->id],
                    ['price' => $prices[$i]]
                );
            }
        }

        // `household` is intentionally absent: it is quoted, not priced per piece.
    }
}
