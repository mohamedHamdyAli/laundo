<?php

namespace App\Modules\Complaint\Services;

use App\Modules\Complaint\Enums\ComplaintCategory;
use App\Modules\Complaint\Enums\ComplaintStatus;
use App\Modules\Complaint\Models\Complaint;
use App\Modules\Complaint\Models\ComplaintAttachment;
use App\Modules\Notification\Data\NotificationMessage;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Services\NotificationDispatcher;
use App\Modules\Order\Models\Order;
use App\Modules\User\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Taking and working a complaint.
 *
 * Operations answers by phone, so nothing here writes a reply. What it does write
 * is a reference the complainant can quote, a status they can see, and an internal
 * note they cannot — and it records who handled it, because "somebody dealt with
 * it" is not an answer when the customer calls back.
 */
class ComplaintService
{
    /**
     * What the design's attacher allows. Capped in the service as well as in the
     * request, because the request is not the only caller — operations can file a
     * complaint on somebody's behalf.
     */
    public const MAX_ATTACHMENTS = 5;

    /**
     * @param  array{category: string, body: string, order_id?: int|null, photos?: array<int, UploadedFile>}  $data
     */
    public function submit(User $complainant, array $data): Complaint
    {
        $order = null;

        if (filled($data['order_id'] ?? null)) {
            // Through the user's own orders, so naming somebody else's order id is
            // a refusal rather than a complaint filed against a stranger's laundry.
            $order = $complainant->orders()->find($data['order_id']);

            if ($order === null) {
                throw new RuntimeException('order_not_found');
            }
        }

        $complaint = DB::transaction(function () use ($complainant, $order, $data) {
            $complaint = Complaint::create([
                'reference' => $this->reference(),
                'user_id' => $complainant->id,
                'order_id' => $order?->id,
                // Copied from the order, never taken from the payload. It decides
                // which laundry a complaint is counted against.
                'laundry_id' => $order?->laundry_id,
                'category' => $data['category'],
                'body' => trim($data['body']),
                'status' => ComplaintStatus::New->value,
            ]);

            // «المرفقات» — a stain that did not come out is mostly a photograph.
            // Uploaded inside the transaction so a complaint never lands holding
            // half its evidence; the files themselves are written first and left
            // behind if the insert rolls back, which is the cheaper of the two
            // failures.
            foreach (array_slice($data['photos'] ?? [], 0, self::MAX_ATTACHMENTS) as $photo) {
                ComplaintAttachment::create([
                    'complaint_id' => $complaint->id,
                    'path' => uploadOrUpdateImage($photo, 'images/complaints'),
                    'uploaded_by' => $complainant->id,
                ]);
            }

            return $complaint;
        });

        // Outside the transaction and swallowed. A queue that only works when
        // somebody remembers to open it is not a queue — but a Firebase outage
        // must not lose a complaint that has already been accepted.
        $this->alertOperations($complaint, $complainant);

        return $complaint;
    }

    /**
     * Tell operations a complaint arrived.
     *
     * The «waiting over a day» counter measures complaints nobody looked at.
     * Measuring that is not the same as preventing it, and P11's notification
     * machinery was already here and unused by this feature.
     */
    private function alertOperations(Complaint $complaint, User $complainant): void
    {
        try {
            $operators = User::whereHas('role', fn ($q) => $q->where('slug', 'super_admin'))->get();

            if ($operators->isEmpty()) {
                return;
            }

            app(NotificationDispatcher::class)->sendMany($operators, new NotificationMessage(
                event: NotificationEvent::ComplaintReceived,
                title: __('A new complaint'),
                // The reference and the category, because that is what makes the
                // alert actionable without opening anything.
                body: __(':reference — :category, from :name', [
                    'reference' => $complaint->reference,
                    'category' => __($complaint->categoryCase()->label()),
                    'name' => $complainant->name ?? '—',
                ]),
                url: '/admin/complaint/show/'.$complaint->id,
                data: ['complaint_id' => (string) $complaint->id],
                subject: $complaint,
            ));
        } catch (Throwable) {
            // Deliberately silent. The complaint is saved; the alert is a courtesy.
        }
    }

    /**
     * Move a complaint along, optionally leaving a note.
     *
     * The transition is checked rather than assumed: a resolved complaint being
     * marked resolved again would overwrite `handled_at` and lose how long it
     * actually took, which is the one figure this screen exists to produce.
     */
    public function transition(Complaint $complaint, ComplaintStatus $to, User $actor, ?string $note = null): Complaint
    {
        $from = $complaint->statusCase();

        if ($from === $to) {
            throw new RuntimeException('already_in_that_state');
        }

        if (! in_array($to, $from->allowedNext(), true)) {
            throw new RuntimeException('transition_not_allowed');
        }

        $complaint = DB::transaction(function () use ($complaint, $to, $actor, $note) {
            $complaint->status = $to->value;
            $complaint->handled_by = $actor->id;

            // Stamped only when the complaint actually finishes. Setting it on
            // every touch would make "how long did this take" meaningless.
            $complaint->handled_at = $to->isOpen() ? null : now();

            if (filled($note)) {
                $complaint->internal_note = $this->appendNote($complaint->internal_note, $note, $actor);
            }

            $complaint->save();

            return $complaint->fresh();
        });

        // Outside the transaction and swallowed, like every other announce here.
        if (! $to->isOpen()) {
            $this->tellComplainantItIsClosed($complaint, $to);
        }

        return $complaint;
    }

    /**
     * Tell the complainant their case is finished.
     *
     * Not the answer — operations gives that by phone, which was the decision.
     * This is the acknowledgement, and it is the difference between "handled" and
     * "handled, and they know". Without it the only way to find out is to open the
     * app and check a status, which nobody does.
     *
     * Resolved and closed are worded differently on purpose: "we sorted it out"
     * and "we looked and decided not to act" are not the same message, and sending
     * the first for the second is how a customer complains a second time.
     */
    private function tellComplainantItIsClosed(Complaint $complaint, ComplaintStatus $to): void
    {
        try {
            $complainant = $complaint->complainant;

            if ($complainant === null) {
                return;
            }

            $resolved = $to === ComplaintStatus::Resolved;

            app(NotificationDispatcher::class)->send($complainant, new NotificationMessage(
                event: NotificationEvent::ComplaintClosed,
                title: $resolved
                    ? __('Your complaint has been resolved')
                    : __('Your complaint has been closed'),
                body: $resolved
                    ? __('Complaint :reference is resolved. Call us if anything is still wrong.', [
                        'reference' => $complaint->reference,
                    ])
                    : __('Complaint :reference has been closed. Call us if you disagree.', [
                        'reference' => $complaint->reference,
                    ]),
                data: ['complaint_id' => (string) $complaint->id, 'reference' => $complaint->reference],
                subject: $complaint,
            ));
        } catch (Throwable) {
            // The complaint is decided and recorded. The message is a courtesy and
            // a Firebase outage must not undo the decision.
        }
    }

    /**
     * Add a note without a status change.
     */
    public function note(Complaint $complaint, User $actor, string $note): Complaint
    {
        return DB::transaction(function () use ($complaint, $actor, $note) {
            $complaint->internal_note = $this->appendNote($complaint->internal_note, $note, $actor);
            $complaint->save();

            return $complaint->fresh();
        });
    }

    /**
     * A reference somebody can read down a phone line.
     *
     * `CMP-` plus a short random tail rather than the id: the id tells a caller how
     * many complaints exist, and a sequential reference is trivially guessable by
     * anyone wanting to quote somebody else's.
     */
    private function reference(): string
    {
        do {
            $reference = 'CMP-'.strtoupper(Str::random(8));
        } while (Complaint::where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * Notes append rather than replace.
     *
     * Two people work the same complaint over a week; the second overwriting the
     * first is how "we already told them that" gets lost.
     */
    private function appendNote(?string $existing, string $addition, User $actor): string
    {
        $entry = sprintf(
            '[%s · %s] %s',
            now()->format('Y-m-d H:i'),
            $actor->name ?? '—',
            trim($addition)
        );

        return blank($existing) ? $entry : $existing."\n\n".$entry;
    }

    /**
     * Categories the app should offer, with translated labels.
     *
     * Served rather than hardcoded in two apps, for the same reason the rating
     * chips are: two clients maintaining the same list is two lists.
     *
     * @return array<int, array{value: string, label: string, needs_order: bool}>
     */
    public function categories(): array
    {
        return array_map(fn (ComplaintCategory $c) => [
            'value' => $c->value,
            'label' => __($c->label()),
            // A hint for the client, not a rule: a complaint that names no order
            // still has to be accepted, or it is lost entirely.
            'needs_order' => $c->usuallyAboutAnOrder(),
        ], ComplaintCategory::cases());
    }
}
