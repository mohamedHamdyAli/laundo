<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Loyalty\Enums\PointReason;
use App\Modules\Loyalty\Models\PointAccount;
use App\Modules\Loyalty\Models\PointTransaction;
use App\Modules\Loyalty\Services\PointsService;
use App\Modules\Order\Models\Order;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use App\Support\Spreadsheet\Sheets\PointAccountSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «نقاط العملاء» on the panel, and the «النقاط» tab on the settings screen.
 */
class PointAccountDashboardTest extends TestCase
{
    use RefreshDatabase;

    private PointsService $points;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->points = app(PointsService::class);
    }

    #[Test]
    public function the_list_shows_every_customer_holding_points_and_searches_them(): void
    {
        $alice = $this->customer('+201099880001');
        $alice->update(['name' => 'Alice Points']);
        $bob = $this->customer('+201099880002');
        $bob->update(['name' => 'Bob Points']);
        $this->points->credit($alice, 30, PointReason::Adjustment);
        $this->points->credit($bob, 5, PointReason::Adjustment);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.point_account.index'))
            ->assertOk()
            ->assertSee('Alice Points')
            ->assertSee('Bob Points');

        $found = $this->actingAs($this->superAdmin())
            ->getJson(route('admin.point_account.search', ['query' => 'alice']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('table');

        $this->assertStringContainsString('Alice Points', $found);
        $this->assertStringNotContainsString('Bob Points', $found);

        // Every column the list shows is searchable — the phone too.
        $byPhone = $this->actingAs($this->superAdmin())
            ->getJson(route('admin.point_account.search', ['query' => '880002']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->json('table');

        $this->assertStringContainsString('Bob Points', $byPhone);
    }

    #[Test]
    public function an_account_that_disagrees_with_its_ledger_is_flagged(): void
    {
        $honest = $this->customer('+201099880001');
        $tampered = $this->customer('+201099880002');
        $this->points->credit($honest, 10, PointReason::Adjustment);
        $this->points->credit($tampered, 10, PointReason::Adjustment);

        // A balance written by hand, past the service — what the check exists for.
        PointAccount::where('user_id', $tampered->id)->update(['balance' => 99]);

        $this->assertSame(1, PointAccount::query()->outOfBalance()->count());
        $this->assertTrue(PointAccount::query()->withLedger()->where('user_id', $honest->id)->firstOrFail()->isReconciled());
        $this->assertFalse(PointAccount::query()->withLedger()->where('user_id', $tampered->id)->firstOrFail()->isReconciled());

        $this->actingAs($this->superAdmin())
            ->get(route('admin.point_account.index'))
            ->assertOk()
            ->assertSee(__('Does not match the ledger'));
    }

    #[Test]
    public function a_customer_can_be_given_points_and_have_them_taken_with_a_reason(): void
    {
        $customer = $this->customer();
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.point_account.adjust', $customer->id), [
                'direction' => 'credit', 'points' => 25, 'note' => 'Apology for a late delivery',
            ])->assertRedirect()->assertSessionHas('success');

        $this->actingAs($admin)
            ->post(route('admin.point_account.adjust', $customer->id), [
                'direction' => 'debit', 'points' => 5, 'note' => 'Typed twice',
            ])->assertRedirect();

        $this->assertSame(20, $this->points->available($customer));
        $this->assertSame(2, PointTransaction::where('user_id', $customer->id)->where('created_by', $admin->id)->count());

        $this->actingAs($admin)
            ->get(route('admin.point_account.show', $customer->id))
            ->assertOk()
            ->assertSee('Apology for a late delivery');
    }

    #[Test]
    public function a_correction_needs_a_reason_and_cannot_overdraw(): void
    {
        $customer = $this->customer();
        $this->points->credit($customer, 3, PointReason::Adjustment);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.point_account.adjust', $customer->id), ['direction' => 'credit', 'points' => 5])
            ->assertSessionHasErrors('note');

        $this->actingAs($this->superAdmin())
            ->post(route('admin.point_account.adjust', $customer->id), [
                'direction' => 'debit', 'points' => 4, 'note' => 'too many',
            ])->assertSessionHas('error');

        $this->assertSame(3, $this->points->available($customer));
    }

    #[Test]
    public function only_a_customer_holds_points(): void
    {
        $driver = $this->driverUser();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.point_account.show', $driver->id))
            ->assertNotFound();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.point_account.adjust', $driver->id), [
                'direction' => 'credit', 'points' => 5, 'note' => 'nope',
            ])->assertNotFound();

        $this->assertSame(0, PointAccount::count());
    }

    #[Test]
    public function reading_is_not_correcting(): void
    {
        $customer = $this->customer();
        $moderator = User::create([
            'name' => 'Mod', 'phone' => '+201000000077', 'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);
        $this->grant('admin', ['point_account.view']);

        $this->actingAs($moderator)->get(route('admin.point_account.show', $customer->id))->assertOk();

        $this->actingAs($moderator)
            ->post(route('admin.point_account.adjust', $customer->id), [
                'direction' => 'credit', 'points' => 5, 'note' => 'x',
            ])->assertForbidden();

        // Fresh: the instance above carries the role's permissions as loaded.
        $this->grant('admin', []);
        $this->actingAs($moderator->fresh())->get(route('admin.point_account.index'))->assertForbidden();
    }

    #[Test]
    public function a_laundry_never_reads_the_customers_points_even_when_granted(): void
    {
        $customer = $this->customer();
        $this->points->credit($customer, 30, PointReason::Adjustment);
        $owner = $this->laundryWithOwner('A', '+201011110001', '+201011110002')['owner'];
        $this->grant('laundry_owner', ['point_account.view', 'point_account.update']);

        $this->actingAs($owner)->get(route('admin.point_account.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('admin.point_account.show', $customer->id))->assertForbidden();
        $this->actingAs($owner)
            ->post(route('admin.point_account.adjust', $customer->id), [
                'direction' => 'credit', 'points' => 5, 'note' => 'x',
            ])->assertForbidden();

        // And the export is empty for them.
        $this->actingAs($owner);
        $this->assertSame(0, app(PointAccountSheet::class)->query()->count());
        $this->assertSame(30, $this->points->available($customer));

        // Nor does the customer page show them the balance, even with
        // user.view as well (found by the review workflow).
        $this->grant('laundry_owner', ['user.view', 'point_account.view']);
        // The balance button itself: the bare ledger URL is also the page's
        // «Back» link (URL::previous(), after the 403 above), and its icon is
        // the sidebar's too.
        $button = route('admin.point_account.show', $customer->id).'" class="btn-quiet"';

        $this->actingAs($owner->fresh())
            ->get(route('admin.user.show', $customer->id))
            ->assertOk()
            ->assertDontSee($button, false)
            ->assertDontSee('30 points');

        // While the platform's own account still sees it.
        $this->actingAs($this->superAdmin())
            ->get(route('admin.user.show', $customer->id))
            ->assertOk()
            ->assertSee($button, false)
            ->assertSee('30 points');
    }

    #[Test]
    public function the_sidebar_has_the_screen(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.point_account.index'))
            ->assertOk()
            ->assertSee(route('admin.point_account.index'), false);
    }

    #[Test]
    public function the_settings_screen_has_a_points_tab_that_saves(): void
    {
        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.generalSetting.viewGeneralSetting'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="settings-tab-points"', $html);
        $pane = substr($html, strpos($html, 'id="settings-points"'));

        foreach (['Points_Enabled', 'Points_Per_Order', 'Point_Value', 'Points_Per_Hanger', 'Max_Hangers_Per_Visit',
            'Points_Min_Redeem', 'Points_Max_Redeem_Percent', 'Points_Min_Order_To_Earn', 'Points_Expiry_Days',
            'Points_Expiry_Warning_Days'] as $key) {
            $this->assertStringContainsString('name="'.$key.'"', $pane, $key);
        }

        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updateGeneralSetting'), [
                'Points_Enabled' => '1', 'Points_Per_Order' => '15', 'Point_Value' => '0.5', 'Points_Expiry_Days' => '90',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('15', Setting::where('key', 'Points_Per_Order')->value('value'));
        $this->assertSame('0.5', Setting::where('key', 'Point_Value')->value('value'));
        $this->assertSame('90', Setting::where('key', 'Points_Expiry_Days')->value('value'));
    }

    #[Test]
    public function a_point_worth_nothing_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updateGeneralSetting'), ['Point_Value' => '0'])
            ->assertSessionHasErrors('Point_Value');

        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updateGeneralSetting'), ['Points_Max_Redeem_Percent' => '150'])
            ->assertSessionHasErrors('Points_Max_Redeem_Percent');
    }

    #[Test]
    public function the_order_screen_shows_the_points_discount_and_what_the_order_earns(): void
    {
        $geo = $this->seedGeo();
        $catalog = $this->seedCatalog();
        $customer = $this->customer();
        $laundry = $this->laundryWithOwner('A', '+201011110001', '+201011110002')['laundry'];

        $order = Order::withoutGlobalScopes()->create([
            'code' => Order::generateCode(),
            'user_id' => $customer->id,
            'laundry_id' => $laundry->id,
            'service_id' => $catalog['service']->id,
            'status' => 'awaiting_pickup',
            'pickup_address_id' => $this->addressFor($customer, $geo['zones'][0])->id,
            'delivery_address_id' => $this->addressFor($customer, $geo['zones'][0])->id,
            'estimated_subtotal' => 51,
            'delivery_fee' => 20,
            'points_used' => 30,
            'points_discount' => 30,
            'points_to_earn' => 10,
            'estimated_total' => 41,
            'qr_token' => Order::generateQrToken(),
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertSee(__('Points discount'))
            ->assertSee(__('Points for this order'))
            ->assertSee(__('Paid when the order completes'));
    }

    #[Test]
    public function the_finance_page_counts_the_points_still_owed(): void
    {
        Setting::updateOrCreate(['key' => 'Point_Value'], ['value' => '2']);
        Cache::flush();
        $this->points->credit($this->customer(), 40, PointReason::Adjustment);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.finance.index'))
            ->assertOk()
            ->assertSee(__('Customer points not spent yet'))
            ->assertSee(moneyFormat(80));
    }
}
