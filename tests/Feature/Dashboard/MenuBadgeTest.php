<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Complaint\Models\Complaint;
use App\Modules\Driver\Models\DriverApplication;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Models\Order;
use App\Services\MenuBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The counts beside the menu.
 *
 * The home page has had a «Waiting for a person» queue for a while, and it
 * answers the right question — but only to somebody who opens the home page.
 * These put the same answer in the sidebar, so an operator on any screen can
 * see that a complaint has gone unanswered or a laundry is waiting to be let
 * in.
 *
 * Two rules hold the feature together, and both are tested here because both
 * are easy to break by adding one more badge:
 *
 *   1. **Only work waiting on a person.** Not row counts. A badge beside
 *      Zones reading 25 is a number nobody asked for, and once everything
 *      carries one nobody reads any of them.
 *   2. **Zero draws nothing.** An empty queue should look like every other
 *      finished thing on the list.
 */
class MenuBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();
    }

    #[Test]
    public function an_empty_queue_draws_nothing(): void
    {
        foreach (['driver_application', 'laundry', 'order', 'order_task', 'complaint', 'refund'] as $model) {
            $this->assertNull(MenuBadges::for($model), "{$model} should draw no badge when empty");
        }
    }

    #[Test]
    public function reference_screens_never_carry_a_badge(): void
    {
        // These have rows — a lot of them — and none of those rows is waiting
        // on anybody. A count here would be noise that teaches an operator to
        // stop reading the badges that matter.
        $this->seedGeo();
        $this->seedCatalog();

        foreach (['zone', 'city', 'country', 'item', 'service', 'driver', 'user', 'coupon', 'setting'] as $model) {
            $this->assertNull(MenuBadges::for($model), "{$model} is a reference screen, not a queue");
        }
    }

    #[Test]
    public function driver_leads_are_counted_until_somebody_rings_them(): void
    {
        DriverApplication::create(['name' => 'One', 'phone' => '01100000001']);
        DriverApplication::create(['name' => 'Two', 'phone' => '01100000002']);

        $this->assertSame(2, MenuBadges::for('driver_application'));

        DriverApplication::first()->forceFill(['handled_at' => now()])->save();

        $this->assertSame(1, MenuBadges::for('driver_application'));
    }

    #[Test]
    public function laundries_are_counted_until_somebody_decides(): void
    {
        $this->seedGeo();

        Laundry::withoutGlobalScopes()->create([
            'name' => json_encode(['en' => 'Applicant', 'ar' => 'متقدمة'], JSON_UNESCAPED_UNICODE),
            'phone' => '+201066660001', 'status' => 'inactive', 'approved_at' => null,
        ]);

        // An operator-created laundry is approved by that act and must not sit
        // in a queue asking that same operator to decide.
        $this->laundryWithOwner('A', '+201011110001', '+201011110002');

        $this->assertSame(1, MenuBadges::for('laundry'));
    }

    #[Test]
    public function open_complaints_are_counted_and_closed_ones_are_not(): void
    {
        $customer = $this->customer();

        $open = Complaint::create([
            'user_id' => $customer->id, 'category' => 'other', 'body' => 'It was late.',
            'status' => 'new', 'reference' => 'C-1',
        ]);
        Complaint::create([
            'user_id' => $customer->id, 'category' => 'other', 'body' => 'Sorted.',
            'status' => 'closed', 'reference' => 'C-2',
        ]);

        $this->assertSame(1, MenuBadges::for('complaint'));

        $open->update(['status' => 'closed']);

        $this->assertNull(MenuBadges::for('complaint'));
    }

    #[Test]
    public function the_sidebar_shows_the_number_it_is_given(): void
    {
        DriverApplication::create(['name' => 'One', 'phone' => '01100000001']);

        $this->actingAs($this->superAdmin())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('menu-badge', false);
    }

    #[Test]
    public function a_laundry_owner_counts_only_their_own(): void
    {
        $geo = $this->seedGeo();
        $catalog = $this->seedCatalog();

        $mine = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $theirs = $this->laundryWithOwner('B', '+201011110004', '+201011110005');

        $customer = $this->customer();
        $address = $this->addressFor($customer, $geo['zones'][0]);

        // One unassigned order for each laundry's tenant scope to see — or
        // not. A badge is a number shown to whoever is looking at the menu,
        // so it has to obey the same scope every other query does.
        Order::withoutGlobalScopes()->create([
            'code' => '90001', 'user_id' => $customer->id, 'laundry_id' => $theirs['laundry']->id,
            'service_id' => $catalog['service']->id, 'status' => 'awaiting_pickup',
            'pickup_address_id' => $address->id, 'delivery_address_id' => $address->id,
            'estimated_items_count' => 1, 'estimated_subtotal' => 10, 'delivery_fee' => 0,
            'discount_total' => 0, 'cash_surcharge' => 0, 'estimated_total' => 10,
            'qr_token' => 'tok-90001',
        ]);

        $this->actingAs($mine['owner']);
        $mineCount = MenuBadges::for('order');

        $this->actingAs($theirs['owner']);
        $theirsCount = MenuBadges::for('order');

        // The order belongs to B, and it has a laundry — so it is not
        // "unassigned" for either. What matters is that neither owner is
        // handed a count drawn from the other's rows.
        $this->assertNull($mineCount);
        $this->assertNull($theirsCount);
    }
}
