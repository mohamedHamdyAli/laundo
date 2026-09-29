<?php

namespace Tests\Feature\Dashboard;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Modules\Complaint\Models\Complaint;
use App\Modules\Driver\Models\DriverProfile;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderStatusLog;
use App\Modules\Order\Services\OrderHistory;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Setting\Models\Setting;
use App\Services\ActivityPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «نعرف مين اللي عمل أي تغيير في أي أكشن» — the activity log, and the history
 * on the order's screen that reads it.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);
    }

    private function logsFor(object $model): Collection
    {
        return ActivityLog::where('subject_type', $model->getMorphClass())
            ->where('subject_id', $model->getKey())
            ->orderBy('id')
            ->get();
    }

    #[Test]
    public function a_dashboard_edit_records_who_where_and_each_field_before_and_after(): void
    {
        $city = $this->geo['city'];
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put(route('admin.city.update', $city->id), [
            'name' => ['en' => 'Cairo Renamed', 'ar' => $city->name->ar],
            'country_id' => $city->country_id,
            'status' => 'inactive',
        ])->assertRedirect();

        $log = $this->logsFor($city)->last();

        $this->assertSame('updated', $log->event);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('Super', $log->actor_name);
        $this->assertSame('super_admin', $log->actor_role);
        $this->assertSame(ActivityLog::DASHBOARD, $log->source);
        $this->assertSame('admin.city.update', $log->route);
        $this->assertSame(['old' => 'active', 'new' => 'inactive'], $log->diff['status']);
        $this->assertArrayHasKey('name', $log->diff);
        // A field nobody touched is not in the diff.
        $this->assertArrayNotHasKey('country_id', $log->diff);
    }

    #[Test]
    public function a_change_from_an_app_is_recorded_with_its_actor_and_source(): void
    {
        $customer = $this->customer('+201099880011');
        $address = $this->addressFor($customer, $this->geo['zones'][0]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertCreated();

        $order = Order::withoutGlobalScopes()->firstOrFail();
        $created = $this->logsFor($order)->firstWhere('event', 'created');

        $this->assertNotNull($created);
        $this->assertSame(ActivityLog::API, $created->source);
        $this->assertSame($customer->id, $created->user_id);
        $this->assertSame($order->id, $created->order_id);
        $this->assertSame('#'.$order->code, $created->subject_label);
    }

    #[Test]
    public function a_secret_is_recorded_as_changed_never_by_its_value(): void
    {
        $user = $this->customer('+201099880011');
        $user->update(['password' => 'a-new-secret-password']);

        $diff = $this->logsFor($user)->last()->diff;
        $this->assertSame('••••', $diff['password']['new']);
        $this->assertStringNotContainsString('a-new-secret-password', json_encode(ActivityLog::all()->toArray()));

        $setting = Setting::create(['key' => 'Google_Maps_Key', 'value' => 'AIza-real-key']);
        $this->assertSame('••••', $this->logsFor($setting)->last()->diff['value']['new']);
    }

    #[Test]
    public function a_driver_reporting_a_position_is_not_a_change_worth_a_row(): void
    {
        $driver = $this->driverUser();
        $profile = DriverProfile::where('user_id', $driver->id)->firstOrFail();
        $before = ActivityLog::count();

        $profile->forceFill(['last_lat' => 30.1, 'last_lng' => 31.2, 'located_at' => now()])->save();

        $this->assertSame($before, ActivityLog::count());

        // Nor is an OTP sent, or the remember-me token cycled at a sign-out.
        $driver->forceFill(['otp' => '123456', 'otp_expires_at' => now()->addMinutes(5), 'remember_token' => 'x'])->save();

        $this->assertSame($before, ActivityLog::count());
    }

    #[Test]
    public function the_status_log_is_not_recorded_twice(): void
    {
        $this->assertSame(0, ActivityLog::where('subject_type', (new OrderStatusLog)->getMorphClass())->count());
    }

    #[Test]
    public function the_order_screen_shows_everything_that_happened_and_who_did_it(): void
    {
        $customer = $this->customer('+201099880011');
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        // The laundry counts five pieces instead of two.
        $this->actingAs($this->tenant['owner']->fresh());
        app(OrderReviewService::class)->review(
            $order->fresh(), [['item_id' => $this->catalog['items'][0]->id, 'qty' => 5]], null, $this->tenant['owner']
        );

        $history = collect(app(OrderHistory::class)->for($order->fresh()));

        // Its statuses…
        $this->assertTrue($history->contains(fn ($e) => $e['kind'] === 'status' && $e['title'] === __(OrderStatus::Reviewed->label())));
        // …and the change the review made, by the laundry's owner, field by field.
        $review = $history->first(fn ($e) => $e['kind'] === 'updated' && $e['actor'] === $this->tenant['owner']->name);
        $this->assertNotNull($review);
        $this->assertNotEmpty($review['fields']);
        // The order's own status is not repeated inside a change.
        $this->assertFalse($history->contains(fn ($e) => $e['kind'] !== 'status' && collect($e['fields'])->contains('key', 'status')
            && str_starts_with($e['title'], __('Changed').' '.__('Order'))));

        // And the screen renders it for whoever may see the order.
        $this->grant('laundry_owner', ['order.view']);
        $this->actingAs($this->tenant['owner']->fresh())
            ->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertSee(__('History'), false)
            ->assertSee($this->tenant['owner']->name, false);
    }

    #[Test]
    public function a_change_naming_another_record_shows_it_by_name(): void
    {
        $customer = $this->customer('+201099880011');
        $address = $this->addressFor($customer, $this->geo['zones'][0]);
        $order = app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $pieces = collect(app(OrderHistory::class)->for($order->fresh()))
            ->first(fn ($e) => $e['kind'] === 'created' && $e['title'] === __('Added').' '.__('Piece'));

        $this->assertNotNull($pieces);
        // «Item: Shirt», never «Item: 1».
        $item = collect($pieces['fields'])->firstWhere('label', __('validation.attributes.item_id'));
        $this->assertNotNull($item);
        $this->assertSame($this->catalog['items'][0]->name->en, $item['new']);
    }

    #[Test]
    public function a_row_reads_as_a_sentence_the_owner_understands(): void
    {
        $admin = $this->superAdmin();
        $city = $this->geo['city'];

        // Changed by somebody on the English panel…
        $this->actingAs($admin)->put(route('admin.city.update', $city->id), [
            'name' => ['en' => $city->name->en, 'ar' => $city->name->ar],
            'country_id' => $city->country_id,
            'status' => 'inactive',
        ])->assertRedirect();

        // …read by somebody on the Arabic one: the city's Arabic name.
        app()->setLocale('ar');
        $row = app(ActivityPresenter::class)->present([$this->logsFor($city)->last()])[0];

        // «تعديل المدينة «القاهرة»» — no class name, no id.
        $this->assertSame(__('Changed').' '.__('City').' «'.$city->name->ar.'»', $row['title']);
        $this->assertStringNotContainsString('#', $row['title']);
        // Who by their role, where from by the screen's own name.
        $this->assertSame(__('Platform manager'), $row['role']);
        $this->assertSame(__('Control panel').' — '.__(config('menu.titles.city')), $row['where']);
        // The status in words, and nothing technical in the details.
        $status = collect($row['fields'])->firstWhere('key', 'status');
        $this->assertSame([__('Active'), __('Inactive')], [$status['old'], $status['new']]);
        $this->assertNotContains('id', collect($row['fields'])->pluck('key'));
        $this->assertNotContains('updated_at', collect($row['fields'])->pluck('key'));
    }

    #[Test]
    public function a_password_change_says_it_changed_and_nothing_more(): void
    {
        $user = $this->customer('+201099880011');
        $user->update(['password' => 'a-new-secret-password']);

        $row = app(ActivityPresenter::class)->present([$this->logsFor($user)->last()])[0];
        $password = collect($row['fields'])->firstWhere('key', 'password');

        $this->assertSame(__('Changed — hidden for security'), $password['new']);
        $this->assertSame('', $password['old']);
    }

    #[Test]
    public function a_panel_sign_in_is_from_the_control_panel_not_the_website(): void
    {
        $admin = $this->superAdmin();
        $this->post(route('login'), ['email' => $admin->email, 'password' => 'password']);

        $log = ActivityLog::where('event', 'login')->where('user_id', $admin->id)->firstOrFail();
        $this->assertSame(ActivityLog::DASHBOARD, $log->source);
    }

    #[Test]
    public function seeded_permissions_are_neither_recorded_nor_listed(): void
    {
        $permission = Permission::create(['name' => 'Probe View', 'slug' => 'probe.view', 'model' => 'probe', 'action' => 'view']);
        $this->assertCount(0, $this->logsFor($permission));

        // A row written before Permission joined the excluded list stays out of the screen.
        ActivityLog::create(['source' => 'system', 'event' => 'created', 'subject_type' => $permission->getMorphClass(), 'subject_id' => $permission->id, 'subject_label' => 'Probe View']);

        $this->actingAs($this->superAdmin())->get(route('admin.activity_log.index'))
            ->assertOk()
            ->assertDontSee('Probe View', false);
    }

    #[Test]
    public function the_order_history_keeps_driver_pay_from_a_laundry_owner(): void
    {
        $customer = $this->customer('+201099880011');
        $address = $this->addressFor($customer, $this->geo['zones'][0]);
        $order = app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        ActivityLog::create([
            'source' => 'system', 'event' => 'created', 'order_id' => $order->id,
            'subject_type' => (new DriverEarning)->getMorphClass(), 'subject_id' => 1,
            'diff' => ['amount' => ['old' => null, 'new' => '15.00']],
        ]);

        $earning = fn ($e) => $e['title'] === __('Added').' '.__('Driver earning');

        $this->grant('laundry_owner', ['order.view']);
        $this->actingAs($this->tenant['owner']->fresh());
        $this->assertFalse(collect(app(OrderHistory::class)->for($order->fresh()))->contains($earning));

        $this->actingAs($this->superAdmin());
        $this->assertTrue(collect(app(OrderHistory::class)->for($order->fresh()))->contains($earning));
    }

    #[Test]
    public function the_order_history_keeps_a_complaint_and_its_internal_note_from_the_laundry(): void
    {
        $customer = $this->customer('+201099880011');
        $address = $this->addressFor($customer, $this->geo['zones'][0]);
        $order = app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        // Filed against the order, then noted by the platform.
        $complaint = Complaint::withoutGlobalScopes()->forceCreate([
            'reference' => 'CMP-TEST-1', 'user_id' => $customer->id, 'order_id' => $order->id, 'laundry_id' => $order->laundry_id,
            'category' => 'other', 'body' => 'The laundry lost my shirt', 'status' => 'open',
        ]);
        $complaint->forceFill(['internal_note' => 'Considering suspending this laundry'])->save();

        $shows = fn () => json_encode(app(OrderHistory::class)->for($order->fresh()), JSON_UNESCAPED_UNICODE);

        // The laundry sees its order's history — and neither the complaint nor the note.
        $this->grant('laundry_owner', ['order.view']);
        $this->actingAs($this->tenant['owner']->fresh());
        $this->assertStringNotContainsString('lost my shirt', $shows());
        $this->assertStringNotContainsString('suspending', $shows());

        // Whoever may read complaints anyway sees both.
        $this->actingAs($this->superAdmin());
        $this->assertStringContainsString('lost my shirt', $shows());
        $this->assertStringContainsString('suspending', $shows());
    }

    #[Test]
    public function a_kind_of_record_nobody_named_shows_only_to_whoever_may_read_the_whole_log(): void
    {
        $customer = $this->customer('+201099880011');
        $address = $this->addressFor($customer, $this->geo['zones'][0]);
        $order = app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        ActivityLog::create([
            'source' => 'system', 'event' => 'created', 'order_id' => $order->id,
            'subject_type' => 'App\\Modules\\Future\\Models\\SomethingNew', 'subject_id' => 1, 'subject_label' => 'Future row',
        ]);

        $this->grant('laundry_owner', ['order.view']);
        $this->actingAs($this->tenant['owner']->fresh());
        $this->assertStringNotContainsString('Future row', json_encode(app(OrderHistory::class)->for($order->fresh())));
    }

    #[Test]
    public function the_log_screen_is_the_platforms_and_filters(): void
    {
        $this->grant('laundry_owner', ['order.view']);
        $this->actingAs($this->tenant['owner']->fresh())->get(route('admin.activity_log.index'))->assertForbidden();

        $admin = $this->superAdmin();
        $this->geo['city']->update(['status' => 'inactive']);

        $this->actingAs($admin)->get(route('admin.activity_log.index'))->assertOk()->assertSee(__('Activity Log'), false);

        $response = $this->actingAs($admin)->getJson(
            route('admin.activity_log.search', ['event' => 'updated', 'query' => $this->geo['city']->name->en]),
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertOk();

        $this->assertStringContainsString($this->geo['city']->name->en, (string) $response->json('table'));
    }

    #[Test]
    public function a_panel_sign_in_is_recorded(): void
    {
        $admin = $this->superAdmin();

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'password']);

        $this->assertTrue(ActivityLog::where('event', 'login')->where('user_id', $admin->id)->exists());
    }

    #[Test]
    public function rows_older_than_six_months_are_pruned_and_status_logs_are_not(): void
    {
        $old = ActivityLog::create(['source' => 'system', 'event' => 'updated', 'created_at' => now()->subDays(200)]);
        $recent = ActivityLog::create(['source' => 'system', 'event' => 'updated', 'created_at' => now()->subDays(10)]);

        $this->artisan('laundo:prune')->assertSuccessful();

        $this->assertNull(ActivityLog::find($old->id));
        $this->assertNotNull(ActivityLog::find($recent->id));
    }
}
