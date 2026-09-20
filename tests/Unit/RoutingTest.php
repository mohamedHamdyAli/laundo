<?php

namespace Tests\Unit;

use App\Services\Routing\Coordinate;
use App\Services\Routing\GoogleDistanceMatrixRouter;
use App\Services\Routing\HaversineRouter;
use App\Services\Routing\RouteLeg;
use App\Services\Routing\RoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The distance layer, with the network faked.
 *
 * The Google key is put in the config rather than in a settings row: the driver
 * prefers the config, so no test here depends on a seeded value.
 *
 * `Http::fake()` **merges** stubs rather than replacing them, and the first
 * match wins — so a test that needs the provider to answer and then to fail
 * cannot call it twice. It uses one closure holding the state instead. That
 * cost an hour once.
 *
 * What is actually worth guarding here is the shape of Google's answer, because
 * every one of these was found by reading a live response rather than by
 * thinking about it:
 *
 * - the elements come back in whatever order Google finished them
 * - `duration` is the string `"505s"`
 * - a routing failure for one pair is not an HTTP failure
 * - and a fallback must never be cached
 */
class RoutingTest extends TestCase
{
    // The schema is needed for one reason only: when no key is in the config
    // the driver looks for the `settings` row, and an absent table is a
    // different failure from an absent key.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['routing.google.key' => 'test-key']);
    }

    #[Test]
    public function haversine_measures_a_known_distance(): void
    {
        // Cairo Tower to the Pyramids: a little over 11 km as the crow flies.
        $km = (new HaversineRouter)->km(
            new Coordinate(30.0459, 31.2243),
            new Coordinate(29.9792, 31.1342),
        );

        $this->assertGreaterThan(10.5, $km);
        $this->assertLessThan(12.5, $km);
    }

    #[Test]
    public function haversine_refuses_to_invent_a_duration(): void
    {
        $legs = (new HaversineRouter)->matrix(
            new Coordinate(30.06, 31.33),
            ['a' => new Coordinate(30.04, 31.23)],
        );

        $this->assertNull($legs['a']->minutes, 'A straight line cannot know how long a drive takes.');
        $this->assertTrue($legs['a']->isEstimate());
    }

    #[Test]
    public function google_results_are_matched_on_the_index_not_on_the_position(): void
    {
        // Exactly what the live API returns: the second destination first. A
        // reader that trusted the array order would swap these two laundries.
        Http::fake([
            '*' => Http::response([
                ['originIndex' => 0, 'destinationIndex' => 1, 'status' => [], 'distanceMeters' => 4291, 'duration' => '505s', 'condition' => 'ROUTE_EXISTS'],
                ['originIndex' => 0, 'destinationIndex' => 0, 'status' => [], 'distanceMeters' => 13767, 'duration' => '1304s', 'condition' => 'ROUTE_EXISTS'],
            ]),
        ]);

        $legs = $this->google()->matrix(new Coordinate(30.0606, 31.33), [
            'downtown' => new Coordinate(30.0444, 31.2357),
            'heliopolis' => new Coordinate(30.0808, 31.3228),
        ]);

        $this->assertEqualsWithDelta(13.767, $legs['downtown']->km, 0.001);
        $this->assertEqualsWithDelta(4.291, $legs['heliopolis']->km, 0.001);
    }

    #[Test]
    public function the_duration_string_becomes_minutes(): void
    {
        Http::fake([
            '*' => Http::response([
                ['originIndex' => 0, 'destinationIndex' => 0, 'status' => [], 'distanceMeters' => 4291, 'duration' => '505s', 'condition' => 'ROUTE_EXISTS'],
            ]),
        ]);

        $leg = $this->google()->matrix(new Coordinate(30.06, 31.33), ['a' => new Coordinate(30.08, 31.32)])['a'];

        // 505 seconds is 8.416…; a leg keeps one decimal, because a tenth of a
        // minute is already finer than a traffic-free estimate deserves.
        $this->assertSame(8.4, $leg->minutes);
        $this->assertSame(RouteLeg::SOURCE_GOOGLE, $leg->source);
        $this->assertFalse($leg->isEstimate());
    }

    #[Test]
    public function a_pair_google_cannot_route_comes_back_unmeasured(): void
    {
        Http::fake([
            '*' => Http::response([
                ['originIndex' => 0, 'destinationIndex' => 0, 'status' => [], 'distanceMeters' => 4291, 'duration' => '505s', 'condition' => 'ROUTE_EXISTS'],
                ['originIndex' => 0, 'destinationIndex' => 1, 'status' => [], 'condition' => 'ROUTE_NOT_FOUND'],
            ]),
        ]);

        $legs = $this->google()->matrix(new Coordinate(30.06, 31.33), [
            'reachable' => new Coordinate(30.08, 31.32),
            'island' => new Coordinate(-40.0, 170.0),
        ]);

        $this->assertNotNull($legs['reachable']);
        $this->assertNull($legs['island'], 'ROUTE_NOT_FOUND is not a zero-kilometre route.');
    }

    #[Test]
    public function an_http_failure_never_throws_and_the_service_estimates_instead(): void
    {
        Http::fake(['*' => Http::response('nope', 503)]);
        config(['routing.driver' => 'google']);

        $leg = app(RoutingService::class)->between(
            new Coordinate(30.0459, 31.2243),
            new Coordinate(29.9792, 31.1342),
        );

        $this->assertTrue($leg->isEstimate());
        $this->assertGreaterThan(10.5, $leg->km);
    }

    #[Test]
    public function a_missing_key_falls_back_without_calling_anybody(): void
    {
        Http::fake();
        config(['routing.driver' => 'google', 'routing.google.key' => null]);

        $leg = app(RoutingService::class)->between(new Coordinate(30.06, 31.33), new Coordinate(30.04, 31.23));

        $this->assertTrue($leg->isEstimate());
        Http::assertNothingSent();
    }

    #[Test]
    public function a_measured_leg_is_cached_and_a_fallback_is_not(): void
    {
        config(['routing.driver' => 'google']);

        $from = new Coordinate(30.0606, 31.33);
        $to = new Coordinate(30.0444, 31.2357);
        $elsewhere = new Coordinate(30.0131, 31.2089);

        // One stub, because `Http::fake()` merges rather than replaces and the
        // first registered match wins — faking twice leaves the first stub
        // answering every request and the test silently proves nothing.
        $down = false;
        $metres = 13767;

        Http::fake(function () use (&$down, &$metres) {
            if ($down) {
                return Http::response('down', 500);
            }

            return Http::response([
                ['originIndex' => 0, 'destinationIndex' => 0, 'status' => [], 'distanceMeters' => $metres, 'duration' => '1304s', 'condition' => 'ROUTE_EXISTS'],
            ]);
        });

        $this->assertEqualsWithDelta(13.767, app(RoutingService::class)->between($from, $to)->km, 0.001);

        // The provider goes down. The measured leg is served from the cache.
        $down = true;

        $cached = app(RoutingService::class)->between($from, $to);
        $this->assertEqualsWithDelta(13.767, $cached->km, 0.001);
        $this->assertFalse($cached->isEstimate());

        // A pair with nothing cached estimates instead — and that estimate must
        // not be written. Caching a fallback for a day turns a thirty-second
        // outage into a day of undercharged deliveries.
        $this->assertTrue(app(RoutingService::class)->between($from, $elsewhere)->isEstimate());

        // Back up: the failed lookup must be retried, not remembered.
        $down = false;
        $metres = 19180;

        $retried = app(RoutingService::class)->between($from, $elsewhere);
        $this->assertFalse($retried->isEstimate(), 'The failed lookup should have been retried, not remembered.');
        $this->assertEqualsWithDelta(19.18, $retried->km, 0.001);
    }

    #[Test]
    public function two_pins_in_one_building_share_a_cached_lookup(): void
    {
        config(['routing.driver' => 'google']);

        Http::fake([
            '*' => Http::response([
                ['originIndex' => 0, 'destinationIndex' => 0, 'status' => [], 'distanceMeters' => 4291, 'duration' => '505s', 'condition' => 'ROUTE_EXISTS'],
            ]),
        ]);

        $laundry = new Coordinate(30.0808, 31.3228);

        app(RoutingService::class)->between(new Coordinate(30.06061111, 31.33001111), $laundry);
        app(RoutingService::class)->between(new Coordinate(30.06062222, 31.33002222), $laundry);

        // Four decimals is about eleven metres, and Distance Matrix is billed
        // per element.
        Http::assertSentCount(1);
    }

    #[Test]
    public function the_cache_survives_a_new_field_on_the_leg(): void
    {
        // Legs are cached as arrays, not as serialized objects. An array with a
        // key this version does not know still rebuilds; a serialized instance
        // of an older class shape would fatal on every hit until it expired.
        $leg = RouteLeg::fromArray(['km' => 3.5, 'minutes' => 7.0, 'source' => 'google', 'something_new' => 'x']);

        $this->assertNotNull($leg);
        $this->assertSame(3.5, $leg->km);
    }

    private function google(): GoogleDistanceMatrixRouter
    {
        return app(GoogleDistanceMatrixRouter::class);
    }
}
