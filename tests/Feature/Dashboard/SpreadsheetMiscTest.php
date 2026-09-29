<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Driver\Models\DriverApplication;
use App\Modules\Driver\Models\DriverBonusRule;
use App\Modules\Driver\Models\DriverBonusTier;
use App\Modules\Faq\Models\Faq;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\LaundryService\Models\LaundryServiceRequest;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Offer\Models\Offer;
use App\Modules\Payment\Models\CommissionRule;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
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
 * The export-only sheets: laundry staff, driver applications, commissions,
 * offers, FAQ, time slots, moderators, the notification log, service requests
 * and driver bonus rules.
 *
 * What matters here: each one exports for the super admin with the headers it
 * declares; none writes a password, token or OTP; the tenant-scoped ones export
 * only the laundry's own rows; the screen's own filters narrow the export the
 * way they narrow the list; and none of them can be imported into.
 */
class SpreadsheetMiscTest extends TestCase
{
    use RefreshDatabase;

    private const SHEETS = [
        'laundry_staff', 'driver_application', 'commission_rule', 'offer', 'faq',
        'time_slot', 'moderator', 'notification_log', 'laundry_service_request', 'driver_bonus_rule',
    ];

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array{laundry: Laundry, owner: User} */
    private array $tenantA;

    /** @var array{laundry: Laundry, owner: User} */
    private array $tenantB;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        $this->tenantA = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->tenantB = $this->laundryWithOwner('B', '+201022220001', '+201022220002');
    }

    private function tr(string $en, string $ar): string
    {
        return json_encode(['en' => $en, 'ar' => $ar], JSON_UNESCAPED_UNICODE);
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
     * @param  array<string, mixed>  $query
     * @return array<int, array<int, mixed>>
     */
    private function export(User $user, string $sheet, array $query = []): array
    {
        $response = $this->actingAs($user)->get(route('admin.spreadsheet.export', ['sheet' => $sheet] + $query));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));

        return $this->read($response->getFile()->getPathname());
    }

    /**
     * One column of an export, by header, without the header row.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, mixed>
     */
    private function column(array $rows, string $header): array
    {
        $index = array_search($header, $rows[0], true);
        $this->assertNotFalse($index, "no {$header} column");

        return array_map(fn (array $row) => $row[$index] ?? null, array_slice($rows, 1));
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, int>
     */
    private function ids(array $rows): array
    {
        $ids = array_map('intval', $this->column($rows, 'id'));
        sort($ids);

        return $ids;
    }

    private function staff(array $tenant, string $name, string $phone): User
    {
        return User::create([
            'name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@test.local',
            'phone' => $phone, 'password' => 'secret-password', 'status' => 'active',
            'role_id' => Role::where('slug', 'laundry_staff')->value('id'),
            'laundry_id' => $tenant['laundry']->id,
            'phone_verified_at' => now(),
        ]);
    }

    private function serviceRequest(array $tenant, string $status, string $action = 'open'): LaundryServiceRequest
    {
        return LaundryServiceRequest::create([
            'laundry_id' => $tenant['laundry']->id,
            'service_id' => $this->catalog['service']->id,
            'action' => $action,
            'status' => $status,
            'requested_by' => $tenant['owner']->id,
        ]);
    }

    /**
     * At least one row behind every sheet, so each column's reader runs.
     */
    private function seedOneOfEach(): void
    {
        $this->staff($this->tenantA, 'Staff A', '+201011110003');

        DriverApplication::create(['name' => 'Applicant', 'phone' => '+201055550001', 'note' => 'Has a motorcycle']);

        $rule = CommissionRule::create([
            'name' => $this->tr('Standard share', 'النسبة العادية'),
            'basis' => 'percent', 'rate' => 80, 'status' => 'active',
        ]);
        $rule->laundries()->attach($this->tenantA['laundry']->id);

        Offer::create([
            'title' => $this->tr('Summer', 'الصيف'),
            'description' => $this->tr('Summer offer', 'عرض الصيف'),
            'target_type' => 'none', 'sort_order' => 1, 'status' => 'active',
        ]);

        Faq::create([
            'question' => $this->tr('How?', 'كيف؟'),
            'answer' => $this->tr('Like this.', 'هكذا.'),
            'audience' => 'both', 'order' => 1, 'status' => 'active',
        ]);

        TimeSlot::create([
            'start_time' => '09:00:00', 'end_time' => '12:00:00',
            'applies_to' => 'both', 'capacity' => 10, 'sort_order' => 1, 'status' => 'active',
        ]);

        User::create([
            'name' => 'Moderator', 'email' => 'moderator@test.local', 'phone' => '+201066660001',
            'password' => 'secret-password', 'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);

        NotificationLog::create([
            'user_id' => $this->tenantA['owner']->id, 'event' => 'order_placed', 'channel' => 'push',
            'status' => NotificationLog::SENT, 'destination' => '…abcd', 'title' => 'Order placed', 'body' => 'Hello',
        ]);

        $this->serviceRequest($this->tenantA, LaundryServiceRequest::PENDING);

        $bonus = DriverBonusRule::create([
            'name' => $this->tr('Per order', 'لكل طلب'),
            'basis' => 'per_order', 'amount' => 5, 'status' => 'active',
        ]);
        DriverBonusTier::create(['driver_bonus_rule_id' => $bonus->id, 'min_orders' => 10, 'amount' => 50]);
    }

    #[Test]
    public function every_sheet_in_the_batch_exports_for_the_super_admin(): void
    {
        $this->seedOneOfEach();
        $registry = app(SheetRegistry::class);
        $admin = $this->superAdmin();

        foreach (self::SHEETS as $key) {
            $this->assertTrue($registry->has($key), "{$key} is not registered");

            $rows = $this->export($admin, $key);

            $this->assertSame($registry->find($key)->headers(), $rows[0], "{$key} headers");
            $this->assertGreaterThanOrEqual(2, count($rows), "{$key} exported no rows");
        }
    }

    #[Test]
    public function no_export_carries_a_password_a_token_or_a_code(): void
    {
        $this->actingAs($this->superAdmin());
        $registry = app(SheetRegistry::class);

        foreach (self::SHEETS as $key) {
            $headers = $registry->find($key)->headers();

            foreach (['password', 'remember_token', 'otp', 'otp_expires_at', 'otp_attempts', 'token'] as $secret) {
                $this->assertNotContains($secret, $headers, "{$key} exports {$secret}");
            }
        }
    }

    #[Test]
    public function the_translatable_columns_come_out_one_per_language(): void
    {
        $this->seedOneOfEach();
        $rows = $this->export($this->superAdmin(), 'commission_rule');

        $this->assertSame(['Standard share'], $this->column($rows, 'name_en'));
        $this->assertSame(['النسبة العادية'], $this->column($rows, 'name_ar'));
        $this->assertSame([1], array_map('intval', $this->column($rows, 'laundries_count')));
    }

    #[Test]
    public function a_laundry_owner_exports_only_its_own_staff(): void
    {
        $mine = $this->staff($this->tenantA, 'Staff A', '+201011110003');
        $theirs = $this->staff($this->tenantB, 'Staff B', '+201022220003');

        $this->grant('laundry_owner', ['laundry_staff.view']);
        $owner = $this->tenantA['owner']->fresh();

        $rows = $this->export($owner, 'laundry_staff');
        $ids = $this->ids($rows);

        $this->assertContains($mine->id, $ids);
        $this->assertContains($owner->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->assertNotContains($this->tenantB['owner']->id, $ids);
        $this->assertSame([$this->tenantA['laundry']->id], array_values(array_unique(array_map('intval', $this->column($rows, 'laundry_id')))));

        // And the super admin sees both laundries' staff.
        $all = $this->ids($this->export($this->superAdmin(), 'laundry_staff'));
        $this->assertContains($theirs->id, $all);
        $this->assertContains($mine->id, $all);
    }

    #[Test]
    public function a_laundry_owner_exports_only_its_own_service_requests(): void
    {
        $mine = $this->serviceRequest($this->tenantA, LaundryServiceRequest::PENDING);
        $theirs = $this->serviceRequest($this->tenantB, LaundryServiceRequest::PENDING);

        $this->grant('laundry_owner', ['laundry_service_request.view']);

        $ids = $this->ids($this->export($this->tenantA['owner']->fresh(), 'laundry_service_request'));

        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    #[Test]
    public function an_export_needs_the_screens_own_view_permission(): void
    {
        $this->grant('laundry_owner', ['laundry.view']);
        $owner = $this->tenantA['owner']->fresh();

        foreach (self::SHEETS as $key) {
            $this->actingAs($owner)->get(route('admin.spreadsheet.export', $key))->assertForbidden();
        }
    }

    #[Test]
    public function a_searched_staff_export_holds_only_what_the_search_found(): void
    {
        $found = $this->staff($this->tenantA, 'Zeinab Unique', '+201011110003');
        $this->staff($this->tenantA, 'Other Person', '+201011110004');

        // By the laundry's name too — a relation the list's search reaches.
        $this->assertSame([$found->id], $this->ids($this->export($this->superAdmin(), 'laundry_staff', ['query' => 'zeinab'])));

        $byLaundry = $this->ids($this->export($this->superAdmin(), 'laundry_staff', ['query' => 'Laundry B']));
        $this->assertSame([$this->tenantB['owner']->id], $byLaundry);
    }

    #[Test]
    public function the_service_request_export_follows_the_status_filter(): void
    {
        $pending = $this->serviceRequest($this->tenantA, LaundryServiceRequest::PENDING);
        $approved = $this->serviceRequest($this->tenantA, LaundryServiceRequest::APPROVED, 'close');
        $rejected = $this->serviceRequest($this->tenantB, LaundryServiceRequest::REJECTED);
        $superseded = $this->serviceRequest($this->tenantB, LaundryServiceRequest::SUPERSEDED);

        $admin = $this->superAdmin();

        // Absent is pending — the screen opens there.
        $this->assertSame([$pending->id], $this->ids($this->export($admin, 'laundry_service_request')));
        $this->assertSame([$approved->id], $this->ids($this->export($admin, 'laundry_service_request', ['status' => 'approved'])));

        // «All» is every decision, never the superseded.
        $all = $this->ids($this->export($admin, 'laundry_service_request', ['status' => 'all']));
        $this->assertSame([$pending->id, $approved->id, $rejected->id], $all);
        $this->assertNotContains($superseded->id, $all);

        // And the term composes with the filter rather than escaping it.
        $this->assertSame([$rejected->id], $this->ids($this->export($admin, 'laundry_service_request', [
            'status' => 'all', 'query' => 'Laundry B',
        ])));
    }

    #[Test]
    public function the_notification_log_export_follows_the_search_and_both_filters(): void
    {
        $recipient = $this->tenantA['owner'];
        $base = ['user_id' => $recipient->id, 'channel' => 'push', 'body' => null];

        $placedSent = NotificationLog::create($base + ['event' => 'order_placed', 'status' => 'sent', 'title' => 'Welcome aboard']);
        $placedFailed = NotificationLog::create($base + ['event' => 'order_placed', 'status' => 'failed', 'title' => 'Welcome aboard']);
        $otherSent = NotificationLog::create($base + ['event' => 'driver_on_way', 'status' => 'sent', 'title' => 'On the way']);

        $admin = $this->superAdmin();

        $this->assertSame([$placedSent->id, $placedFailed->id], $this->ids($this->export($admin, 'notification_log', ['event' => 'order_placed'])));
        $this->assertSame([$placedFailed->id], $this->ids($this->export($admin, 'notification_log', ['status' => 'failed'])));
        $this->assertSame([$otherSent->id], $this->ids($this->export($admin, 'notification_log', ['query' => 'the way'])));

        // A term matching a title must not escape the status filter.
        $this->assertSame([$placedSent->id], $this->ids($this->export($admin, 'notification_log', [
            'query' => 'welcome', 'status' => 'sent',
        ])));
    }

    #[Test]
    public function every_screen_carries_its_export_button_wired_to_its_own_controls(): void
    {
        $this->seedOneOfEach();
        $admin = $this->superAdmin();

        // route => [sheet, search box, filter controls]
        $screens = [
            'admin.laundry_staff.index' => ['laundry_staff', '#staffSearchInput', []],
            'admin.driver_application.index' => ['driver_application', '#applicationSearchInput', []],
            'admin.commission_rule.index' => ['commission_rule', '#commissionSearchInput', []],
            'admin.offer.index' => ['offer', '#offerSearchInput', []],
            'admin.faq.index' => ['faq', '#faqSearchInput', []],
            // Client-side filter only: nothing for the export to follow.
            'admin.time_slot.index' => ['time_slot', '', []],
            'admin.moderator.index' => ['moderator', '#moderatorSearchInput', []],
            'admin.notification.index' => ['notification_log', '#notificationSearchInput', [
                'event' => '#notificationEventFilter', 'status' => '#notificationStatusFilter',
            ]],
            'admin.laundry_service_request.index' => ['laundry_service_request', '#serviceRequestSearchInput', [
                'status' => '#serviceRequestStatusFilter',
            ]],
            'admin.driver_bonus_rule.index' => ['driver_bonus_rule', '#ruleSearchInput', []],
        ];

        foreach ($screens as $route => [$sheet, $search, $filters]) {
            $response = $this->actingAs($admin)->get(route($route));

            $response->assertOk();
            $response->assertSee(route('admin.spreadsheet.export', $sheet), false);
            // Export only: no import dialog on any of them.
            $response->assertDontSee('spreadsheet-import-'.$sheet, false);

            // The ids the button reads at click time, as the component prints them.
            $response->assertSee('data-search="'.$search.'"', false);
            $response->assertSee("data-filters='".json_encode($filters, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)."'", false);
        }
    }

    #[Test]
    public function none_of_these_sheets_imports_or_offers_a_template(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['id', 'name']));
        $writer->addRow(Row::fromValues([null, 'Anything']));
        $writer->close();

        $admin = $this->superAdmin();

        foreach (self::SHEETS as $key) {
            $this->assertFalse(app(SheetRegistry::class)->find($key)->importable(), "{$key} is importable");

            $file = new UploadedFile($path, 'sheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

            $this->actingAs($admin)->post(route('admin.spreadsheet.import', $key), ['file' => $file])->assertNotFound();
            $this->actingAs($admin)->get(route('admin.spreadsheet.template', $key))->assertNotFound();
        }
    }
}
