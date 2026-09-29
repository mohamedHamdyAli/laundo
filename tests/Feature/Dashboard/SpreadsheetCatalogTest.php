<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Country\Models\Country;
use App\Modules\Item\Models\Item;
use App\Modules\ItemCategory\Models\ItemCategory;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Service\Models\Service;
use App\Modules\Zone\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The catalogue and location sheets: countries, zones, services, item
 * categories, items and the price grid.
 *
 * Each one: the export is the header row plus one row per record the screen
 * shows, and an import adds a row, changes a row — leaving what its blank
 * cells did not mention alone — and refuses a bad row by its sheet row number.
 */
class SpreadsheetCatalogTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->actingAs($this->superAdmin());
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows  the first row is the headers
     */
    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return new UploadedFile($path, 'sheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function export(string $sheet): array
    {
        $response = $this->get(route('admin.spreadsheet.export', $sheet))->assertOk();

        $reader = new Reader;
        $reader->open($response->getFile()->getPathname());
        $rows = [];

        foreach ($reader->getSheetIterator() as $worksheet) {
            foreach ($worksheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }

            break;
        }

        $reader->close();

        return $rows;
    }

    /**
     * Import, and assert the one-added, one-changed, row-4-refused shape every
     * test here uses.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function importOneOfEach(string $sheet, array $rows): void
    {
        $this->post(route('admin.spreadsheet.import', $sheet), ['file' => $this->xlsx($rows)])
            ->assertRedirect()
            ->assertSessionHas('spreadsheet_report');

        $report = session('spreadsheet_report');

        $this->assertSame(1, $report['created'], json_encode($report));
        $this->assertSame(1, $report['updated'], json_encode($report));
        $this->assertSame([4], array_column($report['failed'], 'row'), json_encode($report));
        $this->assertNotEmpty($report['failed'][0]['messages']);
    }

    /**
     * @param  class-string  $model
     */
    private function named(string $model, string $en): mixed
    {
        return $model::all()->first(fn ($row) => ($row->name->en ?? null) === $en);
    }

    #[Test]
    public function every_catalogue_screen_carries_its_excel_buttons(): void
    {
        $screens = [
            'country' => 'admin.country.index',
            'zone' => 'admin.zone.index',
            'service' => 'admin.service.index',
            'item_category' => 'admin.item_category.index',
            'item' => 'admin.item.index',
            'item_price' => 'admin.pricing.index',
        ];

        foreach ($screens as $sheet => $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee(route('admin.spreadsheet.export', $sheet), false)
                ->assertSee(route('admin.spreadsheet.import', $sheet), false);
        }

        // Outside the grid's bulk form: a form nested in it would break its save.
        $page = $this->get(route('admin.pricing.index'))->getContent();
        $this->assertLessThan(
            strpos($page, route('admin.pricing.update')),
            strpos($page, route('admin.spreadsheet.import', 'item_price'))
        );
    }

    #[Test]
    public function countries_export_and_import(): void
    {
        $egypt = $this->geo['country'];

        $rows = $this->export('country');
        $this->assertSame(['id', 'name_en', 'name_ar', 'code', 'phone_code', 'timezone', 'status'], $rows[0]);
        $this->assertCount(Country::count() + 1, $rows);

        // A hand-set timezone the code would not derive: a blank cell must keep it.
        $egypt->update(['timezone' => 'UTC']);

        $this->importOneOfEach('country', [
            $rows[0],
            // Excel turns a typed +966 into a number; the row still imports.
            [null, 'Saudi Arabia', 'السعودية', 'SA', 966, null, 'active'],
            // Its own code, unchanged — the unique rule must ignore this row.
            [$egypt->id, 'Egypt Renamed', null, 'EG', null, null, null],
            // A new country needs a code.
            [null, 'Nowhere', null, null, null, null, 'active'],
        ]);

        $saudi = $this->named(Country::class, 'Saudi Arabia');
        $this->assertSame('السعودية', $saudi->name->ar);
        $this->assertSame('966', $saudi->phone_code);
        $this->assertSame('Asia/Riyadh', $saudi->timezone);

        $egypt = $egypt->fresh();
        $this->assertSame('Egypt Renamed', $egypt->name->en);
        $this->assertSame('مصر', $egypt->name->ar);
        $this->assertSame('+20', $egypt->phone_code);
        $this->assertSame('UTC', $egypt->timezone);
    }

    #[Test]
    public function a_country_keeps_its_own_code_when_edited_from_the_panel(): void
    {
        $egypt = $this->geo['country'];

        $this->put(route('admin.country.update', $egypt->id), [
            'name' => ['en' => 'Egypt', 'ar' => 'مصر'], 'code' => 'EG', 'status' => 'active',
        ])->assertRedirect(route('admin.country.index'))->assertSessionHasNoErrors();
    }

    #[Test]
    public function zones_export_and_import(): void
    {
        [$nasr] = $this->geo['zones'];
        $cityId = $this->geo['city']->id;

        $rows = $this->export('zone');
        $this->assertSame(['id', 'name_en', 'name_ar', 'city_id', 'city', 'price_per_km', 'min_delivery_fee', 'sort_order', 'status', 'drawn'], $rows[0]);
        $this->assertCount(Zone::count() + 1, $rows);
        $this->assertContains('Cairo', array_column($rows, 4));

        $this->importOneOfEach('zone', [
            $rows[0],
            [null, 'Zamalek', 'الزمالك', $cityId, 'ignored', 2.5, 10, 3, 'active'],
            [$nasr->id, null, null, null, null, 4, null, null, 'inactive'],
            // No such city.
            [null, 'Atlantis', null, 999999, null, null, null, null, 'active'],
        ]);

        $zamalek = $this->named(Zone::class, 'Zamalek');
        $this->assertSame($cityId, $zamalek->city_id);
        $this->assertSame('2.50', $zamalek->price_per_km);

        $nasr = $nasr->fresh();
        $this->assertSame('4.00', $nasr->price_per_km);
        $this->assertSame('inactive', $nasr->status);
        $this->assertSame('Nasr City', $nasr->name->en);
        $this->assertSame('مدينة نصر', $nasr->name->ar);
        $this->assertSame($cityId, $nasr->city_id);
    }

    #[Test]
    public function services_export_and_import(): void
    {
        $washIron = $this->catalog['service'];
        $washIron->update(['duration_min' => 24, 'duration_max' => 48, 'duration_unit' => 'hour']);

        $rows = $this->export('service');
        $this->assertSame([
            'id', 'name_en', 'name_ar', 'description_en', 'description_ar', 'pricing_mode',
            'duration_min', 'duration_max', 'duration_unit', 'sort_order', 'status',
        ], $rows[0]);
        $this->assertCount(Service::count() + 1, $rows);

        $this->importOneOfEach('service', [
            $rows[0],
            [null, 'Ironing', 'كي', 'Pressed only', null, 'per_item', 12, 24, 'hour', 3, 'active'],
            // One bound only: checked against the stored minimum, and kept.
            [$washIron->id, 'Wash & Press', null, null, null, null, null, 72, null, null, null],
            // Below the stored minimum of 24 — a backwards range.
            [$washIron->id, null, null, null, null, null, null, 12, null, null, null],
        ]);

        $ironing = $this->named(Service::class, 'Ironing');
        $this->assertSame('Pressed only', $ironing->description->en);
        $this->assertSame('per_item', $ironing->pricing_mode);

        $washIron = $washIron->fresh();
        $this->assertSame('Wash & Press', $washIron->name->en);
        $this->assertSame('غسيل وكي', $washIron->name->ar);
        $this->assertSame(24, (int) $washIron->duration_min);
        $this->assertSame(72, (int) $washIron->duration_max);
        // Still per-item: its grid prices survive an edit that did not touch the mode.
        $this->assertSame(2, ItemPrice::where('service_id', $washIron->id)->count());
    }

    #[Test]
    public function the_services_export_follows_the_lists_own_search(): void
    {
        $this->catalog['service']->update(['duration_min' => 24, 'duration_max' => 48, 'duration_unit' => 'hour']);

        // The duration cell as the table renders it — no column holds it.
        $response = $this->get(route('admin.spreadsheet.export', 'service').'?query='.urlencode('24–48 hours'))->assertOk();

        $reader = new Reader;
        $reader->open($response->getFile()->getPathname());
        $ids = [];
        foreach ($reader->getSheetIterator() as $worksheet) {
            foreach ($worksheet->getRowIterator() as $row) {
                $ids[] = $row->toArray()[0];
            }
            break;
        }
        $reader->close();

        $this->assertSame(['id', $this->catalog['service']->id], array_map(fn ($v) => is_float($v) ? (int) $v : $v, $ids));
    }

    #[Test]
    public function item_categories_export_and_import(): void
    {
        $tops = ItemCategory::first();

        $rows = $this->export('item_category');
        $this->assertSame(['id', 'name_en', 'name_ar', 'sort_order', 'status'], $rows[0]);
        $this->assertCount(ItemCategory::count() + 1, $rows);

        $this->importOneOfEach('item_category', [
            $rows[0],
            [null, 'Bottoms', 'سفلي', 2, 'active'],
            [$tops->id, 'Tops Renamed', null, null, null],
            // A new category needs a status.
            [null, 'No Status', null, null, null],
        ]);

        $this->assertSame('سفلي', $this->named(ItemCategory::class, 'Bottoms')->name->ar);

        $tops = $tops->fresh();
        $this->assertSame('Tops Renamed', $tops->name->en);
        $this->assertSame('علوي', $tops->name->ar);
        $this->assertSame('active', $tops->status);
    }

    #[Test]
    public function items_export_and_import(): void
    {
        [$shirt] = $this->catalog['items'];
        $categoryId = $shirt->item_category_id;

        $rows = $this->export('item');
        $this->assertSame(['id', 'name_en', 'name_ar', 'item_category_id', 'item_category', 'sort_order', 'status'], $rows[0]);
        $this->assertCount(Item::count() + 1, $rows);
        $this->assertContains('Tops', array_column($rows, 4));

        $this->importOneOfEach('item', [
            $rows[0],
            [null, 'Jacket', 'جاكيت', $categoryId, 'ignored', 3, 'active'],
            [$shirt->id, null, null, null, null, 5, null],
            // No such category.
            [null, 'Ghost', null, 999999, null, null, 'active'],
        ]);

        $this->assertSame($categoryId, $this->named(Item::class, 'Jacket')->item_category_id);

        $shirt = $shirt->fresh();
        $this->assertSame(5, (int) $shirt->sort_order);
        $this->assertSame('Shirt', $shirt->name->en);
        $this->assertSame('قميص', $shirt->name->ar);
        $this->assertSame($categoryId, $shirt->item_category_id);
    }

    #[Test]
    public function the_price_grid_exports_and_imports(): void
    {
        [$shirt] = $this->catalog['items'];
        $service = $this->catalog['service'];
        $categoryId = $shirt->item_category_id;

        // Priced but not in the grid: an inactive item is not exported.
        $retired = Item::create([
            'item_category_id' => $categoryId, 'status' => 'inactive', 'sort_order' => 9,
            'name' => json_encode(['en' => 'Retired', 'ar' => 'قديم'], JSON_UNESCAPED_UNICODE),
        ]);
        ItemPrice::create(['item_id' => $retired->id, 'service_id' => $service->id, 'price' => 5]);

        $jacket = Item::create([
            'item_category_id' => $categoryId, 'status' => 'active', 'sort_order' => 3,
            'name' => json_encode(['en' => 'Jacket', 'ar' => 'جاكيت'], JSON_UNESCAPED_UNICODE),
        ]);

        $rows = $this->export('item_price');
        $this->assertSame(['id', 'item_id', 'item', 'service_id', 'service', 'price'], $rows[0]);
        $this->assertCount(2 + 1, $rows);
        $this->assertContains('Shirt', array_column($rows, 2));

        $shirtPrice = ItemPrice::where('item_id', $shirt->id)->where('service_id', $service->id)->firstOrFail();

        $this->importOneOfEach('item_price', [
            $rows[0],
            [null, $jacket->id, 'ignored', $service->id, 'ignored', 30.456],
            [$shirtPrice->id, null, null, null, null, 20],
            // A quoted service has no piece prices.
            [null, $shirt->id, null, $this->catalog['quoted']->id, null, 10],
        ]);

        $this->assertSame('30.46', ItemPrice::where('item_id', $jacket->id)->where('service_id', $service->id)->value('price'));

        $shirtPrice = $shirtPrice->fresh();
        $this->assertSame('20.00', $shirtPrice->price);
        $this->assertSame($shirt->id, $shirtPrice->item_id);
        $this->assertSame(0, ItemPrice::where('service_id', $this->catalog['quoted']->id)->count());
    }
}
