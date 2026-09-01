<?php

namespace Tests\Unit;

use App\Modules\Order\Enums\OrderStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The transition table.
 *
 * Worth testing on its own because two business rules live in it and nowhere
 * else: cancellation stops at pickup, and `returned` is a terminal state
 * distinct from `cancelled`.
 */
class OrderStatusTest extends TestCase
{
    #[Test]
    public function an_order_cannot_skip_the_pipeline(): void
    {
        $this->assertFalse(OrderStatus::AwaitingPickup->canTransitionTo(OrderStatus::Cleaning));
        $this->assertFalse(OrderStatus::AwaitingPickup->canTransitionTo(OrderStatus::Delivered));
        $this->assertFalse(OrderStatus::PickedUp->canTransitionTo(OrderStatus::ReadyForDelivery));

        $this->assertTrue(OrderStatus::AwaitingPickup->canTransitionTo(OrderStatus::DriverOnWay));
        $this->assertTrue(OrderStatus::DriverOnWay->canTransitionTo(OrderStatus::PickedUp));
    }

    #[Test]
    public function cancellation_stops_at_pickup(): void
    {
        // Before the driver has the clothes.
        $this->assertTrue(OrderStatus::AwaitingPickup->isCancellable());
        $this->assertTrue(OrderStatus::DriverOnWay->isCancellable());

        // Once they are with us, the order runs to its end.
        $this->assertFalse(OrderStatus::PickedUp->isCancellable());
        $this->assertFalse(OrderStatus::Reviewed->isCancellable());
        $this->assertFalse(OrderStatus::Cleaning->isCancellable());
        $this->assertFalse(OrderStatus::ReadyForDelivery->isCancellable());
    }

    #[Test]
    public function custody_begins_at_pickup(): void
    {
        $this->assertFalse(OrderStatus::AwaitingPickup->isInCustody());
        $this->assertFalse(OrderStatus::DriverOnWay->isInCustody());
        $this->assertFalse(OrderStatus::Cancelled->isInCustody());

        $this->assertTrue(OrderStatus::PickedUp->isInCustody());
        $this->assertTrue(OrderStatus::Cleaning->isInCustody());
    }

    #[Test]
    public function returned_is_terminal_and_not_a_cancellation(): void
    {
        $this->assertTrue(OrderStatus::Returned->isTerminal());
        $this->assertTrue(OrderStatus::Cancelled->isTerminal());
        $this->assertTrue(OrderStatus::Completed->isTerminal());

        // The operator's escape hatch, reachable only around the review — never
        // from before pickup, where cancellation is the customer's route.
        $this->assertTrue(OrderStatus::Reviewed->canTransitionTo(OrderStatus::Returned));
        $this->assertTrue(OrderStatus::ReviewDisputed->canTransitionTo(OrderStatus::Returned));
        $this->assertFalse(OrderStatus::AwaitingPickup->canTransitionTo(OrderStatus::Returned));
        $this->assertFalse(OrderStatus::Confirmed->canTransitionTo(OrderStatus::Returned));
    }

    #[Test]
    public function confirmation_releases_cleaning_and_payment_does_not_gate_it(): void
    {
        // The decision this phase turns on: the customer agreeing is what lets
        // the work start. Money is tracked in payment_status, beside the
        // pipeline, because «الدفع نقدًا عند الاستلام» means a COD customer pays
        // long after the clothes are washed.
        $this->assertTrue(OrderStatus::Reviewed->canTransitionTo(OrderStatus::Confirmed));
        $this->assertTrue(OrderStatus::Confirmed->canTransitionTo(OrderStatus::Cleaning));

        // Nothing may skip the customer's agreement.
        $this->assertFalse(OrderStatus::Reviewed->canTransitionTo(OrderStatus::Cleaning));
        $this->assertFalse(OrderStatus::PickedUp->canTransitionTo(OrderStatus::Confirmed));
    }

    #[Test]
    public function a_questioned_price_goes_back_for_a_second_count(): void
    {
        $this->assertTrue(OrderStatus::Reviewed->canTransitionTo(OrderStatus::ReviewDisputed));
        $this->assertTrue(OrderStatus::ReviewDisputed->canTransitionTo(OrderStatus::Reviewed));

        // A dispute is a detour, not an exit: it cannot jump straight to the work.
        $this->assertFalse(OrderStatus::ReviewDisputed->canTransitionTo(OrderStatus::Confirmed));
        $this->assertFalse(OrderStatus::ReviewDisputed->canTransitionTo(OrderStatus::Cleaning));
    }

    #[Test]
    public function the_laundry_may_price_only_while_the_pieces_await_counting(): void
    {
        $this->assertTrue(OrderStatus::PickedUp->isReviewable());
        $this->assertTrue(OrderStatus::ReviewDisputed->isReviewable());

        $this->assertFalse(OrderStatus::Reviewed->isReviewable());
        $this->assertFalse(OrderStatus::Confirmed->isReviewable());
        $this->assertFalse(OrderStatus::Cleaning->isReviewable());
        $this->assertFalse(OrderStatus::AwaitingPickup->isReviewable());
    }

    #[Test]
    public function exactly_one_state_waits_on_the_customer(): void
    {
        $waiting = array_filter(
            OrderStatus::cases(),
            fn (OrderStatus $s) => $s->isAwaitingCustomer()
        );

        $this->assertSame([OrderStatus::Reviewed], array_values($waiting));
    }

    #[Test]
    public function the_cancellable_list_is_derived_from_the_transition_table(): void
    {
        // The guarantee that the two can never disagree: every status that reports
        // itself cancellable must have Cancelled among its allowed moves.
        foreach (OrderStatus::cases() as $status) {
            $this->assertSame(
                in_array(OrderStatus::Cancelled, $status->allowedNext(), true),
                $status->isCancellable(),
                "{$status->value} disagrees with its own transition table"
            );
        }
    }

    #[Test]
    public function the_tracking_timeline_matches_the_design(): void
    {
        $steps = array_map(fn (OrderStatus $s) => $s->value, OrderStatus::trackingSteps());

        $this->assertSame([
            'picked_up', 'reviewed', 'confirmed', 'cleaning', 'ready_for_delivery', 'delivered',
        ], $steps);

        // A dispute is a detour, not a milestone — drawing it would make the line
        // grow when things go wrong.
        $this->assertNotContains('review_disputed', $steps);
    }
}
