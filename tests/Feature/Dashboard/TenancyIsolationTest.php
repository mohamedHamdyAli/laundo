<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\LaundryStaff\Models\LaundryStaff;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\orderCrudService;
use App\Modules\Order\Services\OrderService;
use App\Modules\User\Models\User;
use App\Support\LaundryContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1 — the security core. If isolation is wrong here it is wrong everywhere,
 * because every later phase stores a laundry_id.
 */
class TenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Laundry $laundryA;

    private Laundry $laundryB;

    private User $ownerA;

    private User $ownerB;

    private User $admin;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        $a = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $b = $this->laundryWithOwner('B', '+201022220001', '+201022220002');

        $this->laundryA = $a['laundry'];
        $this->ownerA = $a['owner'];
        $this->laundryB = $b['laundry'];
        $this->ownerB = $b['owner'];
        $this->admin = $this->superAdmin();
    }

    public function test_console_and_unauthenticated_code_sees_every_row(): void
    {
        // Seeders, queue workers and console commands must not be filtered, or
        // background work would silently operate on a subset.
        $this->assertNull(LaundryContext::currentId());
        $this->assertSame(2, Laundry::count());
    }

    public function test_a_super_admin_is_never_scoped(): void
    {
        $this->actingAs($this->admin);

        $this->assertNull(LaundryContext::currentId());
        $this->assertSame(2, Laundry::count());
    }

    /**
     * A real order on laundry A, placed the way a customer places one.
     *
     * Built through `OrderService` rather than by hand: an order has a dozen
     * non-null columns and a fixture that lists them drifts from the schema the
     * first time one is added.
     */
    private function placeOrderForA(): Order
    {
        $customer = $this->customer('+201099990001');
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

        $this->cover($this->laundryA, $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $order = app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ]);

        return $order->fresh();
    }

    public function test_a_laundry_cannot_touch_a_drivers_leg(): void
    {
        // Which driver carries which order is the platform's to decide. A
        // laundry holds `order.update` so it can review and price its own
        // orders, and that quietly reached the driver routes too — assigning a
        // leg, taking one off him, and re-running the dispatch sweep.
        $order = $this->placeOrderForA();
        $leg = $order->tasks()->first();

        $this->assertNotNull($leg, 'placing an order generates its legs');

        $this->actingAs($this->ownerA);

        foreach ([
            route('admin.order.tasks.assign', $leg->id),
            route('admin.order.tasks.release', $leg->id),
            route('admin.order.tasks.dispatch', $order->id),
            route('admin.order.tasks.generate', $order->id),
        ] as $url) {
            $this->post($url, ['driver_id' => 1])->assertForbidden();
        }

        $this->post(route('admin.dispatch.redispatch'))->assertForbidden();
    }

    public function test_a_laundry_can_still_watch_its_own_legs(): void
    {
        // Read stays. A laundry seeing that a driver is on the way to it is
        // useful and harms nobody — what it may not do is choose him.
        $this->grant('laundry_owner', ['order_task.view']);

        $this->actingAs($this->ownerA);

        $this->get(route('admin.dispatch.index'))->assertOk();
    }

    public function test_the_driver_supervisor_can_do_the_work_the_role_exists_for(): void
    {
        // The other half of the same move. This role holds `order_task.update`
        // and not `order.update`, so under the old gate it could open the
        // dispatch board and act on nothing on it.
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $supervisor = User::create([
            'name' => 'Supervisor',
            'phone' => '+201088880001',
            'email' => 'supervisor@test.local',
            'password' => 'password',
            'status' => 'active',
            'role_id' => Role::where('slug', 'driver_supervisor')->value('id'),
        ]);

        $this->actingAs($supervisor);

        $this->get(route('admin.dispatch.index'))->assertOk();
        // Not forbidden. It may fail validation on a driver that does not fit
        // the leg, which is a different answer entirely.
        $this->post(route('admin.dispatch.redispatch'))->assertRedirect();
    }

    public function test_a_laundry_cannot_hand_its_own_order_to_another_laundry(): void
    {
        // The scope stops an owner *seeing* somebody else's orders, and that was
        // mistaken for a gate on routing. It never stopped them pushing their
        // own order onto a competitor — declining the work and choosing who got
        // it — on a screen that also named every other laundry with its distance
        // and how full it was.
        $order = $this->placeOrderForA();

        $this->actingAs($this->ownerA);

        $this->expectExceptionMessage('not_yours_to_route');

        app(orderCrudService::class)
            ->assign($order->id, $this->laundryB->id, $this->ownerA);
    }

    public function test_the_platform_can_still_route_an_order(): void
    {
        // The other half: the refusal is about who is asking, not about the
        // action. A super admin is unscoped and must still be able to assign.
        $order = $this->placeOrderForA();

        $this->actingAs($this->admin);
        $this->assertNull(LaundryContext::currentId());

        app(orderCrudService::class)
            ->assign($order->id, $this->laundryB->id, $this->admin);

        $this->assertSame(
            $this->laundryB->id,
            Order::withoutGlobalScopes()->find($order->id)->laundry_id
        );
    }

    public function test_a_laundry_owner_sees_only_their_own_laundry(): void
    {
        $this->actingAs($this->ownerA);

        $this->assertSame($this->laundryA->id, LaundryContext::currentId());
        $this->assertSame(1, Laundry::count());
        $this->assertSame($this->laundryA->id, Laundry::first()->id);
    }

    public function test_a_laundry_owner_cannot_load_another_laundry_by_id(): void
    {
        $this->actingAs($this->ownerA);

        // The scope applies to find() too, which is what stops URL guessing.
        $this->assertNull(Laundry::find($this->laundryB->id));
    }

    public function test_the_laundry_index_page_lists_only_the_actors_own(): void
    {
        $this->actingAs($this->ownerA)
            ->get('/admin/laundry')
            ->assertOk()
            ->assertSee('Laundry A')
            ->assertDontSee('Laundry B');
    }

    public function test_a_cross_tenant_url_is_not_found(): void
    {
        $this->actingAs($this->ownerA)->get("/admin/laundry/show/{$this->laundryB->id}")->assertNotFound();
        $this->actingAs($this->ownerA)->get("/admin/laundry/edit/{$this->laundryB->id}")->assertNotFound();
    }

    public function test_the_actors_own_record_is_reachable(): void
    {
        $this->actingAs($this->ownerA)->get("/admin/laundry/show/{$this->laundryA->id}")->assertOk();
    }

    public function test_staff_lists_are_scoped_to_the_tenant(): void
    {
        $this->actingAs($this->ownerA);
        $this->assertSame(1, LaundryStaff::count());
        $this->assertSame($this->ownerA->id, LaundryStaff::first()->id);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->ownerB);
        $this->assertSame(1, LaundryStaff::count());
        $this->assertSame($this->ownerB->id, LaundryStaff::first()->id);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin);
        $this->assertSame(2, LaundryStaff::count());
    }

    public function test_a_forged_laundry_id_on_create_is_overwritten(): void
    {
        // Reading is only half of isolation. Without the creating hook a tenant
        // could plant a row inside another tenant by posting its id.
        $this->actingAs($this->ownerA);

        $staff = LaundryStaff::create([
            'name' => 'Planted',
            'email' => 'planted@test.local',
            'phone' => '+201033330001',
            'password' => 'password',
            'status' => 'active',
            'role_id' => Role::where('slug', 'laundry_staff')->value('id'),
            'laundry_id' => $this->laundryB->id,
        ]);

        $this->assertSame(
            $this->laundryA->id,
            $staff->fresh()->laundry_id,
            'the forged laundry_id must be replaced with the actor own'
        );
    }

    public function test_a_customer_role_cannot_reach_the_dashboard(): void
    {
        $this->actingAs($this->customer('+201044440001'))
            ->get('/admin/home')
            ->assertForbidden();
    }

    public function test_a_laundry_role_can_reach_the_dashboard(): void
    {
        $this->actingAs($this->ownerA)->get('/admin/home')->assertOk();
    }

    public function test_a_laundry_owner_has_no_global_catalogue_permissions(): void
    {
        // Prices and the catalogue are global and belong to the super admin.
        foreach (['/admin/service', '/admin/item', '/admin/item-category', '/admin/pricing', '/admin/zone', '/admin/time-slot'] as $path) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($this->ownerA)->get($path)->assertForbidden();
        }
    }

    public function test_a_laundry_owner_reaches_their_own_scoped_pages(): void
    {
        foreach (['/admin/laundry', '/admin/laundry-staff', '/admin/laundry-service', '/admin/laundry-zone'] as $path) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($this->ownerA)->get($path)->assertOk();
        }
    }

    public function test_the_home_page_gives_a_laundry_only_its_own_half(): void
    {
        // This replaced a test of the old catalogue tiles, which were permission
        // gated so a laundry could not read a global count. The page is now an
        // operations screen, and the isolation question changed with it: what must
        // not reach a laundry is the dispatch and platform-money half.
        //
        // The reason it is withheld rather than filtered: tasks carry no
        // `laundry_id`, so `BelongsToLaundry` offers no protection over driver
        // figures at all.
        $operator = $this->actingAs($this->admin)->get('/admin/home')->assertOk();

        $this->assertFalse($operator->viewData('isLaundry'));
        $this->assertNotNull($operator->viewData('drivers'));

        $this->app['auth']->forgetGuards();

        $laundry = $this->actingAs($this->ownerA)->get('/admin/home')->assertOk();

        $this->assertTrue($laundry->viewData('isLaundry'));
        $this->assertArrayNotHasKey('drivers', $laundry->original->getData());
        $this->assertArrayNotHasKey('attention', $laundry->original->getData());
    }
}
