<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRating;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «ما رأيك في تجربتك؟».
 *
 * The subject is the laundry, and the aspects sit in their own columns because
 * «التوصيل والاستلام» describes the driver rather than the laundry — so a laundry
 * is not marked down for a late driver, and that score can be attributed properly
 * later.
 *
 * Most of what is asserted here protects the *figure* rather than the flow: an
 * average that moves when somebody taps twice, or that counts a rating given
 * before the clothes came back, is worse than having no rating at all.
 */
class RatingTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    /** @var array<string, mixed> */
    private array $tenant;

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
        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);
        $this->customer = $this->customer('+201055550001');
    }

    /**
     * An order carried all the way to a state a rating is allowed on.
     */
    private function finishedOrder(OrderStatus $stopAt = OrderStatus::Completed): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $order->forceFill(['laundry_id' => $this->tenant['laundry']->id])->save();

        $machine = app(OrderStateMachine::class);

        foreach ([
            OrderStatus::DriverOnWay,
            OrderStatus::PickedUp,
            OrderStatus::Reviewed,
            OrderStatus::Confirmed,
            OrderStatus::Cleaning,
            OrderStatus::ReadyForDelivery,
            OrderStatus::Delivered,
            OrderStatus::Completed,
        ] as $status) {
            $order = $machine->transition($order->fresh(), $status, 'admin');

            if ($status === $stopAt) {
                break;
            }
        }

        return $order->fresh();
    }

    // ------------------------------------------------------------- the happy path

    #[Test]
    public function a_customer_can_rate_a_completed_order(): void
    {
        $order = $this->finishedOrder();

        $response = $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", [
                'overall' => 5,
                'service_quality' => 5,
                'delivery' => 4,
                'timing' => 3,
                'tags' => ['fast_delivery', 'very_clean'],
                'comment' => 'كل حاجة تمام',
            ])
            ->assertCreated();

        $this->assertSame(5, $response->json('data.overall'));
        $this->assertSame(4, $response->json('data.delivery'));
        $this->assertCount(2, $response->json('data.tags'));

        $rating = OrderRating::withoutGlobalScopes()->firstOrFail();

        // Copied from the order, never from the payload — it is the only field
        // whose value changes somebody else's numbers.
        $this->assertSame($this->tenant['laundry']->id, $rating->laundry_id);
        $this->assertSame($this->customer->id, $rating->user_id);
    }

    #[Test]
    public function the_three_aspects_are_optional_because_the_design_offers_skip(): void
    {
        $order = $this->finishedOrder();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", ['overall' => 4])
            ->assertCreated();

        $rating = OrderRating::withoutGlobalScopes()->firstOrFail();

        // Null, not zero. Zero is not a score the five stars can produce, and a 0
        // in the column would drag every average down while looking like data.
        $this->assertSame(4, $rating->overall);
        $this->assertNull($rating->service_quality);
        $this->assertNull($rating->delivery);
        $this->assertNull($rating->timing);
        $this->assertFalse($rating->hasAspectDetail());
    }

    #[Test]
    public function a_rating_can_be_read_back(): void
    {
        $order = $this->finishedOrder();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", ['overall' => 3, 'comment' => 'مقبول'])
            ->assertCreated();

        $response = $this->actingAs($this->customer)
            ->getJson("/api/v1/orders/{$order->id}/rating")
            ->assertOk();

        $this->assertSame(3, $response->json('data.rating.overall'));
        $this->assertSame('مقبول', $response->json('data.rating.comment'));
        $this->assertFalse($response->json('data.can_rate'));
    }

    #[Test]
    public function the_screen_is_told_whether_the_button_should_be_drawn(): void
    {
        $order = $this->finishedOrder();

        // A button that opens a screen which then refuses is worse than no button.
        $this->assertTrue(
            $this->actingAs($this->customer)
                ->getJson("/api/v1/orders/{$order->id}/rating")
                ->assertOk()
                ->json('data.can_rate')
        );
    }

    #[Test]
    public function the_chips_come_with_their_labels_so_two_apps_cannot_drift(): void
    {
        $order = $this->finishedOrder();

        $tags = $this->actingAs($this->customer)
            ->getJson("/api/v1/orders/{$order->id}/rating", ['lang' => 'ar'])
            ->assertOk()
            ->json('data.available_tags');

        $this->assertCount(5, $tags);
        $this->assertSame('fast_delivery', $tags[0]['value']);
        $this->assertNotSame('', trim((string) $tags[0]['label']));
    }

    // ------------------------------------------------------ protecting the figure

    #[Test]
    public function an_order_cannot_be_rated_twice(): void
    {
        $order = $this->finishedOrder();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", ['overall' => 5])
            ->assertCreated();

        // A slow connection makes a double tap ordinary. An average that moves
        // with the number of taps is not an average.
        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", ['overall' => 1])
            ->assertStatus(400);

        $this->assertSame(1, OrderRating::withoutGlobalScopes()->count());
        $this->assertSame(5, OrderRating::withoutGlobalScopes()->firstOrFail()->overall);
    }

    #[Test]
    public function an_unfinished_order_cannot_be_rated(): void
    {
        $order = $this->finishedOrder(OrderStatus::Cleaning);

        $this->assertSame(OrderStatus::Cleaning, $order->status);

        // A rating given while the clothes are still at the laundry is a guess,
        // and it would sit in the average as if it were a verdict.
        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", ['overall' => 5])
            ->assertStatus(400);

        $this->assertSame(0, OrderRating::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_delivered_but_unpaid_order_can_still_be_rated(): void
    {
        // The customer has the clothes. A cash order can sit at delivered for
        // days, and refusing would put the rating screen long after the experience.
        $order = $this->finishedOrder(OrderStatus::Delivered);

        $this->assertSame(OrderStatus::Delivered, $order->status);

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", ['overall' => 4])
            ->assertCreated();
    }

    #[Test]
    public function a_customer_cannot_rate_somebody_elses_order(): void
    {
        $order = $this->finishedOrder();
        $intruder = $this->customer('+201055550002');

        $this->actingAs($intruder)
            ->postJson("/api/v1/orders/{$order->id}/rating", ['overall' => 1])
            ->assertNotFound();

        $this->actingAs($intruder)
            ->getJson("/api/v1/orders/{$order->id}/rating")
            ->assertNotFound();

        $this->assertSame(0, OrderRating::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_laundry_cannot_be_named_by_the_client(): void
    {
        $other = $this->laundryWithOwner('B', '+201011110003', '+201011110004');
        $order = $this->finishedOrder();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", [
                'overall' => 1,
                // A forged field, aimed at another laundry's average.
                'laundry_id' => $other['laundry']->id,
            ])
            ->assertCreated();

        $this->assertSame(
            $this->tenant['laundry']->id,
            OrderRating::withoutGlobalScopes()->firstOrFail()->laundry_id
        );
    }

    // ----------------------------------------------------------------- validation

    #[Test]
    public function a_rating_with_no_score_is_refused(): void
    {
        $order = $this->finishedOrder();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", ['comment' => 'بدون درجة'])
            ->assertStatus(422);
    }

    #[Test]
    public function a_score_outside_one_to_five_is_refused(): void
    {
        $order = $this->finishedOrder();

        foreach ([0, 6, -1] as $bad) {
            $this->actingAs($this->customer)
                ->postJson("/api/v1/orders/{$order->id}/rating", ['overall' => $bad])
                ->assertStatus(422);
        }

        $this->assertSame(0, OrderRating::withoutGlobalScopes()->count());
    }

    #[Test]
    public function an_unknown_chip_is_refused_rather_than_stored(): void
    {
        $order = $this->finishedOrder();

        // Free text in this column would make "how often was this picked"
        // unanswerable, which is the only question it exists to answer.
        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", [
                'overall' => 5,
                'tags' => ['fast_delivery', 'invented_by_the_client'],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function duplicate_chips_are_stored_once(): void
    {
        $order = $this->finishedOrder();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", [
                'overall' => 5,
                'tags' => ['very_clean', 'very_clean', 'easy_app'],
            ])
            ->assertCreated();

        $this->assertSame(
            ['very_clean', 'easy_app'],
            OrderRating::withoutGlobalScopes()->firstOrFail()->tags
        );
    }

    #[Test]
    public function picking_no_chips_stores_null_rather_than_an_empty_array(): void
    {
        $order = $this->finishedOrder();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", ['overall' => 5, 'tags' => []])
            ->assertCreated();

        // One shape for "picked nothing", not two.
        $this->assertNull(OrderRating::withoutGlobalScopes()->firstOrFail()->tags);
    }

    #[Test]
    public function a_blank_comment_is_stored_as_null(): void
    {
        $order = $this->finishedOrder();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", ['overall' => 5, 'comment' => '   '])
            ->assertCreated();

        $this->assertNull(OrderRating::withoutGlobalScopes()->firstOrFail()->comment);
    }

    // ---------------------------------------------------------------- the tenant

    #[Test]
    public function a_laundry_reads_only_its_own_ratings(): void
    {
        $order = $this->finishedOrder();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", ['overall' => 5])
            ->assertCreated();

        $other = $this->laundryWithOwner('B', '+201011110003', '+201011110004');

        // The scope, not a hand-written where. Laundry B's owner must see nothing.
        $this->actingAs($other['owner']);
        $this->assertSame(0, OrderRating::count());

        $this->actingAs($this->tenant['owner']);
        $this->assertSame(1, OrderRating::count());
    }

    #[Test]
    public function a_low_score_is_findable_as_a_complaint(): void
    {
        $order = $this->finishedOrder();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", [
                'overall' => 2,
                'comment' => 'الطلب وصل متأخر',
            ])
            ->assertCreated();

        // The design's own placeholder is «اكتب ملاحظاتك أو شكواك هنا», so a low
        // score with a comment is a customer waiting for a reply.
        $this->assertSame(1, OrderRating::withoutGlobalScopes()->poor()->count());
    }

    #[Test]
    public function a_good_score_is_not_treated_as_a_complaint(): void
    {
        $order = $this->finishedOrder();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/rating", ['overall' => 3])
            ->assertCreated();

        $this->assertSame(0, OrderRating::withoutGlobalScopes()->poor()->count());
    }

    #[Test]
    public function a_guest_cannot_rate(): void
    {
        $order = $this->finishedOrder();

        $this->postJson("/api/v1/orders/{$order->id}/rating", ['overall' => 5])
            ->assertUnauthorized();
    }
}
