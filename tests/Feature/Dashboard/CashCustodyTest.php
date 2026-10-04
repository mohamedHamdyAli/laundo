<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Payment\Enums\PaymentStatus;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Services\CashCustody;
use App\Modules\Payment\Services\RefundService;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «الكاش مع المناديب» (the owner, 2026-10-01): how much each driver is holding,
 * and the office recording that it has it.
 */
class CashCustodyTest extends TestCase
{
    use RefreshDatabase;

    private array $tenant;

    private Order $order;

    private Driver $ahmed;

    private Driver $sara;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $geo = $this->seedGeo();
        $catalog = $this->seedCatalog();

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $customer = $this->customer();
        $address = $this->addressFor($customer, $geo['zones'][0]);

        $this->order = Order::withoutGlobalScopes()->create([
            'code' => Order::generateCode(), 'user_id' => $customer->id,
            'laundry_id' => $this->tenant['laundry']->id, 'service_id' => $catalog['service']->id,
            'status' => 'completed', 'pickup_address_id' => $address->id, 'delivery_address_id' => $address->id,
            'estimated_total' => 100, 'payment_method' => 'cash', 'qr_token' => Order::generateQrToken(),
        ]);

        $this->ahmed = $this->driverUser('+201044440001');
        $this->sara = $this->driverUser('+201044440002');
    }

    private function cash(Driver $driver, float $amount, bool $handedIn = false): Payment
    {
        return Payment::create([
            'order_id' => $this->order->id, 'user_id' => $this->order->user_id,
            'provider' => 'cash', 'method' => PaymentMethod::Cash, 'amount' => $amount,
            'status' => PaymentStatus::Captured, 'captured_at' => now(),
            'collected_by' => $driver->id,
            'handed_over_at' => $handedIn ? now() : null,
        ]);
    }

    private function moderator(array $permissions): User
    {
        $this->grant('admin', $permissions);

        return User::create([
            'name' => 'Moderator', 'email' => 'moderator@test.local',
            'phone' => '+201055550009', 'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'),
            'phone_verified_at' => now(),
        ]);
    }

    #[Test]
    public function each_driver_holds_what_they_collected_and_have_not_handed_in(): void
    {
        $this->cash($this->ahmed, 100);
        $this->cash($this->ahmed, 50);
        $this->cash($this->ahmed, 999, handedIn: true);
        $this->cash($this->sara, 30);
        // A card payment is nobody's pocket.
        Payment::create([
            'order_id' => $this->order->id, 'user_id' => $this->order->user_id, 'provider' => 'fake',
            'method' => PaymentMethod::Card, 'amount' => 400, 'status' => PaymentStatus::Captured,
            'captured_at' => now(), 'provider_reference' => 'ref-1',
        ]);

        $holdings = app(CashCustody::class)->holdings()->keyBy('driver_id');

        $this->assertSame([$this->ahmed->id, $this->sara->id], $holdings->keys()->all());
        $this->assertSame(150.0, $holdings[$this->ahmed->id]['amount']);
        $this->assertSame(2, $holdings[$this->ahmed->id]['collections']);
        $this->assertSame(30.0, $holdings[$this->sara->id]['amount']);

        $this->actingAs($this->superAdmin())->get(route('admin.payment.index'))
            ->assertOk()
            ->assertSee(__('Cash with drivers'))
            ->assertSee(__('With the driver'))
            ->assertSee(__('Collected by :name', ['name' => $this->ahmed->name]));
    }

    #[Test]
    public function the_office_receives_a_drivers_cash_once(): void
    {
        $first = $this->cash($this->ahmed, 100);
        $second = $this->cash($this->ahmed, 50);
        $saras = $this->cash($this->sara, 30);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.payment.cash.receive', $this->ahmed->id), ['up_to' => Payment::max('id')])
            ->assertRedirect()
            ->assertSessionHas('success');

        foreach ([$first, $second] as $payment) {
            $this->assertNotNull($payment->fresh()->handed_over_at);
            $this->assertSame($admin->id, $payment->fresh()->received_by);
        }
        // Only that driver's.
        $this->assertNull($saras->fresh()->handed_over_at);

        // Pressed again: nothing left to receive, and it says so.
        $this->actingAs($admin)->post(route('admin.payment.cash.receive', $this->ahmed->id), ['up_to' => Payment::max('id')])
            ->assertSessionHas('error');
    }

    #[Test]
    public function only_what_the_screen_showed_is_received(): void
    {
        $shown = $this->cash($this->ahmed, 100);
        $upTo = app(CashCustody::class)->holdings()->first()['last_id'];

        // Taken at a door while the operator's page was open.
        $later = $this->cash($this->ahmed, 50);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.payment.cash.receive', $this->ahmed->id), ['up_to' => $upTo])
            ->assertSessionHas('success');

        $this->assertNotNull($shown->fresh()->handed_over_at);
        $this->assertNull($later->fresh()->handed_over_at);
        $this->assertSame(50.0, app(CashCustody::class)->holdings()->first()['amount']);
    }

    #[Test]
    public function a_refund_to_the_card_never_binds_to_the_drivers_cash(): void
    {
        $this->cash($this->ahmed, 40);
        $card = Payment::create([
            'order_id' => $this->order->id, 'user_id' => $this->order->user_id, 'provider' => 'fake',
            'method' => PaymentMethod::Card, 'amount' => 60, 'status' => PaymentStatus::Captured,
            'captured_at' => now(), 'provider_reference' => 'ref-card',
        ]);
        $this->order->forceFill(['payment_status' => 'paid'])->save();

        $refund = app(RefundService::class)->request($this->order->fresh(), $this->order->customer, 10, 'damaged');

        $this->assertSame($card->id, $refund->payment_id);
    }

    #[Test]
    public function receiving_cash_needs_its_own_permission(): void
    {
        $this->cash($this->ahmed, 100);

        // Seeing the payments is not taking a driver's cash.
        $viewer = $this->moderator(['payment.view']);
        $this->actingAs($viewer)->get(route('admin.payment.index'))
            ->assertOk()->assertDontSee(__('Cash received'));
        $this->actingAs($viewer)->post(route('admin.payment.cash.receive', $this->ahmed->id), ['up_to' => Payment::max('id')])->assertForbidden();

        $this->grant('admin', ['payment.view', 'payment.update']);
        $this->actingAs($viewer->fresh())->post(route('admin.payment.cash.receive', $this->ahmed->id), ['up_to' => Payment::max('id')])
            ->assertSessionHas('success');
    }

    #[Test]
    public function a_laundry_never_handles_a_drivers_cash(): void
    {
        $payment = $this->cash($this->ahmed, 100);
        $this->grant('laundry_owner', ['payment.view', 'payment.update']);
        $owner = $this->tenant['owner']->fresh();

        $this->actingAs($owner)->get(route('admin.payment.index'))
            ->assertOk()->assertDontSee(__('Cash with drivers'));
        $this->actingAs($owner)->post(route('admin.payment.cash.receive', $this->ahmed->id), ['up_to' => Payment::max('id')])->assertForbidden();

        $this->assertNull($payment->fresh()->handed_over_at);
    }
}
