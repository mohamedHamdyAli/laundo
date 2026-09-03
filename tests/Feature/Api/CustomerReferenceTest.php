<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\User\Models\User;
use App\Modules\User\Services\CustomerReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «مرجع العميل C-882» and the ticket the driver prints.
 *
 * The reference is the one identifier on this platform that ends up on a physical
 * object, so the properties that matter are not the ones a normal id has: it must
 * be stable for life, it must never be reissued to somebody else, and it must be
 * handed out however the customer row came into being — because the failure mode
 * is a bag with nothing printed on it, discovered by a person holding the bag.
 */
class CustomerReferenceTest extends TestCase
{
    use RefreshDatabase;

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
    }

    // ------------------------------------------------------------ the reference

    #[Test]
    public function a_new_customer_is_given_one(): void
    {
        $customer = $this->customer('+201055550001');

        $this->assertSame('C-001', $customer->fresh()->customer_reference);
    }

    #[Test]
    public function they_are_sequential_over_customers_only(): void
    {
        $first = $this->customer('+201055550001');
        // Drivers and staff share this table, so the user id sequence skips.
        // Numbering off it would tell the 3rd customer they are the 12th.
        $this->driverUser('+201066660001');
        $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $second = $this->customer('+201055550002');

        $this->assertSame('C-001', $first->fresh()->customer_reference);
        $this->assertSame('C-002', $second->fresh()->customer_reference);
    }

    #[Test]
    public function nobody_but_a_customer_gets_one(): void
    {
        $this->assertNull($this->driverUser('+201066660001')->fresh()->customer_reference);
        $this->assertNull($this->superAdmin()->fresh()->customer_reference);
        $this->assertNull(
            $this->laundryWithOwner('A', '+201011110001', '+201011110002')['owner']->fresh()->customer_reference
        );
    }

    #[Test]
    public function registering_through_the_app_gets_one_too(): void
    {
        // Four paths make a customer — the app, the dashboard, the seeders and
        // the tests. A reference assigned at one of them is a bag with nothing
        // on it at the other three, which is why this lives on the model.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Nada',
            'phone' => '+201055559999',
            'password' => 'password',
            'password_confirmation' => 'password',
            'accepted_terms' => true,
        ])->assertSuccessful();

        $this->assertSame(
            'C-001',
            User::where('phone', '+201055559999')->value('customer_reference')
        );
    }

    #[Test]
    public function a_deleted_customer_does_not_hand_their_number_on(): void
    {
        $first = $this->customer('+201055550001');
        $this->customer('+201055550002');

        // The reference is printed on labels that outlive the account. Counting
        // rows instead of reading the highest would give C-002 to a third
        // customer and put two people's clothes under one number.
        $first->delete();

        $third = $this->customer('+201055550003');

        $this->assertSame('C-003', $third->fresh()->customer_reference);
    }

    #[Test]
    public function it_does_not_change_when_the_customer_is_edited(): void
    {
        $customer = $this->customer('+201055550001');
        $reference = $customer->fresh()->customer_reference;

        $customer->update(['name' => 'A New Name']);

        $this->assertSame($reference, $customer->fresh()->customer_reference);
    }

    #[Test]
    public function assigning_twice_is_a_no_op(): void
    {
        $customer = $this->customer('+201055550001');
        $reference = $customer->fresh()->customer_reference;

        CustomerReference::assign($customer->fresh());

        $this->assertSame($reference, $customer->fresh()->customer_reference);
        $this->assertSame(1, User::whereNotNull('customer_reference')->count());
    }

    #[Test]
    public function the_number_keeps_growing_past_the_padding(): void
    {
        $customer = $this->customer('+201055550001');
        $customer->forceFill(['customer_reference' => 'C-999'])->save();

        $next = $this->customer('+201055550002');

        // Padded to three, not capped at three.
        $this->assertSame('C-1000', $next->fresh()->customer_reference);
    }

    // ------------------------------------------------------------ on the screens

    #[Test]
    public function the_reference_finds_the_customer_on_the_dashboard(): void
    {
        $customer = $this->customer('+201055550001');

        // Somebody at the counter holding a parcel has the reference and nothing
        // else — not the phone number, and certainly not the row id.
        $response = $this->actingAs($this->superAdmin())
            ->getJson('/admin/user/search?query='.$customer->fresh()->customer_reference, [
                'X-Requested-With' => 'XMLHttpRequest',
            ])->assertOk();

        $this->assertStringContainsString($customer->name, $response->json('table'));
    }

    // ------------------------------------------------------------- the ticket

    private function orderFor(User $customer): Order
    {
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

        return app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
    }

    #[Test]
    public function the_driver_gets_everything_the_printed_card_carries(): void
    {
        $customer = $this->customer('+201055550001');
        $order = $this->orderFor($customer);

        $driver = $this->driverUser('+201066660001');
        $task = $order->tasks()->orderBy('sequence')->firstOrFail();
        $task->forceFill(['driver_id' => $driver->id])->save();

        $ticket = $this->actingAs($driver, 'sanctum')
            ->getJson('/api/v1/driver/tasks/'.$task->id)
            ->assertOk()
            ->json('data.ticket');

        // Assembled server-side rather than pieced together by the app from four
        // other fields: a label with the wrong order on it is found out when the
        // clothes come back to the wrong person.
        $this->assertSame($order->code, $ticket['order_code']);
        $this->assertSame('C-001', $ticket['customer_reference']);
        $this->assertSame($order->qr_token, $ticket['qr']);
        $this->assertNotNull($ticket['service']);
        $this->assertNotNull($ticket['date']);
        $this->assertNotNull($ticket['destination']);
    }

    #[Test]
    public function another_drivers_task_has_no_ticket_to_print(): void
    {
        $customer = $this->customer('+201055550001');
        $order = $this->orderFor($customer);

        $mine = $this->driverUser('+201066660001');
        $theirs = $this->driverUser('+201066660002');
        $task = $order->tasks()->orderBy('sequence')->firstOrFail();
        $task->forceFill(['driver_id' => $theirs->id])->save();

        // The ticket carries the QR token, so the isolation that protects the
        // task protects the token with it.
        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/v1/driver/tasks/'.$task->id)
            ->assertNotFound();
    }

    #[Test]
    public function a_customer_who_predates_the_column_still_prints(): void
    {
        $customer = $this->customer('+201055550001');
        // Simulate a row created before the migration: the backfill gave every
        // existing customer one, but a null must degrade to a blank line on the
        // card rather than a failed request.
        $customer->forceFill(['customer_reference' => null])->save();

        $order = $this->orderFor($customer->fresh());
        $driver = $this->driverUser('+201066660001');
        $task = $order->tasks()->orderBy('sequence')->firstOrFail();
        $task->forceFill(['driver_id' => $driver->id])->save();

        $ticket = $this->actingAs($driver, 'sanctum')
            ->getJson('/api/v1/driver/tasks/'.$task->id)
            ->assertOk()
            ->json('data.ticket');

        $this->assertNull($ticket['customer_reference']);
        $this->assertSame($order->code, $ticket['order_code']);
    }

    #[Test]
    public function the_role_matters_not_the_table(): void
    {
        // A defensive check on the hook's condition: `users` holds four kinds of
        // person and the reference belongs to exactly one of them.
        $customerRole = Role::where('slug', Role::USER)->value('id');

        $this->assertNotNull($customerRole);
        $this->assertSame(
            $customerRole,
            $this->customer('+201055550001')->role_id
        );
    }
}
