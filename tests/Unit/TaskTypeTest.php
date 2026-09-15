<?php

namespace Tests\Unit;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shape of the four legs.
 *
 * Worth testing apart from the database because several business rules are
 * encoded here and nowhere else: which legs take a signature, which count pieces,
 * which move the order, and which failure stops everything.
 */
class TaskTypeTest extends TestCase
{
    #[Test]
    public function the_chain_is_four_legs_in_a_fixed_order(): void
    {
        $chain = TaskType::chain();

        $this->assertCount(4, $chain);
        $this->assertSame([
            'pickup_from_customer', 'deliver_to_laundry',
            'collect_from_laundry', 'deliver_to_customer',
        ], array_map(fn (TaskType $t) => $t->value, $chain));

        // The sequence numbers must match the position, or predecessorComplete()
        // would guard the wrong leg.
        foreach ($chain as $index => $type) {
            $this->assertSame($index + 1, $type->sequence());
        }
    }

    #[Test]
    public function only_the_customer_facing_legs_take_a_signature(): void
    {
        // A laundry hands over to a colleague, not to a customer.
        $this->assertTrue(TaskType::PickupFromCustomer->requiresSignature());
        $this->assertTrue(TaskType::DeliverToCustomer->requiresSignature());

        $this->assertFalse(TaskType::DeliverToLaundry->requiresSignature());
        $this->assertFalse(TaskType::CollectFromLaundry->requiresSignature());
    }

    #[Test]
    public function the_final_leg_does_not_re_open_the_piece_count(): void
    {
        // The count was settled by the laundry's review and agreed by the
        // customer. Asking a driver to re-open it on the doorstep would invite a
        // dispute nobody standing there can resolve.
        $this->assertFalse(TaskType::DeliverToCustomer->countsPieces());

        $this->assertTrue(TaskType::PickupFromCustomer->countsPieces());
        $this->assertTrue(TaskType::DeliverToLaundry->countsPieces());
        $this->assertTrue(TaskType::CollectFromLaundry->countsPieces());
    }

    #[Test]
    public function money_changes_hands_on_the_last_leg_only(): void
    {
        $collecting = array_filter(TaskType::cases(), fn (TaskType $t) => $t->collectsPayment());

        $this->assertSame([TaskType::DeliverToCustomer], array_values($collecting));
    }

    #[Test]
    public function every_leg_moves_the_order(): void
    {
        /*
         * This test used to assert the opposite for legs 2 and 3, and it was
         * wrong in the same way the code was: «after the collection it is
         * already ready» — and nothing put it there.
         *
         * `allowedNext()` runs
         * `confirmed → cleaning → ready_for_delivery → delivered`, so with those
         * two returning null, leg 4 asked for `confirmed → delivered`, the table
         * refused, and the order was stranded at `confirmed` for ever with
         * nobody paid. The chain only closes if every leg says where it lands.
         */
        $this->assertSame(OrderStatus::PickedUp, TaskType::PickupFromCustomer->completesInto());
        $this->assertSame(OrderStatus::Cleaning, TaskType::DeliverToLaundry->completesInto());
        $this->assertSame(OrderStatus::ReadyForDelivery, TaskType::CollectFromLaundry->completesInto());
        $this->assertSame(OrderStatus::Delivered, TaskType::DeliverToCustomer->completesInto());
    }

    #[Test]
    public function the_legs_walk_the_status_chain_in_order(): void
    {
        // The property that matters more than the individual values: each leg
        // must land exactly where the previous one's status allows, or the chain
        // breaks again somewhere in the middle and the symptom is an order that
        // stops rather than an error.
        $previous = OrderStatus::DriverOnWay;

        foreach (TaskType::cases() as $leg) {
            $target = $leg->completesInto();

            if ($leg === TaskType::PickupFromCustomer) {
                $previous = $target;

                continue;
            }

            // The review sits between leg 1 and leg 2 and is driven by the
            // laundry, so the walk resumes from `confirmed`.
            $from = $previous === OrderStatus::PickedUp ? OrderStatus::Confirmed : $previous;

            $this->assertTrue(
                $from->canTransitionTo($target),
                "«{$from->value}» cannot reach «{$target->value}», so {$leg->value} would strand the order"
            );

            $previous = $target;
        }
    }

    #[Test]
    public function every_leg_files_its_photos_under_a_distinct_type(): void
    {
        $types = array_map(fn (TaskType $t) => $t->mediaType(), TaskType::cases());

        $this->assertSame($types, array_unique($types));

        // And each is one order_media already declares, so no migration is needed.
        foreach ($types as $type) {
            $this->assertContains($type, ['pickup', 'laundry', 'ready', 'delivery']);
        }
    }

    #[Test]
    public function the_collection_and_delivery_filters_split_the_four_evenly(): void
    {
        $collections = array_filter(TaskType::cases(), fn (TaskType $t) => $t->isCollection());
        $deliveries = array_filter(TaskType::cases(), fn (TaskType $t) => ! $t->isCollection());

        $this->assertCount(2, $collections);
        $this->assertCount(2, $deliveries);
    }

    #[Test]
    public function a_count_dispute_is_the_only_failure_that_halts_the_order(): void
    {
        // Sending another driver would move clothes that are already in dispute.
        $this->assertTrue(TaskFailureReason::PieceCountMismatch->haltsTheOrder());

        foreach ([
            TaskFailureReason::CustomerUnavailable,
            TaskFailureReason::WrongAddress,
            TaskFailureReason::CustomerPostponed,
            TaskFailureReason::Other,
        ] as $reason) {
            $this->assertFalse($reason->haltsTheOrder(), $reason->value.' should not halt the order');
        }
    }

    #[Test]
    public function only_an_assigned_task_can_be_started(): void
    {
        $this->assertTrue(TaskStatus::Assigned->isStartable());

        // A queued task has no driver to start it; a started one is already going.
        $this->assertFalse(TaskStatus::Pending->isStartable());
        $this->assertFalse(TaskStatus::Started->isStartable());
        $this->assertFalse(TaskStatus::Completed->isStartable());
        $this->assertFalse(TaskStatus::Failed->isStartable());
    }

    #[Test]
    public function the_dispatch_queue_is_exactly_the_pending_tasks(): void
    {
        $queued = array_filter(TaskStatus::cases(), fn (TaskStatus $s) => $s->isUnassigned());

        $this->assertSame([TaskStatus::Pending], array_values($queued));

        // Open and finished partition the five states with nothing left over.
        foreach (TaskStatus::cases() as $status) {
            $this->assertNotSame(
                $status->isOpen(),
                $status->isFinished(),
                $status->value.' is both open and finished, or neither'
            );
        }
    }
}
