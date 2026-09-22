<?php

namespace App\Modules\Driver\Services;

use App\Modules\Driver\Models\Driver;
use App\Modules\Driver\Models\DriverRecordSubmission;
use App\Modules\Notification\Services\DriverRecordNotifier;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Everything a driver sends about their vehicle or their papers, and the person
 * who decides on it.
 *
 * The driver app edits three screens; writing them straight onto
 * `driver_profiles` would make the licence expiry whatever the driver last
 * typed, and a record nobody checks is not a record. So a submission is staged
 * and applies on approval.
 *
 * The dashboard is deliberately not routed through here. An operator editing a
 * driver **is** the approval, and sending them round their own queue would be
 * theatre with an extra click in it.
 */
class DriverRecordReview
{
    public function __construct(private readonly DriverRecordNotifier $notifier) {}

    /**
     * Take what the driver sent.
     *
     * **One pending row per driver.** A second submission supersedes the first
     * rather than queueing two versions of the same car: an operator working
     * through a backlog of one driver's own corrections is reading history, not
     * making decisions. The superseded row is marked rather than deleted, so
     * «what did he send last Tuesday» still has an answer.
     *
     * @param  array<string, mixed>  $payload  only the fields actually submitted
     */
    public function submit(Driver $driver, array $payload): DriverRecordSubmission
    {
        $payload = array_intersect_key($payload, DriverRecordSubmission::FIELDS);

        if ($payload === []) {
            throw new RuntimeException('nothing_submitted');
        }

        $submission = DB::transaction(function () use ($driver, $payload) {
            DriverRecordSubmission::where('driver_id', $driver->id)
                ->pending()
                ->update([
                    'status' => DriverRecordSubmission::REJECTED,
                    'note' => __('Superseded by a newer submission.'),
                    'reviewed_at' => now(),
                ]);

            return DriverRecordSubmission::create([
                'driver_id' => $driver->id,
                'payload' => $payload,
                'status' => DriverRecordSubmission::PENDING,
            ]);
        });

        // Outside the transaction on purpose, and swallowing its own failures:
        // a notification that fails must not roll back the submission it was
        // describing, or a driver is told their upload did not work when it did.
        $this->notifier->submitted($submission);

        return $submission;
    }

    /**
     * Apply it.
     *
     * Writes only the fields the driver actually sent — a submission from the
     * licence screen must not re-assert the vehicle at whatever it was when that
     * form loaded.
     */
    public function approve(DriverRecordSubmission $submission, User $reviewer): DriverRecordSubmission
    {
        if (! $submission->isPending()) {
            throw new RuntimeException('already_reviewed');
        }

        $superseded = [];

        $approved = DB::transaction(function () use ($submission, $reviewer, &$superseded) {
            $driver = $submission->driver;

            if (! $driver) {
                throw new RuntimeException('driver_missing');
            }

            $payload = array_intersect_key(
                $submission->payload,
                DriverRecordSubmission::FIELDS
            );

            // The photographs this approval is about to replace, read *before*
            // the write. Until this transaction commits, the old licence is
            // still the record — which is why the upload endpoint no longer
            // touches it and why the deletion belongs here rather than there.
            $profile = $driver->profile;

            foreach ($payload as $field => $path) {
                if (DriverRecordSubmission::FIELDS[$field] !== 'image') {
                    continue;
                }

                $existing = $profile?->{$field};

                if ($existing && $existing !== $path) {
                    $superseded[] = $existing;
                }
            }

            // updateOrCreate, not update: a driver whose profile row never
            // existed would otherwise be approved into nothing.
            $driver->profile()->updateOrCreate(['user_id' => $driver->id], $payload);

            $submission->update([
                'status' => DriverRecordSubmission::APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            return $submission->fresh();
        });

        // After the commit, never inside it: a rolled-back approval replaced
        // nothing, and a file deleted on the way to that rollback is gone all
        // the same. A delete that fails here orphans a file, which is the
        // cheaper of the two failures by a distance.
        foreach ($superseded as $path) {
            DeleteImage($path);
        }

        return $approved;
    }

    /**
     * Refuse it, and say why.
     *
     * The note is not optional in the form above this: a driver who is told
     * «rejected» and nothing else sends the same photograph again, and the
     * second one is refused for the same unstated reason.
     *
     * The uploaded files stay where they are. A refused photograph is evidence
     * of what was sent, and deleting it means the next conversation about it has
     * nothing to look at.
     */
    public function reject(DriverRecordSubmission $submission, User $reviewer, ?string $note = null): DriverRecordSubmission
    {
        if (! $submission->isPending()) {
            throw new RuntimeException('already_reviewed');
        }

        $rejected = DB::transaction(function () use ($submission, $reviewer, $note) {
            $submission->update([
                'status' => DriverRecordSubmission::REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'note' => $note,
            ]);

            return $submission->fresh();
        });

        // Read once and handed on, rather than re-read for the notifier and
        // again for the caller: two instances of the same row can disagree, and
        // the one the driver is told about should be the one returned.
        $this->notifier->decided($rejected);

        return $rejected;
    }
}
