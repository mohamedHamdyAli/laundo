<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Complaint\Models\Complaint;
use App\Modules\Driver\Models\DriverBonusAward;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRating;
use App\Modules\Order\Models\OrderRecurrence;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderService;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Payment\Models\OrderSettlement;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Models\Refund;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Models\Wallet;
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
 * Excel export for the money and operations screens.
 *
 * These sheets export only — a payment, a settlement or a wallet movement typed
 * into a spreadsheet is a ledger entry nobody earned — and each one must hold
 * exactly what its screen holds: the same scoped query, the same search, the
 * same filters and the same default the screen opens on.
 */
class SpreadsheetOperationsTest extends TestCase
{
    use RefreshDatabase;

    /** Every sheet in this batch, by key — the screen's permission slug. */
    private const SHEETS = [
        'order', 'payment', 'refund', 'wallet', 'order_settlement',
        'driver_earning', 'driver_bonus_award', 'complaint', 'order_rating', 'order_recurrence',
    ];

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array{laundry: Laundry, owner: User} */
    private array $tenant;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer('+201099880011');
    }

    private function place(array $extra = []): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        if ($extra !== []) {
            $order->forceFill($extra)->save();
        }

        return $order->fresh();
    }

    /**
     * One row on every screen, each in the state the screen opens on, so an
     * unfiltered export of every sheet has something in it to read.
     */
    private function oneOfEverything(): Order
    {
        $order = $this->place();
        $driver = $this->driverUser('+201033330001');

        $payment = Payment::create([
            'order_id' => $order->id, 'user_id' => $this->customer->id,
            'provider' => 'fake', 'method' => 'card', 'provider_reference' => 'REF-1',
            'amount' => 34, 'currency' => 'EGP', 'status' => 'captured', 'captured_at' => now(),
        ]);

        Refund::create([
            'order_id' => $order->id, 'user_id' => $this->customer->id, 'payment_id' => $payment->id,
            'amount' => 10, 'reason' => 'Shirt came back torn', 'status' => Refund::PENDING,
        ]);

        Wallet::create(['user_id' => $this->customer->id, 'balance' => 25, 'pending_balance' => 0, 'currency' => 'EGP']);

        OrderSettlement::create([
            'order_id' => $order->id, 'laundry_id' => $this->tenant['laundry']->id,
            'basis' => 34, 'commission_rate' => 20, 'laundry_share_rate' => 80,
            'commission_amount' => 6.8, 'laundry_amount' => 27.2,
            'discount_amount' => 0, 'laundry_discount_amount' => 0,
            'tax_amount' => 0, 'platform_fee_amount' => 0, 'status' => OrderSettlement::PENDING,
        ]);

        DriverEarning::create([
            'driver_id' => $driver->id, 'order_id' => $order->id,
            'order_task_id' => OrderTask::where('order_id', $order->id)->value('id'),
            'amount' => 5, 'basis' => 25, 'rate' => 0.2, 'status' => DriverEarning::PENDING,
        ]);

        DriverBonusAward::create([
            'driver_id' => $driver->id, 'period' => now()->format('Y-m'),
            'orders_count' => 12, 'failed_tasks' => 1, 'amount' => 150,
            'status' => DriverBonusAward::DUE, 'gate_failures' => ['rating'],
        ]);

        Complaint::create([
            'reference' => 'CMP-0001', 'user_id' => $this->customer->id, 'order_id' => $order->id,
            'laundry_id' => $this->tenant['laundry']->id, 'category' => 'late',
            'body' => 'Two days late', 'status' => 'new',
        ]);

        OrderRating::create([
            'order_id' => $order->id, 'user_id' => $this->customer->id, 'laundry_id' => $this->tenant['laundry']->id,
            'overall' => 2, 'delivery' => 3, 'tags' => ['on_time'], 'comment' => 'Creased',
        ]);

        OrderRecurrence::create([
            'user_id' => $this->customer->id, 'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $order->pickup_address_id, 'frequency' => 'weekly', 'day_of_week' => 2,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'next_prompt_on' => now()->addWeek()->toDateString(), 'status' => 'active',
        ]);

        return $order;
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
     * The exported rows of one sheet, as [header => value] maps.
     *
     * @param  array<string, string>  $params
     * @return array<int, array<string, mixed>>
     */
    private function export(string $sheet, array $params = [], ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->superAdmin())
            ->get(route('admin.spreadsheet.export', ['sheet' => $sheet] + $params));

        $response->assertOk();

        $rows = $this->read($response->getFile()->getPathname());
        $headers = array_shift($rows);

        return array_map(fn (array $row) => array_combine($headers, array_pad($row, count($headers), null)), $rows);
    }

    private function xlsx(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['id', 'status']));
        $writer->addRow(Row::fromValues([1, 'settled']));
        $writer->close();

        return new UploadedFile($path, 'sheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    #[Test]
    public function every_operations_sheet_exports_its_headers_and_its_rows(): void
    {
        $this->oneOfEverything();
        $registry = app(SheetRegistry::class);

        foreach (self::SHEETS as $key) {
            $this->assertTrue($registry->has($key), "{$key} is not registered");
            $sheet = $registry->find($key);

            $this->assertSame($key, $sheet->permission(), "{$key} answers to another permission");
            $this->assertFalse($sheet->importable(), "{$key} is a money or operations record and must not import");

            $response = $this->actingAs($this->superAdmin())->get(route('admin.spreadsheet.export', $key));
            $response->assertOk();

            $rows = $this->read($response->getFile()->getPathname());

            $this->assertSame($sheet->headers(), $rows[0], "{$key} header row");
            $this->assertCount(2, $rows, "{$key} should hold the one row the screen opens on");
        }
    }

    #[Test]
    public function money_figures_are_raw_numbers_and_dates_are_machine_readable(): void
    {
        $order = $this->oneOfEverything();

        $settlement = $this->export('order_settlement')[0];
        $this->assertSame($order->code, (string) $settlement['order_code']);
        $this->assertEquals(34, $settlement['basis']);
        $this->assertEquals(6.8, $settlement['commission_amount']);
        $this->assertEquals(27.2, $settlement['laundry_amount']);
        $this->assertEquals(80, $settlement['laundry_share_rate']);
        $this->assertSame('pending', $settlement['status']);

        $row = $this->export('order')[0];
        $this->assertSame($order->id, (int) $row['id']);
        $this->assertSame('+201099880011', $row['customer_phone']);
        $this->assertEquals($order->payableTotal(), $row['payable_total']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row['created_at']);
        // A secret the driver scans; never in a file that gets emailed around.
        $this->assertArrayNotHasKey('qr_token', $row);

        $wallet = $this->export('wallet')[0];
        $this->assertEquals(25, $wallet['balance']);
        $this->assertEquals(0, $wallet['ledger_balance']);
        $this->assertSame(0, (int) $wallet['reconciled'], 'a cached balance with no ledger behind it does not reconcile');
    }

    #[Test]
    public function the_order_export_honours_the_status_filter_and_the_search(): void
    {
        $open = $this->place();
        $cancelled = $this->place(['status' => OrderStatus::Cancelled->value]);
        $unassigned = $this->place(['laundry_id' => null]);

        $ids = fn (array $params) => array_map(fn ($row) => (int) $row['id'], $this->export('order', $params));

        $this->assertEqualsCanonicalizing([$open->id, $cancelled->id, $unassigned->id], $ids([]));
        $this->assertSame([$cancelled->id], $ids(['status' => OrderStatus::Cancelled->value]));

        // A queue filter that is not a status — the null laundry — as the
        // list's dropdown spells it.
        $this->assertSame([$unassigned->id], $ids(['status' => 'needs_laundry']));

        $this->assertSame([$open->id], $ids(['query' => $open->code]));
        $this->assertSame([], $ids(['query' => $open->code, 'status' => OrderStatus::Cancelled->value]));
    }

    #[Test]
    public function a_laundry_owner_exports_only_its_own_orders_and_settlements(): void
    {
        $mine = $this->place();

        $other = $this->laundryWithOwner('B', '+201022220001', '+201022220002')['laundry'];
        $theirs = $this->place(['laundry_id' => $other->id]);

        foreach ([[$mine, $this->tenant['laundry']], [$theirs, $other]] as [$order, $laundry]) {
            OrderSettlement::create([
                'order_id' => $order->id, 'laundry_id' => $laundry->id,
                'basis' => 34, 'commission_rate' => 20, 'commission_amount' => 6.8, 'laundry_amount' => 27.2,
                'status' => OrderSettlement::PENDING,
            ]);
        }

        $this->grant('laundry_owner', ['order.view', 'order_settlement.view']);
        $owner = $this->tenant['owner']->fresh();

        $this->assertSame([$mine->id], array_map(fn ($row) => (int) $row['id'], $this->export('order', [], $owner)));
        $this->assertSame([$mine->id], array_map(fn ($row) => (int) $row['order_id'], $this->export('order_settlement', [], $owner)));

        // And nothing it was not granted: a payment is the platform's money.
        $this->actingAs($owner)->get(route('admin.spreadsheet.export', 'payment'))->assertForbidden();
    }

    #[Test]
    public function each_export_opens_where_its_screen_opens(): void
    {
        $order = $this->oneOfEverything();

        // Decided rows beside the open ones each screen leads with.
        Refund::create([
            'order_id' => $order->id, 'user_id' => $this->customer->id,
            'amount' => 5, 'reason' => 'Late', 'status' => Refund::SETTLED,
        ]);
        DriverEarning::query()->update(['status' => DriverEarning::RELEASED]);
        Complaint::create([
            'reference' => 'CMP-0002', 'user_id' => $this->customer->id,
            'category' => 'other', 'body' => 'Sorted', 'status' => 'resolved',
        ]);
        DriverBonusAward::create([
            'driver_id' => User::where('phone', '+201033330001')->value('id'),
            'period' => now()->subMonthNoOverflow()->format('Y-m'), 'amount' => 90, 'status' => DriverBonusAward::APPROVED,
        ]);

        // Refunds and complaints open on what is waiting; `all` is everything.
        $this->assertSame(['pending'], array_column($this->export('refund'), 'status'));
        $this->assertCount(2, $this->export('refund', ['status' => 'all']));
        $this->assertSame(['new'], array_column($this->export('complaint'), 'status'));
        $this->assertCount(2, $this->export('complaint', ['status' => 'all']));

        // Earnings open on what is still held — none now.
        $this->assertSame([], $this->export('driver_earning'));
        $this->assertCount(1, $this->export('driver_earning', ['status' => 'released']));

        // Bonuses are one month at a time, this month unless told otherwise.
        $this->assertSame([now()->format('Y-m')], array_column($this->export('driver_bonus_award'), 'period'));
        $last = now()->subMonthNoOverflow()->format('Y-m');
        $this->assertSame([$last], array_column($this->export('driver_bonus_award', ['period' => $last]), 'period'));

        // Wallets open on the funded ones; `all` includes the empty.
        Wallet::create(['user_id' => $this->superAdmin()->id, 'balance' => 0, 'currency' => 'EGP']);
        $this->assertCount(1, $this->export('wallet'));
        $this->assertCount(2, $this->export('wallet', ['type' => 'all']));

        // Ratings by band.
        $this->assertCount(1, $this->export('order_rating', ['band' => 'poor']));
        $this->assertSame([], $this->export('order_rating', ['band' => 'good']));
    }

    #[Test]
    public function every_screen_offers_the_export_wired_to_its_own_filters(): void
    {
        // index route => [sheet, the filters the button reads, the search box]
        $screens = [
            'admin.order.index' => ['order', ['status' => '#orderStatusFilter'], '#orderSearchInput'],
            'admin.payment.index' => ['payment', ['status' => '#paymentStatusFilter'], '#paymentSearchInput'],
            'admin.refund.index' => ['refund', ['status' => '#refundStatusFilter'], '#refundSearchInput'],
            'admin.wallet.index' => ['wallet', ['type' => '#walletTypeFilter'], '#walletSearchInput'],
            'admin.settlement.index' => ['order_settlement', ['status' => '#settlementStatusFilter'], '#settlementSearchInput'],
            'admin.earning.index' => ['driver_earning', ['status' => '#earningStatusFilter'], '#earningSearchInput'],
            'admin.driver_bonus.index' => ['driver_bonus_award', ['period' => '#bonusPeriodFilter', 'status' => '#bonusStatusFilter'], '#bonusSearchInput'],
            'admin.complaint.index' => ['complaint', ['status' => '#complaintStatusFilter', 'audience' => '#complaintAudienceFilter'], '#complaintSearchInput'],
            'admin.rating.index' => ['order_rating', ['band' => '#ratingBandFilter'], '#ratingSearchInput'],
            'admin.recurrence.index' => ['order_recurrence', ['status' => '#recurrenceStatusFilter'], '#recurrenceSearchInput'],
        ];

        $admin = $this->superAdmin();

        foreach ($screens as $route => [$sheet, $filters, $search]) {
            $response = $this->actingAs($admin)->get(route($route));

            $response->assertOk();
            $response->assertSee(route('admin.spreadsheet.export', $sheet), false);
            $response->assertSee('data-search="'.$search.'"', false);
            $response->assertSee(json_encode($filters), false);
        }
    }

    #[Test]
    public function none_of_these_sheets_imports_or_offers_a_template(): void
    {
        $admin = $this->superAdmin();

        foreach (self::SHEETS as $key) {
            $this->actingAs($admin)
                ->post(route('admin.spreadsheet.import', $key), ['file' => $this->xlsx()])
                ->assertNotFound();

            $this->actingAs($admin)->get(route('admin.spreadsheet.template', $key))->assertNotFound();
        }
    }
}
