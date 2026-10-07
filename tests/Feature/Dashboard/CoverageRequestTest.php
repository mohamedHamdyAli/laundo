<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Address\Models\Address;
use App\Modules\Zone\Models\CoverageRequest;
use App\Modules\Zone\Services\coverageRequestCrudService;
use App\Services\MenuBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «طلبات خارج التغطية» — the customers the app told «we will contact you as soon
 * as we reach your area».
 *
 * A row is a promise with a phone number on it. What is guarded hardest is the
 * moment it can be kept: the address gains a zone, the row says «Covered now»,
 * and the sidebar counts it until somebody rings.
 */
class CoverageRequestTest extends TestCase
{
    use RefreshDatabase;

    private array $geo;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
    }

    private function refused(string $phone, string $name, string $street): CoverageRequest
    {
        $customer = $this->customer($phone);
        $customer->update(['name' => $name]);

        $address = Address::create([
            'user_id' => $customer->id, 'label' => 'Home', 'city_id' => $this->geo['city']->id,
            'zone_id' => null, 'street' => $street, 'lat' => 30.01, 'lng' => 31.20, 'is_default' => true,
        ]);

        return app(coverageRequestCrudService::class)->record($customer, $address);
    }

    #[Test]
    public function the_super_admin_sees_who_asked_and_where(): void
    {
        $this->refused('+201055550301', 'Hoda Salem', 'شارع الهرم');

        $this->actingAs($this->superAdmin())
            ->get(route('admin.coverage_request.index'))
            ->assertOk()
            ->assertSee('Hoda Salem')
            ->assertSee('tel:+201055550301', false)
            ->assertSee('شارع الهرم')
            ->assertSee(__('Still outside'))
            ->assertSee('openstreetmap.org/?mlat=30.01&mlon=31.2#map=17/30.01/31.2', false);
    }

    #[Test]
    public function a_row_whose_address_has_gained_a_zone_is_covered_now_and_badged(): void
    {
        $waiting = $this->refused('+201055550302', 'Still Out', 'Far Street');
        $ready = $this->refused('+201055550303', 'Now In', 'Near Street');

        $this->assertNull(MenuBadges::for('coverage_request'), 'nobody to ring yet: no badge');

        // A zone was drawn over their pin (or they moved it) — read off the
        // address as it is today, nothing has to update the row.
        $ready->address->update(['zone_id' => $this->geo['zones'][0]->id]);

        $this->assertSame(1, MenuBadges::for('coverage_request'));
        $this->assertTrue($ready->fresh()->isNowCovered());
        $this->assertFalse($waiting->fresh()->isNowCovered());

        // The one somebody can ring today comes first.
        $page = $this->actingAs($this->superAdmin())->get(route('admin.coverage_request.index'))->assertOk();
        $page->assertSeeInOrder(['Now In', 'Still Out'])->assertSee(__('Covered now'));
    }

    #[Test]
    public function a_customer_in_a_zone_switched_off_is_named_and_comes_back_when_it_is_switched_on(): void
    {
        $zone = $this->geo['zones'][0];
        $row = $this->refused('+201055550310', 'Paused Zone', 'Some Street');
        $row->address->update(['zone_id' => $zone->id]);

        // Switched off with the panel's own button.
        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.zone.toggleStatus', $zone->id), ['status' => 'inactive'])
            ->assertOk();

        $this->assertFalse($row->fresh()->isNowCovered());
        $this->assertNull(MenuBadges::for('coverage_request'), 'still not served: nobody to ring yet');
        $this->actingAs($this->superAdmin())->get(route('admin.coverage_request.index'))
            ->assertOk()
            ->assertSee(__('Zone switched off'));

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.zone.toggleStatus', $zone->id), ['status' => 'active'])
            ->assertOk();

        $this->assertTrue($row->fresh()->isNowCovered());
        $this->assertSame(1, MenuBadges::for('coverage_request'));
        $this->actingAs($this->superAdmin())->get(route('admin.coverage_request.index'))
            ->assertOk()
            ->assertSee(__('Covered now'));
    }

    #[Test]
    public function a_city_switched_off_is_named_as_the_city_not_the_zone(): void
    {
        // The zone is on; switching it «on» again would change nothing. The
        // row has to point at what actually closed the area.
        $row = $this->refused('+201055550311', 'Closed City', 'Some Street');
        $row->address->update(['zone_id' => $this->geo['zones'][0]->id]);
        $this->geo['city']->update(['status' => 'inactive']);

        $this->actingAs($this->superAdmin())->get(route('admin.coverage_request.index'))
            ->assertOk()
            ->assertSee(__('City switched off'))
            ->assertDontSee(__('Zone switched off'));
    }

    #[Test]
    public function marking_them_rung_takes_them_off_the_badge_and_can_be_undone(): void
    {
        $row = $this->refused('+201055550304', 'Call Me', 'Some Street');
        $row->address->update(['zone_id' => $this->geo['zones'][0]->id]);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.coverage_request.contacted', $row->id))->assertRedirect();

        $row->refresh();
        $this->assertTrue($row->isContacted());
        $this->assertSame($admin->id, $row->contacted_by);
        $this->assertNull(MenuBadges::for('coverage_request'));

        $this->actingAs($admin)->post(route('admin.coverage_request.contacted', $row->id))->assertRedirect();

        $this->assertFalse($row->fresh()->isContacted());
        $this->assertSame(1, MenuBadges::for('coverage_request'));
    }

    #[Test]
    public function the_search_reaches_the_name_the_number_and_the_street(): void
    {
        $this->refused('+201055550305', 'Hoda Salem', 'شارع الهرم');
        $this->refused('+201055550306', 'Karim Adel', 'Corniche Road');

        foreach (['hoda' => 'Hoda Salem', '0306' => 'Karim Adel', 'الهرم' => 'Hoda Salem'] as $term => $expected) {
            $html = $this->actingAs($this->superAdmin())
                ->get(route('admin.coverage_request.search', ['query' => $term]), ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()
                ->json('table');

            $this->assertStringContainsString($expected, $html, "«{$term}» should find {$expected}");
            $other = $expected === 'Hoda Salem' ? 'Karim Adel' : 'Hoda Salem';
            $this->assertStringNotContainsString($other, $html, "«{$term}» should not find {$other}");
        }
    }

    #[Test]
    public function the_search_reaches_who_rang_them(): void
    {
        // Shown on the row, so searchable — the owner's rule.
        $rung = $this->refused('+201055550308', 'Hoda Salem', 'شارع الهرم');
        $this->refused('+201055550309', 'Karim Adel', 'Corniche Road');
        $admin = $this->superAdmin();
        $admin->update(['name' => 'Operator Mona']);
        $this->actingAs($admin)->post(route('admin.coverage_request.contacted', $rung->id));

        $html = $this->actingAs($admin)
            ->get(route('admin.coverage_request.search', ['query' => 'mona']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('table');

        $this->assertStringContainsString('Hoda Salem', $html);
        $this->assertStringNotContainsString('Karim Adel', $html);
    }

    #[Test]
    public function a_laundry_owner_cannot_open_it(): void
    {
        $owner = $this->laundryWithOwner('A', '+201011110301', '+201011110302')['owner'];
        $row = $this->refused('+201055550307', 'Private Person', 'Somewhere');

        $this->actingAs($owner)->get(route('admin.coverage_request.index'))->assertForbidden();
        $this->actingAs($owner)->post(route('admin.coverage_request.contacted', $row->id))->assertForbidden();

        $this->assertFalse($row->fresh()->isContacted());
    }

    #[Test]
    public function the_sidebar_shows_it_under_locations(): void
    {
        $this->assertSame(4, config('menu.groups.locations.items.coverage_request'));
        $this->assertSame('admin.coverage_request.index', config('menu.routes.coverage_request'));
        $this->assertNotEmpty(config('menu.icons.coverage_request'));
        $this->assertNotEmpty(config('menu.titles.coverage_request'));
    }
}
