<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Enums\PieceCheckStep;
use App\Modules\Order\Enums\PieceCountSource;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Models\PieceDiscrepancy;
use App\Modules\Order\Repositories\OrderRepository;
use App\Modules\User\Models\User;
use App\Support\LaundryContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;
use RuntimeException;

/**
 * «عدد القطع المستلمة», checked.
 *
 * The driver confirms a count at every handover but the last, and for the life
 * of the project that number was stored and compared with nothing: a customer
 * ordering one piece and a driver counting two raised no flag anywhere. Now
 * every count of the same pieces is measured against **the last number
 * somebody stood behind** (the owner's rule):
 *
 *   - collecting from the customer → what the customer ordered;
 *   - handing over to the laundry  → what was collected;
 *   - the laundry's review         → what was handed to it;
 *   - collecting from the laundry  → what the laundry counted at its review.
 *
 * Wherever the platform has already looked into a disagreement, the count it
 * settled is the number that stands, so a typo corrected at review is not
 * raised again one step later. A disagreement names the step where a piece
 * appeared or went missing, instead of every later step repeating the first.
 *
 * Nothing is refused. The driver is at a doorstep and the laundry is pricing a
 * bag; the difference is recorded as a `PieceDiscrepancy`, raised with the
 * platform and the laundry, and stays open until somebody at the platform has
 * looked into it and said what they found and how many pieces there really are.
 */
class PieceCheck
{
    use Localizable;

    public function __construct(private readonly OrderRepository $orders) {}

    /**
     * What this leg's count should be, and where that number comes from —
     * `[null, null]` when there is nothing to measure it against (a service
     * priced after inspection lists no pieces, so its first count is the first
     * number anybody has).
     *
     * @return array{0: int|null, 1: PieceCountSource|null}
     */
    public function expectedFor(OrderTask $task): array
    {
        if (! $task->type->countsPieces()) {
            return [null, null];
        }

        $order = $task->order;

        // Once the laundry has reviewed the order, its count is the one that
        // stands — or, if the platform looked into it, what the platform found.
        if ($task->type === TaskType::CollectFromLaundry) {
            $settled = $this->laundryCount($order);

            if ($settled !== null) {
                return $settled;
            }
        }

        $previous = $this->orders->lastCountedLegBefore($task)?->countedPieces();

        if ($previous !== null) {
            return [$previous, PieceCountSource::PreviousLeg];
        }

        if ((int) $order->estimated_items_count > 0) {
            return [(int) $order->estimated_items_count, PieceCountSource::CustomerOrder];
        }

        return [null, null];
    }

    /**
     * A leg has just been completed with its count and expectation stamped.
     * Returns the disagreements to announce once the handover has committed:
     * its own, and — for the handover to the laundry, when the laundry has
     * already reviewed — the review's against it.
     *
     * @return list<PieceDiscrepancy>
     */
    public function afterHandover(OrderTask $task, int $counted, ?int $expected, ?PieceCountSource $source): array
    {
        $raised = [];

        if ($expected !== null && $source !== null && $counted !== $expected) {
            // By the leg, not a bare create: a leg disagrees at most once, and
            // the column is unique — a retry that got this far says nothing new.
            $raised[] = PieceDiscrepancy::firstOrCreate(['order_task_id' => $task->id], [
                'order_id' => $task->order_id,
                'step' => PieceCheckStep::forTask($task->type),
                'counted' => $counted,
                'expected' => $expected,
                'expected_source' => $source,
                'counted_by' => $task->driver_id,
            ]);
        }

        // The review can come before this handover is confirmed — the laundry
        // may count the bag while the driver is still on the app. Checked here
        // too, so the review is always held to what was actually handed over.
        $order = $task->order;

        if ($task->type === TaskType::DeliverToLaundry && $order->final_items_count !== null) {
            $review = $this->afterReview($order, (int) $order->final_items_count);

            if ($review) {
                $raised[] = $review;
            }
        }

        return $raised;
    }

    /**
     * The laundry has counted the pieces at its review.
     *
     * Measured against what was handed to it — once the handover to the
     * laundry has been confirmed; until then there is nothing handed over to
     * hold it to, and `afterHandover()` checks it when there is. A review done
     * again updates an open disagreement (closing it if the laundry now agrees
     * — it recounted and found the piece), and is held to what the platform
     * settled on a reviewed one. Returns a disagreement only when it is new,
     * for the caller to announce once the review has committed.
     */
    public function afterReview(Order $order, int $counted, ?User $by = null): ?PieceDiscrepancy
    {
        $handover = $this->orders->completedLeg($order, TaskType::DeliverToLaundry);
        $handed = $handover?->countedPieces();

        if ($handed === null) {
            return null;
        }

        $latest = $this->orders->latestReviewDiscrepancy($order);

        if ($latest && $latest->open) {
            $latest->update($counted === $handed
                ? [
                    'counted' => $counted,
                    'expected' => $handed,
                    'open' => false,
                    'resolved_at' => now(),
                    'note' => $this->inPanelLanguage(fn () => __('The laundry counted again and found :count.', ['count' => $counted])),
                    'confirmed_count' => $counted,
                ]
                : array_filter([
                    'counted' => $counted,
                    'expected' => $handed,
                    // Kept when nobody is named — a review from a job or a
                    // handover must not erase who counted.
                    'counted_by' => $by?->id,
                ], fn ($value) => $value !== null));

            return null;
        }

        // The platform already looked into this order's review and said how
        // many there really are: a later review is held to that.
        [$expected, $source] = $latest && $latest->confirmed_count !== null
            ? [$latest->confirmed_count, PieceCountSource::Settled]
            : [$handed, PieceCountSource::PreviousLeg];

        if ($counted === $expected) {
            return null;
        }

        return PieceDiscrepancy::create([
            'order_id' => $order->id,
            'order_task_id' => null,
            'step' => PieceCheckStep::LaundryReview,
            'counted' => $counted,
            'expected' => $expected,
            'expected_source' => $source,
            'counted_by' => $by?->id,
        ]);
    }

    /**
     * «تمت المراجعة», by id — looked up through the tenant-scoped Order, so
     * another laundry's id is a 404 for every caller, not only the controller.
     */
    public function resolveById(int|string $id, User $by, string $note, int $found): PieceDiscrepancy
    {
        return $this->resolve($this->orders->findDiscrepancy($id), $by, $note, $found);
    }

    /**
     * «تمت المراجعة» — somebody at the platform looked into it and says how
     * many pieces there really are.
     *
     * The platform's call, not the laundry's: a piece that went missing may
     * have gone missing there, and the one it disappeared on is not the one to
     * close the question. Checked on the reviewer being recorded, not only on
     * whoever is signed in — a call from a job or the console has nobody signed
     * in, and would otherwise let anybody be stamped as the reviewer.
     *
     * The note is required — closed without one, the next person to open the
     * order learns only that somebody pressed a button. The count found is what
     * the next count is measured against.
     */
    public function resolve(PieceDiscrepancy $discrepancy, User $by, string $note, int $found): PieceDiscrepancy
    {
        if ($by->laundry_id !== null || LaundryContext::isTenant()) {
            throw new RuntimeException('platform_only');
        }

        return DB::transaction(function () use ($discrepancy, $by, $note, $found) {
            // Re-read under a lock: two reviews pressed at once must not both
            // find it open and the second silently rewrite who closed it.
            $current = $this->orders->lockDiscrepancy($discrepancy->id);

            if (! $current || ! $current->open) {
                throw new RuntimeException('nothing_to_resolve');
            }

            $current->update([
                'open' => false,
                'resolved_at' => now(),
                'resolved_by' => $by->id,
                'note' => trim($note),
                'confirmed_count' => $found,
            ]);

            return $current->refresh();
        });
    }

    /**
     * The laundry's count that stands for the collection: what the platform
     * found, if it looked into the review; otherwise the review itself.
     *
     * @return array{0: int, 1: PieceCountSource}|null
     */
    private function laundryCount(Order $order): ?array
    {
        $latest = $this->orders->latestReviewDiscrepancy($order);

        if ($latest && ! $latest->open && $latest->confirmed_count !== null) {
            return [$latest->confirmed_count, PieceCountSource::Settled];
        }

        return $order->final_items_count !== null
            ? [(int) $order->final_items_count, PieceCountSource::LaundryReview]
            : null;
    }

    /**
     * A note stored for the platform to read, in the panel's language rather
     * than whichever the laundry's session happens to be in.
     *
     * @param  callable(): string  $words
     */
    private function inPanelLanguage(callable $words): string
    {
        return (string) $this->withLocale((string) getDefaultLanguage('code'), $words);
    }
}
