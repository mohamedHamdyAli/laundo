<?php

namespace App\Modules\Notification\Services;

use App\Jobs\SendManualNotification;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Notification\Data\NotificationMessage;
use App\Modules\Notification\Enums\NotificationAudience;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Something a person wanted to say, with no event behind it.
 *
 * Every other notifier here is named for a moment — an order placed, a driver
 * assigned, an application received. This one exists because the moments are not
 * enough: «اتأخرنا عليك» and «الخدمة موقوفة النهارده» have no trigger and never
 * will, and before this the only way to say either was to telephone.
 *
 * It deliberately adds no delivery machinery of its own. It resolves *who* and
 * hands the message to `NotificationDispatcher`, so a hand-written message obeys
 * the same mute, the same log and the same failure handling as every automatic
 * one. A second send path would be a second set of rules to keep in step.
 */
class ManualNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * The rows the second select offers, as `id => name`.
     *
     * Inactive accounts are left out rather than shown and refused: a name in a
     * recipient list is a promise that choosing it does something.
     *
     * @return array<int, string>
     */
    public function targets(NotificationAudience $audience): array
    {
        if ($audience === NotificationAudience::Laundry) {
            return $this->laundries()
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn (Laundry $laundry) => [
                    $laundry->id => getLocalizedValueDashboard($laundry, 'name'),
                ])
                ->all();
        }

        return $this->recipientsQuery($audience, null)
            ->get()
            ->mapWithKeys(fn (User $user) => [
                $user->id => trim($user->name.' · '.$user->phone),
            ])
            ->all();
    }

    /**
     * Everybody this message is about to reach, as a query.
     *
     * A query rather than a collection because «every customer» has no ceiling
     * any more — the cap came off when the worker went in — and materialising an
     * unbounded audience to count it would put the whole table in memory for a
     * number.
     *
     * A null `$targetId` is «everyone in this audience», and it is only ever null
     * because the operator chose that option, never because a field was left
     * blank. See `ManualNotificationRequest`.
     *
     * @return Builder<User>
     */
    public function recipientsQuery(NotificationAudience $audience, ?int $targetId): Builder
    {
        if ($audience !== NotificationAudience::Laundry) {
            return User::query()
                ->whereHas('role', fn ($query) => $query->where('slug', $audience->roleSlug()))
                ->where('status', 'active')
                ->when($targetId !== null, fn ($query) => $query->where('id', $targetId))
                ->orderBy('name');
        }

        /*
         * A laundry is not a user. One laundry resolves to its own active people;
         * «every laundry» resolves to the people of every approved, active one —
         * not to every account that merely carries a `laundry_id`, which would
         * include the staff of a shop that was rejected last week.
         */
        return User::query()
            ->whereIn('laundry_id', $this->laundries()
                ->when($targetId !== null, fn ($query) => $query->where('id', $targetId))
                ->select('id'))
            ->where('status', 'active')
            ->orderBy('id');
    }

    /**
     * @return Collection<int, User>
     */
    public function recipients(NotificationAudience $audience, ?int $targetId): Collection
    {
        return $this->recipientsQuery($audience, $targetId)->get();
    }

    /**
     * Why this cannot be sent, or null when it can.
     *
     * Shaped like `OrderDeletionGuard::blocker()` on purpose: a refusal that
     * carries its own sentence is a refusal an operator can act on, and the
     * alternative — a boolean plus a generic message at the call site — is how
     * two screens end up disagreeing about why the same thing was refused.
     *
     * There is no size limit here any more. There was, while every message was an
     * FCM round-trip inside the request that started it; a worker processes the
     * broadcast now, so the only thing that can stop a send is having nobody to
     * send it to.
     */
    public function blocker(NotificationAudience $audience, ?int $targetId): ?string
    {
        if ($this->recipientsQuery($audience, $targetId)->exists()) {
            return null;
        }

        return $targetId === null
            ? __('There is nobody in this audience yet.')
            : __('That account is not active, so nothing would reach it.');
    }

    /**
     * Say it.
     *
     * Returns how many people it is going to, and whether they are being written
     * to now or by the worker. That is not the same as how many handsets lit up,
     * deliberately: a customer with no registered device still gets the in-app
     * record, and reporting «0 delivered» for that would send somebody chasing a
     * fault that is not there.
     *
     * **A handful goes inline; a crowd goes to the queue.** One person is a
     * single round-trip and the operator should see it land, rather than be told
     * their message is «on its way» and have to go and check. Beyond a few there
     * is nothing to gain from making them wait, and past a few hundred the
     * request could not survive it.
     *
     * **No transaction.** The dispatcher makes a network call per handset, and a
     * transaction held open across them would keep a write lock for as long as
     * Firebase takes to answer. Each message is independent — one failure must
     * not undo the ones that arrived.
     *
     * @return array{count: int, queued: bool}
     */
    public function send(
        NotificationAudience $audience,
        ?int $targetId,
        string $title,
        string $body,
        User $sender,
    ): array {
        $query = $this->recipientsQuery($audience, $targetId);
        $count = (clone $query)->count();

        if ($count === 0) {
            return ['count' => 0, 'queued' => false];
        }

        if ($count <= $this->inlineLimit()) {
            $this->dispatcher->sendMany($query->get(), new NotificationMessage(
                event: NotificationEvent::ManualMessage,
                title: $title,
                body: $body,
                // No url. The apps navigate on `data`, and a free-text link on a
                // screen that writes to customers is an open redirect with a
                // friendly form in front of it.
                data: ['audience' => $audience->value],
                sentBy: $sender,
            ));

            return ['count' => $count, 'queued' => false];
        }

        /*
         * `chunkById`, not `get()`. «Every customer» has no ceiling, and the
         * point of moving this off the request was not to move an unbounded
         * `SELECT *` along with it.
         *
         * `reorder()` first, and it is load-bearing. `chunkById` pages on the id
         * and strips only the *existing order on that column* — a leftover
         * `orderBy('name')` would still sort first, the id cursor would stop
         * matching the row order, and the chunking would skip people silently.
         * Nobody notices a broadcast that missed a third of its audience.
         */
        $query->reorder()->select('id')->chunkById(500, function (Collection $people) use ($title, $body, $sender, $audience): void {
            foreach ($people as $person) {
                SendManualNotification::dispatch(
                    $person->id,
                    $title,
                    $body,
                    $sender->id,
                    $audience->value,
                );
            }
        });

        return ['count' => $count, 'queued' => true];
    }

    /**
     * How many people may be written to inside the request.
     *
     * Small on purpose. It is not a performance budget — it is the line between
     * «done» and «being done», and the operator is told which of the two happened.
     */
    public function inlineLimit(): int
    {
        return (int) config('push.manual_inline_limit', 5);
    }

    /**
     * Shops that can actually be written to.
     *
     * An applicant is not yet a laundry: announcing anything to somebody who was
     * rejected last week is a message that cannot be taken back.
     *
     * @return Builder<Laundry>
     */
    private function laundries(): Builder
    {
        return Laundry::query()
            ->where('status', 'active')
            ->whereNotNull('approved_at');
    }
}
