<?php

namespace App\Modules\LaundryService\Services;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\LaundryService\Models\LaundryService;
use App\Modules\LaundryService\Models\LaundryServiceRequest;
use App\Modules\Notification\Services\LaundryServiceRequestNotifier;
use App\Modules\User\Models\User;
use App\Support\LaundryContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A laundry's services change only when somebody approves.
 *
 * The laundry asks — from its services screen — and the ask is staged here as
 * one request per service. `laundry_services`, which `LaundryAssigner` reads,
 * does not move until a reviewer approves: a service the laundry asked to open
 * brings it no orders meanwhile, and one it asked to close keeps bringing them.
 *
 * **An operator editing a laundry's services directly is the approval** — the
 * same line `DriverRecordReview` draws. Routing the platform's own edit through
 * its own queue would be theatre; instead, a direct edit settles whatever the
 * laundry had asked about those services by marking it superseded.
 */
class LaundryServiceRequestReview
{
    public function __construct(private readonly LaundryServiceRequestNotifier $notifier) {}

    /**
     * File what a laundry wants its services to be, as requests.
     *
     * `$wanted` is the whole set the laundry ticked. Each difference from what
     * it offers now becomes a request; a service it is asking about again
     * replaces the earlier pending request; and a pending request the laundry
     * has since unticked back to how things stand is withdrawn.
     *
     * @param  array<int, int>  $wanted  service ids
     * @return array<int, LaundryServiceRequest> the requests filed
     */
    public function request(Laundry $laundry, array $wanted, ?User $actor = null): array
    {
        $wanted = array_values(array_unique(array_map('intval', $wanted)));

        $filed = DB::transaction(function () use ($laundry, $wanted, $actor) {
            $offered = $this->offered($laundry->id);
            $pending = LaundryServiceRequest::withoutGlobalScopes()
                ->where('laundry_id', $laundry->id)
                ->pending()
                ->lockForUpdate()
                ->get()
                ->keyBy('service_id');

            $filed = [];

            foreach (array_unique(array_merge($offered, $wanted, $pending->keys()->all())) as $serviceId) {
                $isOffered = in_array($serviceId, $offered, true);
                $isWanted = in_array($serviceId, $wanted, true);
                $existing = $pending->get($serviceId);

                $action = match (true) {
                    $isWanted && ! $isOffered => LaundryServiceRequest::OPEN,
                    ! $isWanted && $isOffered => LaundryServiceRequest::CLOSE,
                    default => null,
                };

                // Already asked, the same way: nothing new to say.
                if ($existing && $existing->action === $action) {
                    continue;
                }

                // Asked something else before — or nothing needs asking now.
                // Either way the old question is closed, and kept as history.
                if ($existing) {
                    $existing->forceFill(['status' => LaundryServiceRequest::SUPERSEDED])->save();
                }

                if ($action === null) {
                    continue;
                }

                // Unscoped create, laundry named explicitly: the creating hook
                // would write the actor's own laundry, which for a laundry
                // owner is this one anyway and for anybody else is wrong.
                $filed[] = LaundryServiceRequest::withoutGlobalScopes()->create([
                    'laundry_id' => $laundry->id,
                    'service_id' => $serviceId,
                    'action' => $action,
                    'status' => LaundryServiceRequest::PENDING,
                    'requested_by' => $actor?->id,
                ]);
            }

            return $filed;
        });

        $this->notifier->submitted($laundry, array_map(fn ($r) => $r->load('service'), $filed));

        return $filed;
    }

    /**
     * Apply the change and close the request.
     */
    public function approve(LaundryServiceRequest $request, User $reviewer): LaundryServiceRequest
    {
        $this->mayReview();

        $request = DB::transaction(function () use ($request, $reviewer) {
            // Re-read under a lock: two reviewers pressing at once must not
            // apply the same change twice or approve a request the other refused.
            $locked = LaundryServiceRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($request->id);

            if (! $locked->isPending()) {
                throw new RuntimeException('not_pending');
            }

            if ($locked->opens()) {
                LaundryService::withoutGlobalScopes()->updateOrCreate(
                    ['laundry_id' => $locked->laundry_id, 'service_id' => $locked->service_id],
                    ['status' => 'active']
                );
            } else {
                // Removed, as the services screen has always removed an
                // unticked service. Orders already placed carry their own
                // laundry and service, so they run out as they were; only new
                // orders stop coming.
                // One model at a time, so the activity log sees it go.
                LaundryService::withoutGlobalScopes()
                    ->where('laundry_id', $locked->laundry_id)
                    ->where('service_id', $locked->service_id)
                    ->get()
                    ->each->delete();
            }

            $locked->forceFill([
                'status' => LaundryServiceRequest::APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            return $locked;
        });

        $this->notifier->decided($request->load('service'));

        return $request;
    }

    /**
     * Refuse, with a note the laundry will read.
     */
    public function reject(LaundryServiceRequest $request, User $reviewer, string $note): LaundryServiceRequest
    {
        $this->mayReview();

        if (trim($note) === '') {
            throw new RuntimeException('note_required');
        }

        $request = DB::transaction(function () use ($request, $reviewer, $note) {
            $locked = LaundryServiceRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($request->id);

            if (! $locked->isPending()) {
                throw new RuntimeException('not_pending');
            }

            $locked->forceFill([
                'status' => LaundryServiceRequest::REJECTED,
                'note' => trim($note),
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            return $locked;
        });

        $this->notifier->decided($request->load('service'));

        return $request;
    }

    /**
     * An operator set these services directly: whatever the laundry had asked
     * about them is settled.
     */
    public function supersedeFor(int $laundryId): void
    {
        LaundryServiceRequest::withoutGlobalScopes()
            ->where('laundry_id', $laundryId)
            ->pending()
            ->get()
            ->each(fn (LaundryServiceRequest $request) => $request
                ->forceFill(['status' => LaundryServiceRequest::SUPERSEDED])
                ->save());
    }

    /**
     * What is waiting, and the last refusal, per service — for the laundry's
     * own screen.
     *
     * @return array{pending: array<int, string>, rejected: array<int, string>}
     */
    public function stateFor(int $laundryId): array
    {
        $requests = LaundryServiceRequest::withoutGlobalScopes()
            ->where('laundry_id', $laundryId)
            ->whereIn('status', [LaundryServiceRequest::PENDING, LaundryServiceRequest::REJECTED, LaundryServiceRequest::APPROVED])
            ->orderBy('id')
            ->get();

        $pending = [];
        $rejected = [];

        foreach ($requests as $request) {
            if ($request->isPending()) {
                $pending[$request->service_id] = $request->action;
                unset($rejected[$request->service_id]);
            } elseif ($request->status === LaundryServiceRequest::REJECTED) {
                // Only the latest word on a service counts: a refusal followed
                // by an approval is not a refusal any more.
                $rejected[$request->service_id] = (string) $request->note;
            } else {
                unset($rejected[$request->service_id]);
            }
        }

        return ['pending' => $pending, 'rejected' => $rejected];
    }

    /**
     * The services a laundry offers right now.
     *
     * @return array<int, int>
     */
    private function offered(int $laundryId): array
    {
        return LaundryService::withoutGlobalScopes()
            ->where('laundry_id', $laundryId)
            ->where('status', 'active')
            ->pluck('service_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Somebody inside a laundry never reviews — not their own request, not
     * another laundry's. The permission alone would allow it the day somebody
     * grants it to a laundry role by mistake; this is the line that stops that.
     */
    private function mayReview(): void
    {
        if (LaundryContext::currentId() !== null) {
            throw new RuntimeException('not_yours_to_review');
        }
    }
}
