<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Coupon\Models\Coupon;
use App\Modules\Driver\Models\Driver;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\User\Models\User;
use App\Support\Spreadsheet\SheetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Excel sheets for the screens that hold people and their terms: discount
 * codes, laundries (with their owner), drivers (with their profile and areas)
 * and customers.
 *
 * What each sheet must get right beyond the engine: **no password ever leaves**
 * — a password column exists only so an account can be given one, and is blank
 * on the way out; a blank cell never blanks a stored value, including the ones
 * a service reads as «unticked» when absent; and the form's paired checks
 * (a percentage's ceiling, an end after a start) still bite on a row that
 * changes only one half of the pair.
 */
class SpreadsheetPeopleTest extends TestCase
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

    // ---------------------------------------------------------------- helpers

    /**
     * A sheet from rows keyed by header; the headers are every key used, in
     * the order first seen, and a missing key is a blank cell.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function xlsx(array $rows): UploadedFile
    {
        $headers = [];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $header) {
                if (! in_array($header, $headers, true)) {
                    $headers[] = $header;
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(fn ($h) => $row[$h] ?? null, $headers)));
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

    /**
     * The export, as rows keyed by header.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<string, mixed>>}
     */
    private function export(string $sheet, User $as): array
    {
        $response = $this->actingAs($as)->get(route('admin.spreadsheet.export', $sheet));
        $response->assertOk();

        $raw = $this->read($response->getFile()->getPathname());
        $headers = array_shift($raw);

        return [
            'headers' => $headers,
            'rows' => array_map(fn (array $row) => array_combine($headers, array_pad($row, count($headers), null)), $raw),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function import(string $sheet, array $rows, User $as): array
    {
        $this->actingAs($as)
            ->post(route('admin.spreadsheet.import', $sheet), ['file' => $this->xlsx($rows)])
            ->assertRedirect()
            ->assertSessionHas('spreadsheet_report');

        return session('spreadsheet_report');
    }

    /**
     * Every cell under a password header is empty, and the stored hash is in
     * no cell at all.
     *
     * @param  array{headers: array<int, string>, rows: array<int, array<string, mixed>>}  $export
     */
    private function assertNoPasswordLeaves(array $export, string $hash): void
    {
        foreach ($export['headers'] as $header) {
            if (str_contains($header, 'password')) {
                $this->assertSame([], array_filter(array_column($export['rows'], $header), fn ($v) => $v !== null && $v !== ''), "{$header} carried a value");
            }
        }

        $cells = array_merge(...array_map('array_values', $export['rows']));
        $this->assertNotContains($hash, $cells, 'a stored password hash was exported');
    }

    private function admin(array $permissions): User
    {
        $this->grant('admin', $permissions);

        return User::create([
            'name' => 'Operator', 'email' => 'operator@test.local', 'phone' => '+201000000077',
            'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);
    }

    #[Test]
    public function each_list_screen_offers_its_sheet(): void
    {
        $this->coupon();
        $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->driverUser();
        $this->customer();

        $admin = $this->superAdmin();

        foreach (['coupon', 'laundry', 'driver', 'user'] as $sheet) {
            $page = $this->actingAs($admin)->get(route("admin.{$sheet}.index"));

            $page->assertOk();
            $page->assertSee(route('admin.spreadsheet.export', $sheet), false);
            // Import is offered only where the sheet imports.
            $sheet === 'user'
                ? $page->assertDontSee(route('admin.spreadsheet.import', $sheet), false)
                : $page->assertSee(route('admin.spreadsheet.import', $sheet), false);
        }
    }

    #[Test]
    public function an_export_imported_back_untouched_is_accepted_row_for_row(): void
    {
        // The ordinary way a sheet is used: export, change a cell or two,
        // import the whole file. Every untouched row has to pass the form it
        // came out of — a stored value the form would refuse (a legacy vehicle
        // type, seconds on a shift, «10.00») would otherwise refuse the row.
        $coupon = $this->coupon();
        $coupon->forceFill(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'max_discount' => 50])->save();
        $this->laundryWithOwner('A', '+201011110001', '+201011110002')['laundry']
            ->update(['lat' => 30.0561, 'lng' => 31.2003, 'city_id' => $this->geo['city']->id, 'address' => 'Nile St']);
        $this->driverUser('+201033330001', zoneIds: [$this->geo['zones'][0]->id]);
        $this->driverUser('+201033330002', available: false);

        $admin = $this->superAdmin();

        foreach (['coupon' => 1, 'laundry' => 1, 'driver' => 2] as $sheet => $count) {
            $response = $this->actingAs($admin)->get(route('admin.spreadsheet.export', $sheet));
            $file = new UploadedFile($response->getFile()->getPathname(), 'export.xlsx', null, null, true);

            $this->actingAs($admin)->post(route('admin.spreadsheet.import', $sheet), ['file' => $file]);
            $report = session('spreadsheet_report');

            $this->assertSame([], $report['failed'], "{$sheet}: ".json_encode($report, JSON_UNESCAPED_UNICODE));
            $this->assertSame($count, $report['updated'], $sheet);
            $this->assertSame(0, $report['created'], $sheet);
        }

        // And nothing moved: the unavailable driver is still unavailable, the
        // coupon still covers delivery.
        $this->assertFalse(Driver::where('phone', '+201033330002')->first()->profile->is_available);
        $this->assertTrue($coupon->fresh()->applies_to_delivery);
        $this->assertEquals(50, $coupon->fresh()->max_discount);
    }

    // ---------------------------------------------------------------- coupons

    private function coupon(): Coupon
    {
        return Coupon::create([
            'code' => 'WELCOME',
            'name' => json_encode(['en' => 'Welcome', 'ar' => 'أهلا'], JSON_UNESCAPED_UNICODE),
            'type' => Coupon::PERCENTAGE, 'value' => 10, 'max_per_user' => 1,
            'applies_to_delivery' => true, 'discount_laundry_share' => 30, 'status' => 'active',
        ]);
    }

    #[Test]
    public function the_coupon_export_holds_every_code_and_who_pays_for_it(): void
    {
        $coupon = $this->coupon();

        $export = $this->export('coupon', $this->superAdmin());

        $this->assertSame([
            'id', 'code', 'name_en', 'name_ar', 'type', 'value', 'max_discount', 'min_order_total',
            'applies_to_delivery', 'applies_to', 'discount_bearer', 'discount_laundry_share', 'max_redemptions',
            'max_per_user', 'starts_at', 'ends_at', 'status', 'redemptions_count',
        ], $export['headers']);
        $this->assertCount(1, $export['rows']);

        $row = $export['rows'][0];
        $this->assertSame($coupon->id, (int) $row['id']);
        $this->assertSame('WELCOME', $row['code']);
        $this->assertSame('أهلا', $row['name_ar']);
        $this->assertSame('split', $row['discount_bearer']);
        $this->assertEquals(30, $row['discount_laundry_share']);
        $this->assertEquals(1, $row['applies_to_delivery']);
    }

    #[Test]
    public function a_coupon_import_adds_changes_and_refuses_row_by_row(): void
    {
        $coupon = $this->coupon();

        $report = $this->import('coupon', [
            // Row 2: new, typed in lower case the way somebody would.
            ['id' => null, 'code' => 'summer10', 'name_en' => 'Summer', 'type' => 'fixed', 'value' => 15, 'max_per_user' => 2, 'status' => 'active'],
            // Row 3: the value only — every other cell blank.
            ['id' => $coupon->id, 'value' => 20],
            // Row 4: refused — the stored type is a percentage, and 150% is
            // caught even though the row does not say so.
            ['id' => $coupon->id, 'value' => 150],
            // Row 5: refused — a new code needs a code.
            ['id' => null, 'name_en' => 'Nameless', 'type' => 'fixed', 'value' => 5, 'max_per_user' => 1, 'status' => 'active'],
        ], $this->superAdmin());

        $this->assertSame(1, $report['created'], json_encode($report));
        $this->assertSame(1, $report['updated'], json_encode($report));
        $this->assertSame([4, 5], array_column($report['failed'], 'row'), json_encode($report));
        $this->assertContains(__('A percentage cannot exceed 100.'), $report['failed'][0]['messages']);

        // Stored the way the form stores it.
        $summer = Coupon::where('code', 'SUMMER10')->first();
        $this->assertNotNull($summer);
        $this->assertSame('Summer', $summer->name->en);

        $coupon = $coupon->fresh();
        $this->assertEquals(20, $coupon->value);
        // Blank cells left alone — including the box the service would read as
        // unticked, and the Arabic name and the laundry's share.
        $this->assertTrue($coupon->applies_to_delivery);
        $this->assertSame('أهلا', $coupon->name->ar);
        $this->assertSame(Coupon::PERCENTAGE, $coupon->type);
        $this->assertEquals(30, $coupon->discount_laundry_share);
    }

    #[Test]
    public function who_pays_for_a_coupon_is_neither_exported_nor_imported_without_setting_update(): void
    {
        $coupon = $this->coupon();
        $operator = $this->admin(['coupon.view', 'coupon.update']);

        $export = $this->export('coupon', $operator);
        $this->assertNotContains('discount_bearer', $export['headers']);
        $this->assertNotContains('discount_laundry_share', $export['headers']);

        $report = $this->import('coupon', [
            ['id' => $coupon->id, 'discount_bearer' => 'laundry', 'discount_laundry_share' => 100, 'max_per_user' => 3],
        ], $operator);

        $this->assertSame(1, $report['updated'], json_encode($report));
        $coupon = $coupon->fresh();
        $this->assertSame(3, (int) $coupon->max_per_user);
        $this->assertEquals(30, $coupon->discount_laundry_share);
    }

    // -------------------------------------------------------------- laundries

    #[Test]
    public function the_laundry_export_holds_the_owner_but_never_their_password(): void
    {
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');

        $export = $this->export('laundry', $this->superAdmin());

        $this->assertSame([
            'id', 'name_en', 'name_ar', 'phone', 'email', 'address', 'city_id', 'city', 'lat', 'lng', 'status',
            'laundry_share', 'owner_name', 'owner_email', 'owner_phone', 'owner_password',
        ], $export['headers']);
        $this->assertCount(1, $export['rows']);
        $this->assertSame('ownera@test.local', $export['rows'][0]['owner_email']);
        $this->assertNoPasswordLeaves($export, $tenant['owner']->fresh()->password);
    }

    #[Test]
    public function a_laundry_owner_exports_their_own_laundry_and_nobody_elses(): void
    {
        $a = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->laundryWithOwner('B', '+201011110003', '+201011110004');

        $export = $this->export('laundry', $a['owner']);

        $this->assertCount(1, $export['rows']);
        $this->assertSame($a['laundry']->id, (int) $export['rows'][0]['id']);
    }

    #[Test]
    public function a_laundry_import_creates_one_with_its_owner_changes_one_and_refuses_one(): void
    {
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $laundry = $tenant['laundry'];

        $report = $this->import('laundry', [
            // Row 2: new, with the owner account created alongside it.
            [
                'id' => null, 'name_en' => 'Fresh', 'name_ar' => 'فريش', 'phone' => '+201055550001', 'status' => 'active',
                'owner_name' => 'Fresh Owner', 'owner_email' => 'fresh@test.local', 'owner_phone' => '+201055550002',
                'owner_password' => 'secret123',
            ],
            // Row 3: the phone only — email and password blank.
            ['id' => $laundry->id, 'phone' => '+201011119999'],
            // Row 4: refused — a new laundry needs its owner.
            ['id' => null, 'name_en' => 'Ownerless', 'phone' => '+201055550003', 'status' => 'active'],
        ], $this->superAdmin());

        $this->assertSame(1, $report['created'], json_encode($report));
        $this->assertSame(1, $report['updated'], json_encode($report));
        $this->assertSame([4], array_column($report['failed'], 'row'), json_encode($report));

        $fresh = Laundry::where('phone', '+201055550001')->first();
        $this->assertNotNull($fresh);
        $this->assertSame('فريش', $fresh->name->ar);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame('fresh@test.local', $fresh->owner?->email);
        $this->assertTrue(Hash::check('secret123', $fresh->owner->password));

        $laundry = $laundry->fresh();
        $this->assertSame('+201011119999', $laundry->phone);
        $this->assertSame('laundrya@test.local', $laundry->email);
        // A blank password cell leaves the owner's password alone.
        $this->assertTrue(Hash::check('password', $tenant['owner']->fresh()->password));
    }

    #[Test]
    public function a_laundry_import_hands_the_owner_a_new_password_only_when_one_is_given(): void
    {
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');

        $report = $this->import('laundry', [
            ['id' => $tenant['laundry']->id, 'owner_password' => 'rotated123'],
        ], $this->superAdmin());

        $this->assertSame(1, $report['updated'], json_encode($report));
        $this->assertTrue(Hash::check('rotated123', $tenant['owner']->fresh()->password));
    }

    // ---------------------------------------------------------------- drivers

    #[Test]
    public function the_driver_export_holds_the_profile_and_areas_but_never_a_password(): void
    {
        $zone = $this->geo['zones'][0];
        $driver = $this->driverUser('+201033330001', zoneIds: [$zone->id]);
        // A customer on the same table is not a driver.
        $this->customer();

        $export = $this->export('driver', $this->superAdmin());

        $this->assertContains('password', $export['headers']);
        $this->assertCount(1, $export['rows']);

        $row = $export['rows'][0];
        $this->assertSame($driver->id, (int) $row['id']);
        // The form's reading of the free text this used to be.
        $this->assertSame('motorcycle', $row['vehicle_type']);
        $this->assertSame('09:00', $row['shift_start']);
        $this->assertSame((string) $zone->id, (string) $row['zones']);
        $this->assertSame('Nasr City', $row['zone_names']);
        $this->assertNoPasswordLeaves($export, $driver->fresh()->password);
    }

    #[Test]
    public function a_driver_import_creates_one_changes_one_and_refuses_row_by_row(): void
    {
        [$nasr, $maadi] = $this->geo['zones'];
        $driver = $this->driverUser('+201033330001', available: true);

        $report = $this->import('driver', [
            // Row 2: new, with a password, two areas and a vehicle typed by hand.
            [
                'id' => null, 'name' => 'New Driver', 'phone' => '+201033330002', 'password' => 'secret123',
                'status' => 'active', 'vehicle_type' => 'Tuk-tuk', 'shift_start' => '08:00', 'shift_end' => '16:00',
                'is_available' => 1, 'zones' => "{$nasr->id},{$maadi->id}",
            ],
            // Row 3: the plate only — availability, password and areas blank.
            ['id' => $driver->id, 'plate_number' => 'XYZ 789'],
            // Row 4: refused — the shift would end before its stored start.
            ['id' => $driver->id, 'shift_end' => '08:00'],
            // Row 5: refused — a new driver needs a phone.
            ['id' => null, 'name' => 'No Phone', 'password' => 'secret123', 'status' => 'active'],
            // Row 6: refused — the super admin is on the same table, and a
            // driver sheet's password column must never reach them.
            ['id' => $this->superAdmin()->id, 'password' => 'hijacked1'],
        ], $this->superAdmin());

        $this->assertSame(1, $report['created'], json_encode($report));
        $this->assertSame(1, $report['updated'], json_encode($report));
        $this->assertSame([4, 5, 6], array_column($report['failed'], 'row'), json_encode($report));
        $this->assertContains(__('The shift end must be after the shift start.'), $report['failed'][0]['messages']);
        $this->assertTrue(Hash::check('password', $this->superAdmin()->fresh()->password));

        $new = Driver::where('phone', '+201033330002')->with(['profile', 'zones'])->first();
        $this->assertNotNull($new);
        $this->assertTrue(Hash::check('secret123', $new->password));
        $this->assertNotNull($new->phone_verified_at);
        $this->assertSame('tuk_tuk', $new->profile->vehicle_type);
        $this->assertTrue($new->profile->is_available);
        $this->assertEqualsCanonicalizing([$nasr->id, $maadi->id], $new->zones->pluck('id')->all());

        $driver = $driver->fresh(['profile', 'zones']);
        $this->assertSame('XYZ 789', $driver->profile->plate_number);
        // Blank cells left alone — the switch the service would read as off,
        // the password, the shift the refused row tried to move.
        $this->assertTrue($driver->profile->is_available);
        $this->assertTrue(Hash::check('password', $driver->password));
        $this->assertSame('21:00', substr((string) $driver->profile->shift_end, 0, 5));
        $this->assertSame('DL-9911', $driver->profile->license_number);
    }

    // -------------------------------------------------------------- customers

    #[Test]
    public function the_customer_export_holds_customers_only_and_no_secret(): void
    {
        $customer = $this->customer('+201099887766');
        $this->driverUser('+201033330001');

        $export = $this->export('user', $this->superAdmin());

        $this->assertSame(['id', 'name', 'phone', 'email', 'customer_reference', 'status', 'created_at'], $export['headers']);
        $this->assertCount(1, $export['rows']);
        $this->assertSame($customer->id, (int) $export['rows'][0]['id']);
        $this->assertNoPasswordLeaves($export, $customer->fresh()->password);
    }

    #[Test]
    public function customers_export_but_do_not_import(): void
    {
        // The customer form requires a photograph to add one, which no cell
        // can carry — so the sheet says it does not import rather than
        // importing half of what its template offers.
        $this->assertFalse(app(SheetRegistry::class)->find('user')->importable());

        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('admin.spreadsheet.template', 'user'))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.spreadsheet.import', 'user'), [
            'file' => $this->xlsx([['id' => null, 'name' => 'Sneaky', 'phone' => '+201099990000']]),
        ])->assertNotFound();

        $this->assertNull(User::where('phone', '+201099990000')->first());
    }
}
