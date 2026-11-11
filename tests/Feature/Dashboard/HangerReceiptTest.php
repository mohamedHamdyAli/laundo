<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderService;
use App\Modules\Setting\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «تأكيد استلام من المغسلة لعدد الشماعات» — the client's sheet, item 11
 * (2026-11-08): the laundry confirms the hangers a driver collected, and the
 * customer's hanger points follow its count.
 */
class HangerReceiptTest extends TestCase
{
    use RefreshDatabase;

    private array $geo;

    private array $catalog;

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

        foreach (['Points_Enabled' => '1', 'Points_Per_Hanger' => '1', 'Max_Hangers_Per_Visit' => '20'] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Cache::flush();
    }

    #[Test]
    public function the_laundry_confirms_its_own_hangers_from_the_order_screen(): void
    {
        [$order, $leg] = $this->withHangers(5);

        $this->actingAs($this->tenant['owner'])->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertSee('data-hangers-form="'.$leg->id.'"', false);

        $this->actingAs($this->tenant['owner'])
            ->post(route('admin.order.tasks.hangers', $leg->id), ['hangers_confirmed' => 4])
            ->assertRedirect()
            ->assertSessionHas('success');

        $leg->refresh();
        $this->assertSame(4, $leg->hangers_confirmed);
        $this->assertNotNull($leg->hangers_confirmed_at);
        $this->assertSame($this->tenant['owner']->id, $leg->hangers_confirmed_by);

        // Paid on the laundry's count.
        $this->assertDatabaseHas('point_transactions', [
            'user_id' => $order->user_id, 'reason' => 'hangers_returned', 'points' => 4,
        ]);

        // Shown as confirmed, without a second form.
        $this->actingAs($this->tenant['owner'])->get(route('admin.order.show', $order->id))
            ->assertSee('data-hangers-confirmed="'.$leg->id.'"', false)
            ->assertDontSee('data-hangers-form="'.$leg->id.'"', false);

        // And a second confirmation is refused.
        $this->actingAs($this->tenant['owner'])
            ->post(route('admin.order.tasks.hangers', $leg->id), ['hangers_confirmed' => 5])
            ->assertSessionHas('error');
        $this->assertSame(4, $leg->fresh()->hangers_confirmed);
    }

    #[Test]
    public function the_laundry_cannot_confirm_more_than_the_driver_collected(): void
    {
        // The points are the platform's money (code review, 2026-11-08).
        [$order, $leg] = $this->withHangers(1);

        $this->actingAs($this->tenant['owner'])
            ->post(route('admin.order.tasks.hangers', $leg->id), ['hangers_confirmed' => 20])
            ->assertSessionHas('error');

        $this->assertNull($leg->fresh()->hangers_confirmed_at);
        $this->assertDatabaseMissing('point_transactions', ['user_id' => $order->user_id, 'reason' => 'hangers_returned']);
    }

    #[Test]
    public function another_laundry_cannot_reach_them(): void
    {
        [, $leg] = $this->withHangers(5);
        $other = $this->laundryWithOwner('B', '+201022220001', '+201022220002');

        $this->actingAs($other['owner'])
            ->post(route('admin.order.tasks.hangers', $leg->id), ['hangers_confirmed' => 5])
            ->assertNotFound();

        $this->assertNull($leg->fresh()->hangers_confirmed_at);
    }

    #[Test]
    public function they_are_counted_in_the_list_filter_until_confirmed(): void
    {
        [$order, $leg] = $this->withHangers(2);

        $listed = fn () => $this->actingAs($this->tenant['owner'])
            ->getJson(route('admin.order.search', ['query' => '', 'status' => 'hangers_to_confirm']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('table');

        $this->assertStringContainsString('#'.$order->code, $listed());

        $this->actingAs($this->tenant['owner'])
            ->post(route('admin.order.tasks.hangers', $leg->id), ['hangers_confirmed' => 2]);

        $this->assertStringNotContainsString('#'.$order->code, $listed());
    }

    #[Test]
    public function both_apps_are_told_what_the_laundry_confirmed(): void
    {
        // The apps had the driver's count only, so neither could say why the
        // customer's points were not there yet, or were fewer.
        [$order, $leg] = $this->withHangers(5);
        $driver = $this->driverUser('+201044440001');
        $leg->forceFill(['driver_id' => $driver->id])->save();

        $customerSees = function () use ($order) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($order->customer);

            return $this->getJson("/api/v1/orders/{$order->id}", $this->apiHeaders())->assertOk()->json('data.points');
        };
        $driverSees = function () use ($driver, $leg) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($driver);

            return $this->getJson("/api/v1/driver/tasks/{$leg->id}", $this->apiHeaders())->assertOk()->json('data');
        };

        $points = $customerSees();
        $this->assertSame([5, 0, 5], [$points['hangers_returned'], $points['hangers_confirmed'], $points['hangers_awaiting_laundry']]);
        $this->assertNull($driverSees()['hangers_confirmed']);

        $this->actingAs($this->tenant['owner'])
            ->post(route('admin.order.tasks.hangers', $leg->id), ['hangers_confirmed' => 3]);

        $points = $customerSees();
        $this->assertSame([5, 3, 0], [$points['hangers_returned'], $points['hangers_confirmed'], $points['hangers_awaiting_laundry']]);
        $this->assertSame(3, $driverSees()['hangers_confirmed']);
    }

    #[Test]
    public function the_order_screen_asks_no_ordered_aggregate(): void
    {
        // Production is MariaDB: under ONLY_FULL_GROUP_BY it refuses an ORDER BY
        // in an aggregate with no GROUP BY (error 1140), which SQLite and MySQL 8
        // accept. `hangers()` inherited `tasks()`'s `orderBy('sequence')` and
        // every `GET /orders/{id}` answered 500 on the live site (2026-11-11).
        // SQLite cannot refuse it, so the SQL itself is checked.
        [$order] = $this->withHangers(5);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        Sanctum::actingAs($order->customer);
        $this->getJson("/api/v1/orders/{$order->id}", $this->apiHeaders())->assertOk();

        $ordered = array_filter($queries, fn (string $sql) => preg_match('/\b(sum|count|min|max|avg)\s*\(/', $sql)
            && str_contains($sql, ' order by ')
            && ! str_contains($sql, ' group by '));

        $this->assertSame([], array_values($ordered));
    }

    /**
     * An order whose pickup is done with `$hangers` handed back.
     *
     * @return array{0: Order, 1: OrderTask}
     */
    private function withHangers(int $hangers): array
    {
        $customer = $this->customer();
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $leg = OrderTask::where('order_id', $order->id)->where('type', TaskType::PickupFromCustomer->value)->firstOrFail();
        $leg->forceFill(['status' => TaskStatus::Completed, 'completed_at' => now(), 'hangers_returned' => $hangers])->save();

        return [$order, $leg->fresh()];
    }
}
