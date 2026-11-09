<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Address\Models\Address;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «إنشاء طلب» — an order taken for a customer from the panel (2026-11-08).
 *
 * The owner's answers: an existing customer only, the app's checkout exactly,
 * the platform only. Everything the app's checkout already guarantees is
 * covered by the API's own tests; this file proves the panel reaches the same
 * code, and who may.
 */
class PanelOrderTest extends TestCase
{
    use RefreshDatabase;

    private array $geo;

    private array $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);
    }

    #[Test]
    public function an_operator_with_order_create_places_an_order_for_a_customer(): void
    {
        [$customer, $address, $laundry] = $this->coveredCustomer();
        $operator = $this->operator(['order.view', 'order.create']);

        $this->actingAs($operator)
            ->post(route('admin.order.store'), $this->payload($customer, $address))
            ->assertRedirect();

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame(OrderStatus::AwaitingPickup, $order->status);
        $this->assertSame($laundry->id, $order->laundry_id);
        $this->assertSame(3, $order->estimated_items_count);
        $this->assertSame('cash', $order->payment_method);
        $this->assertNotNull($order->review_terms_accepted_at);
        // The app's own placement: the four legs exist from the start.
        $this->assertSame(4, OrderTask::where('order_id', $order->id)->count());

        // The history says the operator opened it, not the customer.
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => 'awaiting_pickup',
            'actor_type' => 'admin',
            'actor_id' => $operator->id,
        ]);
    }

    #[Test]
    public function it_lands_on_the_new_order(): void
    {
        [$customer, $address] = $this->coveredCustomer();

        $response = $this->actingAs($this->superAdmin())
            ->post(route('admin.order.store'), $this->payload($customer, $address));

        $order = Order::withoutGlobalScopes()->firstOrFail();
        $response->assertRedirect(route('admin.order.show', $order->id))
            ->assertSessionHas('success', "Order #{$order->code} placed for Customer.");
    }

    #[Test]
    public function without_order_create_every_route_is_forbidden(): void
    {
        [$customer, $address] = $this->coveredCustomer();
        $operator = $this->operator(['order.view']);

        $this->actingAs($operator)->get(route('admin.order.create'))->assertForbidden();
        $this->actingAs($operator)->post(route('admin.order.store'), $this->payload($customer, $address))->assertForbidden();
        $this->actingAs($operator)->postJson(route('admin.order.quote'), $this->payload($customer, $address))->assertForbidden();
        $this->actingAs($operator)->getJson(route('admin.order.customers', ['query' => 'Cu']))->assertForbidden();
        $this->actingAs($operator)->getJson(route('admin.order.customer_addresses', $customer->id))->assertForbidden();

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_laundry_is_refused_even_when_granted_the_permission(): void
    {
        [$customer, $address] = $this->coveredCustomer();
        $tenant = $this->laundryWithOwner('B', '+201022220001', '+201022220002');
        $this->grant('laundry_owner', ['order.view', 'order.create']);
        $owner = $tenant['owner'];

        $this->actingAs($owner)->get(route('admin.order.create'))->assertForbidden();
        $this->actingAs($owner)->post(route('admin.order.store'), $this->payload($customer, $address))->assertForbidden();
        $this->actingAs($owner)->postJson(route('admin.order.quote'), $this->payload($customer, $address))->assertForbidden();
        $this->actingAs($owner)->getJson(route('admin.order.customers', ['query' => 'Cu']))->assertForbidden();
        $this->actingAs($owner)->getJson(route('admin.order.customer_addresses', $customer->id))->assertForbidden();
        $this->actingAs($owner)->postJson(route('admin.order.customer_addresses.store', $customer->id), $this->newAddress())->assertForbidden();

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(1, Address::where('user_id', $customer->id)->count());

        // And the list does not offer the button.
        $this->actingAs($owner)->get(route('admin.order.index'))
            ->assertOk()
            ->assertDontSee(route('admin.order.create'));
    }

    #[Test]
    public function the_list_offers_the_button_to_the_platform(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.order.index'))
            ->assertOk()
            ->assertSee(route('admin.order.create'));
    }

    #[Test]
    public function the_form_opens_with_the_catalogue(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.order.create'))
            ->assertOk()
            ->assertSee(__('New order for a customer'))
            ->assertSee('Wash &amp; Iron', false)
            // The basket is drawn from the app's own price list.
            ->assertSee('"pricing_mode":"per_item"', false);
    }

    #[Test]
    public function another_customers_address_is_refused(): void
    {
        [$customer] = $this->coveredCustomer();
        $stranger = $this->customer('+201055550001');
        $theirs = $this->addressFor($stranger, $this->geo['zones'][0]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.order.store'), $this->payload($customer, $theirs))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pickup_address_id');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function only_an_active_customer_can_be_ordered_for(): void
    {
        [$customer, $address] = $this->coveredCustomer();
        $admin = $this->superAdmin();

        // A staff account is not a customer.
        $this->actingAs($admin)
            ->postJson(route('admin.order.store'), ['user_id' => $admin->id] + $this->payload($customer, $address))
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');

        // Nor is a switched-off one.
        $customer->update(['status' => 'inactive']);

        $this->actingAs($admin)
            ->postJson(route('admin.order.store'), $this->payload($customer, $address))
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_customer_must_have_agreed_to_the_review(): void
    {
        [$customer, $address] = $this->coveredCustomer();

        $payload = $this->payload($customer, $address);
        unset($payload['accepts_review_terms']);

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.order.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('accepts_review_terms');
    }

    #[Test]
    public function an_address_outside_coverage_is_refused_against_its_field(): void
    {
        [$customer, $address] = $this->coveredCustomer();
        $this->geo['zones'][0]->update(['status' => 'inactive']);

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.order.store'), $this->payload($customer, $address))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pickup_address_id');
    }

    #[Test]
    public function an_uncovered_address_is_recorded_on_the_submit_not_on_every_quote(): void
    {
        [$customer, $address] = $this->coveredCustomer();
        $this->geo['zones'][0]->update(['status' => 'inactive']);
        $admin = $this->superAdmin();

        // The price box asks on every change: none of that is an attempt.
        $this->actingAs($admin)->postJson(route('admin.order.quote'), $this->payload($customer, $address))->assertStatus(422);
        $this->actingAs($admin)->postJson(route('admin.order.quote'), $this->payload($customer, $address))->assertStatus(422);
        $this->assertDatabaseCount('coverage_requests', 0);

        $this->actingAs($admin)->postJson(route('admin.order.store'), $this->payload($customer, $address))->assertStatus(422);

        $this->assertDatabaseHas('coverage_requests', [
            'user_id' => $customer->id,
            'address_id' => $address->id,
            'attempts' => 1,
        ]);
    }

    #[Test]
    public function the_history_says_it_was_taken_from_the_panel(): void
    {
        [$customer, $address] = $this->coveredCustomer();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.order.store'), $this->payload($customer, $address))
            ->assertRedirect();

        $this->assertDatabaseHas('order_status_logs', [
            'from_status' => null,
            'actor_type' => 'admin',
            'note' => 'Placed from the panel for the customer.',
        ]);
    }

    #[Test]
    public function an_operator_who_cannot_open_orders_is_not_sent_to_a_403(): void
    {
        [$customer, $address] = $this->coveredCustomer();
        $operator = $this->operator(['order.create']);

        $this->actingAs($operator)
            ->post(route('admin.order.store'), $this->payload($customer, $address))
            ->assertRedirect(route('admin.order.create'))
            ->assertSessionHas('success');

        $this->assertSame(1, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function stain_photos_are_attached_as_uploaded_by_the_operator(): void
    {
        Storage::fake('public');
        [$customer, $address] = $this->coveredCustomer();
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.order.store'), $this->payload($customer, $address) + [
                'photos' => [UploadedFile::fake()->image('stain.jpg')],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('order_media', [
            'order_id' => Order::withoutGlobalScopes()->value('id'),
            'type' => 'stain',
            'uploaded_by' => $admin->id,
        ]);
    }

    #[Test]
    public function a_quote_priced_service_needs_no_basket(): void
    {
        [$customer, $address] = $this->coveredCustomer(serviceId: $this->catalog['quoted']->id);

        $payload = $this->payload($customer, $address);
        $payload['service_id'] = $this->catalog['quoted']->id;
        // A form cannot post an empty array: the key is simply absent.
        unset($payload['items']);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.order.store'), $payload)
            ->assertRedirect();

        $this->assertSame(1, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_price_box_quotes_without_saving(): void
    {
        [$customer, $address] = $this->coveredCustomer();

        $response = $this->actingAs($this->superAdmin())
            ->postJson(route('admin.order.quote'), $this->payload($customer, $address))
            ->assertOk();

        $this->assertSame(3, $response->json('items_count'));
        $this->assertNotEmpty($response->json('rows'));
        $this->assertNotEmpty($response->json('total'));
        $this->assertSame('Laundry A', $response->json('laundry'));
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_customer_picker_finds_active_customers_only(): void
    {
        $this->customer('+201066660001')->update(['name' => 'Mona Adel']);
        $this->customer('+201066660002')->update(['name' => 'Mona Off', 'status' => 'inactive']);
        $this->superAdmin()->update(['name' => 'Mona Admin']);

        $results = $this->actingAs($this->superAdmin())
            ->getJson(route('admin.order.customers', ['query' => 'mona']))
            ->assertOk()
            ->json('results');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Mona Adel', $results[0]['text']);
    }

    #[Test]
    public function the_picker_lists_the_customers_addresses_with_their_coverage(): void
    {
        [$customer, $address] = $this->coveredCustomer();
        $outside = $this->addressFor($customer, $this->geo['zones'][1], label: 'Work');
        $this->geo['zones'][1]->update(['status' => 'inactive']);

        $addresses = collect($this->actingAs($this->superAdmin())
            ->getJson(route('admin.order.customer_addresses', $customer->id))
            ->assertOk()
            ->json('addresses'))->keyBy('id');

        $this->assertTrue($addresses[$address->id]['is_covered']);
        $this->assertFalse($addresses[$outside->id]['is_covered']);
    }

    #[Test]
    public function a_new_address_is_saved_to_the_customer(): void
    {
        $customer = $this->customer();

        $response = $this->actingAs($this->superAdmin())
            ->postJson(route('admin.order.customer_addresses.store', $customer->id), $this->newAddress())
            ->assertCreated();

        $address = Address::findOrFail($response->json('address.id'));
        $this->assertSame($customer->id, $address->user_id);
        // Their first: the default, as in the app.
        $this->assertTrue((bool) $address->is_default);
    }

    #[Test]
    public function a_new_address_needs_a_pin(): void
    {
        $customer = $this->customer();
        $data = $this->newAddress();
        unset($data['lat'], $data['lng']);

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.order.customer_addresses.store', $customer->id), $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lat', 'lng']);
    }

    /**
     * @return array{0: User, 1: Address, 2: Laundry}
     */
    private function coveredCustomer(?int $serviceId = null): array
    {
        $customer = $this->customer();
        $address = $this->addressFor($customer, $this->geo['zones'][0]);
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        if ($serviceId !== null) {
            $this->cover($tenant['laundry'], $this->geo['zones'][0]->id, $serviceId);
        }

        return [$customer, $address, $tenant['laundry']];
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function operator(array $permissions): User
    {
        $this->grant('admin', $permissions);

        return User::create([
            'name' => 'Desk', 'email' => 'desk@test.local', 'phone' => '+201077770001',
            'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'),
            'phone_verified_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $customer, Address $address): array
    {
        return [
            'user_id' => $customer->id,
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [
                ['item_id' => $this->catalog['items'][0]->id, 'qty' => 2],
                ['item_id' => $this->catalog['items'][1]->id, 'qty' => 1],
            ],
            'pickup_date' => now()->addDay()->toDateString(),
            'payment_method' => 'cash',
            'accepts_review_terms' => '1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newAddress(): array
    {
        return [
            'label' => 'Office',
            'zone_id' => $this->geo['zones'][0]->id,
            'street' => '12 Abbas El Akkad',
            'lat' => 30.0561,
            'lng' => 31.3400,
        ];
    }
}
