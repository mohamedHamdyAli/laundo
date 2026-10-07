<?php

namespace Tests\Feature\Api;

use App\Modules\Complaint\Enums\ComplaintStatus;
use App\Modules\Complaint\Models\Complaint;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderService;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «شكوى» on a card under «طلباتي»: `has_complaint`, and one complaint per order
 * (the app team's ask and the owner's rule, 2026-10-07).
 *
 * The app greys the button out off the flag; the server refuses a second
 * complaint whatever the app shows. Both read one definition
 * (`Complaint::scopeUsingUpTheOrder()`), and the cases below that matter most
 * are the ones where the two could disagree: somebody else's complaint about
 * the same order, and a «تواصل معنا» message.
 */
class OrderComplaintFlagTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private array $geo;

    private array $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->customer = $this->customer('+201055550101');
    }

    private function order(?User $for = null): Order
    {
        $owner = $for ?? $this->customer;

        return app(OrderService::class)->place($owner, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->addressFor($owner, $this->geo['zones'][0])->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ]);
    }

    private function complain(User $as, ?Order $order, string $category = 'not_clean'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as)->postJson('/api/v1/complaints', array_filter([
            'category' => $category,
            'body' => 'القميص رجع مش نضيف',
            'order_id' => $order?->id,
        ]));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function list(string $query = ''): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->customer);

        return $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/orders'.$query)
            ->assertOk()
            ->json('data');
    }

    // --------------------------------------------------------- the flag

    #[Test]
    public function every_order_carries_the_flag_and_it_is_false_until_they_complain(): void
    {
        $order = $this->order();

        $row = $this->list()[0];

        // Always there, always a bool — never null, never missing.
        $this->assertArrayHasKey('has_complaint', $row);
        $this->assertFalse($row['has_complaint']);

        $this->complain($this->customer, $order)->assertCreated();

        $this->assertTrue($this->list()[0]['has_complaint']);
    }

    #[Test]
    public function the_completed_tab_the_list_and_the_order_all_say_the_same(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => OrderStatus::Completed->value])->save();
        $this->complain($this->customer, $order)->assertCreated();

        $this->assertTrue($this->list('?tab=completed')[0]['has_complaint']);
        $this->assertTrue($this->list()[0]['has_complaint']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->customer);
        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.has_complaint', true);
    }

    #[Test]
    public function a_new_order_is_answered_with_the_flag_too(): void
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);
        Sanctum::actingAs($this->customer);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/orders', [
                'service_id' => $this->catalog['service']->id,
                'pickup_address_id' => $address->id,
                'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
                'accepts_review_terms' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.has_complaint', false);
    }

    #[Test]
    public function the_flag_is_per_order_not_per_customer(): void
    {
        $complained = $this->order();
        $other = $this->order();
        $this->complain($this->customer, $complained)->assertCreated();

        $flags = collect($this->list())->pluck('has_complaint', 'id');

        $this->assertTrue($flags[$complained->id]);
        $this->assertFalse($flags[$other->id]);
    }

    #[Test]
    public function a_drivers_complaint_about_the_same_order_does_not_grey_out_the_customers_button(): void
    {
        $order = $this->order();
        $driver = $this->driverUser('+201066660101');

        OrderTask::where('order_id', $order->id)
            ->where('type', TaskType::PickupFromCustomer->value)
            ->update(['driver_id' => $driver->id, 'status' => TaskStatus::Completed->value]);

        $this->complain($driver, $order, 'customer_conduct')->assertCreated();

        $this->assertFalse($this->list()[0]['has_complaint']);

        // And it did not use up the customer's one complaint either.
        $this->complain($this->customer, $order)->assertCreated();
        $this->assertTrue($this->list()[0]['has_complaint']);
    }

    #[Test]
    public function a_contact_us_message_naming_the_order_is_not_a_complaint(): void
    {
        $order = $this->order();

        $this->complain($this->customer, $order, 'support_request')->assertCreated();

        $this->assertFalse($this->list()[0]['has_complaint']);
        $this->complain($this->customer, $order)->assertCreated();
    }

    /**
     * Asked in the list's own query. One order or five, the same number of
     * queries — a flag that cost one query per card would be invisible until a
     * customer with a page of orders opened the app.
     */
    #[Test]
    public function the_flag_costs_no_query_per_order(): void
    {
        $this->complain($this->customer, $this->order())->assertCreated();

        $count = function (): int {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($this->customer);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->withHeaders($this->apiHeaders())->getJson('/api/v1/orders')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // Once to warm the caches: the first request also reads the default
        // language, which is remembered after that, and would make one order
        // look dearer than five.
        $count();
        $one = $count();

        for ($i = 0; $i < 4; $i++) {
            $this->complain($this->customer, $this->order())->assertCreated();
        }

        $this->assertSame($one, $count(), 'has_complaint must not cost a query per order');
    }

    // ------------------------------------------------------- one per order

    #[Test]
    public function a_second_complaint_about_the_same_order_is_refused(): void
    {
        $order = $this->order();
        $this->complain($this->customer, $order)->assertCreated();

        $this->complain($this->customer, $order, 'damaged_item')
            ->assertStatus(422)
            ->assertJsonPath('key', 'validation_error')
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('msg', __('You have already sent a complaint about this order.'))
            ->assertJsonStructure(['errors' => ['order_id']]);

        $this->assertSame(1, Complaint::where('order_id', $order->id)->count());
    }

    #[Test]
    public function it_stays_refused_after_the_first_one_is_closed(): void
    {
        // The owner's choice: one per order for good. A problem that comes back
        // after a complaint is closed is a phone call.
        $order = $this->order();
        $this->complain($this->customer, $order)->assertCreated();

        foreach ([ComplaintStatus::Resolved, ComplaintStatus::Closed] as $state) {
            Complaint::where('order_id', $order->id)->update(['status' => $state->value]);

            $this->complain($this->customer, $order)->assertStatus(422);
        }

        $this->assertSame(1, Complaint::where('order_id', $order->id)->count());
    }

    #[Test]
    public function a_driver_can_report_two_problems_on_one_order(): void
    {
        // The rule is the customer's «شكوى» button. A driver with both doorstep
        // legs can meet two separate problems days apart, and the driver app
        // has no flag that would explain a refusal.
        $order = $this->order();
        $driver = $this->driverUser('+201066660102');

        OrderTask::where('order_id', $order->id)
            ->whereIn('type', [TaskType::PickupFromCustomer->value, TaskType::DeliverToCustomer->value])
            ->update(['driver_id' => $driver->id]);

        $this->complain($driver, $order, 'customer_conduct')->assertCreated();
        $this->complain($driver, $order, 'customer_conduct')->assertCreated();

        $this->assertSame(2, Complaint::where('order_id', $order->id)->where('user_id', $driver->id)->count());
    }

    #[Test]
    public function complaints_about_no_order_in_particular_are_never_refused(): void
    {
        $this->complain($this->customer, null, 'app_problem')->assertCreated();
        $this->complain($this->customer, null, 'app_problem')->assertCreated();

        $this->assertSame(2, Complaint::whereNull('order_id')->count());
    }
}
