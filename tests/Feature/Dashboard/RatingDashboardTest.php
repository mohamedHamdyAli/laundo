<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRating;
use App\Modules\Order\Services\OrderService;
use App\Modules\Report\Data\DateRange;
use App\Modules\Report\Services\LaundryReport;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The ratings screen, and the rating column on the laundry report.
 *
 * Unlike the driver and operations reports, this one is safe to give a laundry
 * owner: `OrderRating` carries a `laundry_id` and uses BelongsToLaundry, so the
 * scope does the work. The isolation test below is what proves that rather than
 * assumes it.
 *
 * The averages get the most attention. "Nobody has rated this laundry" and "this
 * laundry is rated badly" are different claims, and a report that returns 0 for
 * the first accuses a laundry of something that never happened.
 */
class RatingDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $tenant;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->customer = $this->customer('01055550001');
    }

    /**
     * A rating written straight in. The API path is covered by RatingTest; here
     * the subject is what the dashboard does with rows that already exist.
     */
    private function rating(int $overall, array $extra = [], ?int $laundryId = null): OrderRating
    {
        $order = $this->bareOrder($laundryId);

        return OrderRating::withoutGlobalScopes()->create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'laundry_id' => $laundryId ?? $this->tenant['laundry']->id,
            'overall' => $overall,
        ] + $extra);
    }

    /**
     * A completed order for a given laundry.
     *
     * Through OrderService rather than a hand-written insert: `orders` has
     * several NOT NULL columns a literal create has to guess at, and a test that
     * lists them drifts from the schema the first time one is added.
     */
    private function bareOrder(?int $laundryId = null): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        // Forced rather than transitioned: the lifecycle is covered elsewhere and
        // what matters here is a finished order attached to a known laundry.
        $order->forceFill([
            'laundry_id' => $laundryId ?? $this->tenant['laundry']->id,
            'status' => 'completed',
        ])->save();

        return $order->fresh();
    }

    // ------------------------------------------------------------ reaching it

    #[Test]
    public function a_super_admin_and_a_laundry_owner_can_both_open_it(): void
    {
        $this->rating(5);

        $this->actingAs($this->superAdmin())->get('/admin/rating')->assertOk();
        $this->actingAs($this->tenant['owner'])->get('/admin/rating')->assertOk();
    }

    #[Test]
    public function a_customer_cannot_open_it(): void
    {
        $this->actingAs($this->customer)->get('/admin/rating')->assertForbidden();
    }

    #[Test]
    public function a_laundry_owner_sees_only_their_own_verdicts(): void
    {
        $other = $this->laundryWithOwner('B', '01011110003', '01011110004');

        $this->rating(5);
        $this->rating(1, [], $other['laundry']->id);

        // Through the scope, not a hand-written where. If this ever fails, one
        // laundry is reading another's customer feedback.
        $mine = $this->actingAs($this->tenant['owner'])
            ->get('/admin/rating')->assertOk()->viewData('ratings');

        $this->assertCount(1, $mine);
        $this->assertSame(5, $mine->first()->overall);

        $theirs = $this->actingAs($other['owner'])
            ->get('/admin/rating')->assertOk()->viewData('ratings');

        $this->assertCount(1, $theirs);
        $this->assertSame(1, $theirs->first()->overall);
    }

    // --------------------------------------------------------------- summary

    #[Test]
    public function the_average_is_null_before_anybody_has_rated(): void
    {
        $summary = $this->actingAs($this->superAdmin())
            ->get('/admin/rating')->assertOk()->viewData('summary');

        // Not 0. Zero on a five-point scale is a verdict, and nobody gave it.
        $this->assertNull($summary['average']);
        $this->assertSame(0, $summary['total']);
    }

    #[Test]
    public function the_summary_reports_the_average_the_count_and_the_unhappy(): void
    {
        $this->rating(5);
        $this->rating(4);
        $this->rating(2);
        $this->rating(1, ['comment' => 'وصل متأخر']);

        $summary = $this->actingAs($this->superAdmin())
            ->get('/admin/rating')->assertOk()->viewData('summary');

        $this->assertSame(4, $summary['total']);
        $this->assertSame(3.0, $summary['average']);
        // Two stars or fewer.
        $this->assertSame(2, $summary['poor']);
        $this->assertSame(1, $summary['commented']);
    }

    #[Test]
    public function a_skipped_aspect_is_left_out_of_that_aspects_average(): void
    {
        // Two rated the service, one skipped it. The skip must not count as a
        // zero — that would drag the average down while looking like data.
        $this->rating(5, ['service_quality' => 5]);
        $this->rating(3, ['service_quality' => 3]);
        $this->rating(4);

        $summary = $this->actingAs($this->superAdmin())
            ->get('/admin/rating')->assertOk()->viewData('summary');

        $this->assertSame(4.0, $summary['aspects']['service_quality']);
        // Nobody scored these at all.
        $this->assertNull($summary['aspects']['delivery']);
        $this->assertNull($summary['aspects']['timing']);
    }

    #[Test]
    public function the_chips_are_tallied(): void
    {
        $this->rating(5, ['tags' => ['fast_delivery', 'very_clean']]);
        $this->rating(4, ['tags' => ['fast_delivery']]);
        $this->rating(5, ['tags' => null]);

        $counts = collect(
            $this->actingAs($this->superAdmin())
                ->get('/admin/rating')->assertOk()->viewData('tagCounts')
        )->mapWithKeys(fn ($e) => [$e['tag']->value => $e['count']]);

        // The only question these exist to answer.
        $this->assertSame(2, $counts['fast_delivery']);
        $this->assertSame(1, $counts['very_clean']);
        $this->assertSame(0, $counts['easy_app']);
    }

    #[Test]
    public function an_unrecognised_stored_tag_does_not_break_the_tally(): void
    {
        // A tag removed from the enum later. The row is historical and the rest
        // of it is still true, so the page must render.
        $this->rating(5, ['tags' => ['fast_delivery', 'retired_tag']]);

        $counts = collect(
            $this->actingAs($this->superAdmin())
                ->get('/admin/rating')->assertOk()->viewData('tagCounts')
        )->mapWithKeys(fn ($e) => [$e['tag']->value => $e['count']]);

        $this->assertSame(1, $counts['fast_delivery']);
    }

    // ---------------------------------------------------------------- filters

    #[Test]
    public function the_band_filter_narrows_to_the_ones_worth_reading(): void
    {
        $this->rating(5);
        $this->rating(2, ['comment' => 'مش راضي']);
        $this->rating(4, ['comment' => 'كويس']);

        $this->actingAs($this->superAdmin());

        $poor = $this->get('/admin/rating?band=poor')->assertOk()->viewData('ratings');
        $this->assertCount(1, $poor);
        $this->assertSame(2, $poor->first()->overall);

        $commented = $this->get('/admin/rating?band=commented')->assertOk()->viewData('ratings');
        $this->assertCount(2, $commented);

        $good = $this->get('/admin/rating?band=good')->assertOk()->viewData('ratings');
        $this->assertCount(2, $good);
    }

    #[Test]
    public function search_finds_a_rating_by_its_comment(): void
    {
        $this->rating(2, ['comment' => 'الطلب وصل متأخر جداً']);
        $this->rating(5, ['comment' => 'ممتاز']);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/admin/rating/search?query=متأخر', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        $this->assertStringContainsString('متأخر', $response->json('table'));
        $this->assertStringNotContainsString('ممتاز', $response->json('table'));
    }

    // ------------------------------------------------- the laundry report link

    #[Test]
    public function the_laundry_report_now_says_whether_customers_were_happy(): void
    {
        $this->rating(5);
        $this->rating(3);
        $this->rating(1);

        $this->actingAs($this->superAdmin());

        $rows = app(LaundryReport::class)->performance(DateRange::lastDays(7));

        $this->assertCount(1, $rows);
        $this->assertSame(3.0, $rows[0]['average_rating']);
        $this->assertSame(3, $rows[0]['ratings']);
        $this->assertSame(1, $rows[0]['unhappy']);
    }

    #[Test]
    public function an_unrated_laundry_reads_as_unrated_not_as_the_worst_one(): void
    {
        // Two ratings for A, none for B. If B came back as 0 it would sort below
        // a laundry customers actively disliked.
        $other = $this->laundryWithOwner('B', '01011110003', '01011110004');

        $this->rating(5);
        // An order for B with no rating at all.
        $this->bareOrder($other['laundry']->id);

        $this->actingAs($this->superAdmin());

        $rows = collect(app(LaundryReport::class)->performance(DateRange::lastDays(7)))
            ->keyBy('laundry');

        $this->assertSame(5.0, $rows['Laundry A']['average_rating']);
        $this->assertNull($rows['Laundry B']['average_rating']);
        $this->assertSame(0, $rows['Laundry B']['ratings']);
    }

    #[Test]
    public function a_laundry_owner_reading_the_report_sees_only_their_own_rating(): void
    {
        $other = $this->laundryWithOwner('B', '01011110003', '01011110004');

        $this->rating(5);
        $this->rating(1, [], $other['laundry']->id);

        $rows = $this->actingAs($this->tenant['owner'])
            ->get('/admin/report/laundries')->assertOk()->viewData('rows');

        $this->assertCount(1, $rows);
        $this->assertSame(5.0, $rows[0]['average_rating']);
    }
}
