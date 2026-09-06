<?php

namespace Tests\Feature\Dashboard;

use App\Modules\City\Models\City;
use App\Modules\Country\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The coordinate picker, on both screens that carry `lat`/`lng`.
 *
 * The map itself is Leaflet's problem. What is ours — and what breaks silently —
 * is the wiring: the component finds its inputs by **id**, and the city options
 * have to carry `data-lat` / `data-lng` or the "map follows the city" half of
 * the feature does nothing at all and looks like a broken map rather than an
 * unwired one. None of that raises an error server-side, which is exactly why it
 * is asserted here.
 */
class MapPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->seedGeo();
    }

    #[Test]
    public function the_laundry_form_renders_a_map_bound_to_its_coordinate_inputs(): void
    {
        $this->actingAs($this->superAdmin());

        $page = $this->get(route('admin.laundry.create'));

        $page->assertOk()
            ->assertSee('map-picker-canvas', false)
            // The ids the component was told to drive must be the ids the form
            // actually rendered, or the map writes into nothing.
            ->assertSee('id="laundry-lat"', false)
            ->assertSee('id="laundry-lng"', false)
            ->assertSee('latInput: "laundry-lat"', false)
            ->assertSee('lngInput: "laundry-lng"', false)
            ->assertSee('citySelect: "laundry-city"', false)
            ->assertSee('id="laundry-city"', false);
    }

    #[Test]
    public function the_city_options_carry_the_coordinates_the_map_recentres_on(): void
    {
        // seedGeo() makes a city with no centre — this feature is what gives
        // it one, so the fixture sets it rather than assuming it.
        City::first()->forceFill(['lat' => 30.0444, 'lng' => 31.2357])->save();

        $this->actingAs($this->superAdmin());

        $html = $this->get(route('admin.laundry.create'))->assertOk()->getContent();

        // Matched numerically, not by string: MySQL pads `decimal(10,7)` to
        // 30.0444000 and the SQLite these tests run on does not. Asserting the
        // padded form would pass here and say nothing about the real database.
        $this->assertSame(1, preg_match('/data-lat="([\d.]+)"[^>]*data-lng="([\d.]+)"/', $html, $m));
        $this->assertEqualsWithDelta(30.0444, (float) $m[1], 0.0000001);
        $this->assertEqualsWithDelta(31.2357, (float) $m[2], 0.0000001);
    }

    #[Test]
    public function a_city_without_coordinates_emits_no_data_attributes(): void
    {
        // The map is supposed to stay where it is rather than jump to [0, 0],
        // and it can only do that if the option says nothing instead of zero.
        City::query()->update(['lat' => null, 'lng' => null]);

        $this->actingAs($this->superAdmin());

        $page = $this->get(route('admin.laundry.create'));

        $page->assertOk()
            ->assertSee('map-picker-canvas', false)
            ->assertDontSee('data-lat=', false)
            ->assertDontSee('data-lat="0', false);
    }

    #[Test]
    public function the_city_form_has_its_own_picker_and_no_city_select_to_follow(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get(route('admin.city.create'))
            ->assertOk()
            ->assertSee('map-picker-canvas', false)
            ->assertSee('latInput: "city-lat"', false)
            ->assertSee('lngInput: "city-lng"', false)
            // This form *is* the city — there is nothing for it to follow.
            ->assertSee('citySelect: null', false);
    }

    #[Test]
    public function the_show_page_renders_the_map_read_only(): void
    {
        ['laundry' => $laundry] = $this->laundryWithOwner('a', '+201000000101', '+201000000102');

        $this->actingAs($this->superAdmin());

        $this->get(route('admin.laundry.show', $laundry->id))
            ->assertOk()
            ->assertSee('readonly: true', false);
    }

    #[Test]
    public function a_city_saves_and_updates_its_coordinates(): void
    {
        $this->actingAs($this->superAdmin());

        $country = Country::first();

        $this->post(route('admin.city.store'), [
            'name' => ['en' => 'Pinned City'],
            'country_id' => $country->id,
            'status' => 'active',
            'lat' => 29.9668,
            'lng' => 32.5498,
        ])->assertRedirect();

        $city = City::latest('id')->first();

        $this->assertEqualsWithDelta(29.9668, (float) $city->lat, 0.0000001);
        $this->assertEqualsWithDelta(32.5498, (float) $city->lng, 0.0000001);

        $this->put(route('admin.city.update', $city->id), [
            'name' => ['en' => 'Pinned City'],
            'country_id' => $country->id,
            'status' => 'active',
            'lat' => 24.0889,
            'lng' => 32.8998,
        ])->assertRedirect();

        $this->assertEqualsWithDelta(24.0889, (float) $city->fresh()->lat, 0.0000001);
        $this->assertEqualsWithDelta(32.8998, (float) $city->fresh()->lng, 0.0000001);
    }

    #[Test]
    public function coordinates_outside_the_globe_are_refused(): void
    {
        $this->actingAs($this->superAdmin());

        $country = Country::first();

        $this->post(route('admin.city.store'), [
            'name' => ['en' => 'Nowhere'],
            'country_id' => $country->id,
            'status' => 'active',
            'lat' => 120,   // there is no 120th parallel
            'lng' => 400,
        ])->assertSessionHasErrors(['lat', 'lng']);
    }

    #[Test]
    public function a_city_may_be_saved_with_no_coordinates_at_all(): void
    {
        // Optional by decision: a city is usable without a centre, the picker
        // just opens on the country instead of refusing to draw.
        $this->actingAs($this->superAdmin());

        $country = Country::first();

        $this->post(route('admin.city.store'), [
            'name' => ['en' => 'Unpinned City'],
            'country_id' => $country->id,
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $city = City::latest('id')->first();

        $this->assertNull($city->lat);
        $this->assertNull($city->lng);
    }

    #[Test]
    public function the_shared_script_is_emitted_once_however_many_pickers_render(): void
    {
        $this->actingAs($this->superAdmin());

        $html = $this->get(route('admin.laundry.create'))->assertOk()->getContent();

        // `@once` guards the definition and the styles; a second copy would mean
        // re-declaring the initialiser and double-binding every listener.
        $this->assertSame(1, substr_count($html, 'window.initMapPicker = function'));
        $this->assertSame(1, substr_count($html, '.map-picker-canvas {'));
    }

    #[Test]
    public function the_coordinate_boxes_are_locked_so_only_the_map_writes_them(): void
    {
        // `inputs="readonly"` is applied from the component's own script, which
        // is why the assertion is on the option it was given rather than on a
        // `readonly` attribute in the markup — the server does not render one.
        $this->actingAs($this->superAdmin());

        $this->get(route('admin.laundry.create'))
            ->assertOk()
            ->assertSee('inputs: "readonly"', false);

        $this->get(route('admin.city.create'))
            ->assertOk()
            ->assertSee('inputs: "readonly"', false);
    }

    #[Test]
    public function an_editable_map_offers_a_place_search_and_a_way_to_clear_the_pin(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get(route('admin.laundry.create'))
            ->assertOk()
            // The rendered controls, not the selector strings — the shared
            // initialiser names `data-map-search` whether or not the box exists,
            // so asserting the bare attribute proves nothing either way.
            ->assertSee('Search for a place, street or landmark', false)
            ->assertSee('Clear the pin', false)
            // A nested <form> would submit the wrong thing on Enter, and a
            // button without type="button" submits the form it sits in.
            ->assertSee('type="button" data-map-search-go', false);
    }

    #[Test]
    public function a_read_only_map_offers_neither(): void
    {
        ['laundry' => $laundry] = $this->laundryWithOwner('b', '+201000000201', '+201000000202');

        $this->actingAs($this->superAdmin());

        $this->get(route('admin.laundry.show', $laundry->id))
            ->assertOk()
            ->assertSee('readonly: true', false)
            // Nothing to search for and nothing to clear when nothing can move.
            ->assertDontSee('Search for a place, street or landmark', false)
            ->assertDontSee('Clear the pin', false)
            ->assertSee('The saved location.', false);
    }
}
