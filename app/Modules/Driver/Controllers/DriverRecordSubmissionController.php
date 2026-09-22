<?php

namespace App\Modules\Driver\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Driver\Models\DriverRecordSubmission;
use App\Modules\Driver\Services\DriverRecordReview;
use App\Services\ResponseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * «مستندات بانتظار المراجعة» — what drivers have sent about their own records.
 *
 * Its own screen rather than a tab on the driver, and that is the point of the
 * owner's decision: an approval queue you have to go looking for is an approval
 * queue nobody works. The sidebar badge counts what is pending, so the number is
 * in front of somebody before they open anything.
 *
 * Gated on `driver_record_submission.*`, not on `driver.update`. Checking a
 * licence photograph against the person who sent it is a different job from
 * keeping a driver's shift current, and the install should be able to hand it to
 * a different person.
 */
class DriverRecordSubmissionController extends Controller
{
    public function __construct(private readonly DriverRecordReview $review) {}

    public function index(Request $request)
    {
        $status = (string) $request->get('status', DriverRecordSubmission::PENDING);

        $view = view('admin.driver_record_submission.index', [
            'submissions' => $this->listing($status)->paginate(15),
            'status' => $status,
            'pendingCount' => DriverRecordSubmission::pending()->count(),
        ]);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if (! $request->ajax()) {
            return response()->json([], 400);
        }

        $term = (string) $request->get('query');

        $submissions = $this->listing((string) $request->get('status', DriverRecordSubmission::PENDING))
            ->when($term !== '', fn (Builder $q) => $q->search($term, [
                'driver.name', 'driver.phone', 'status', 'note',
            ]))
            ->paginate(15);

        return response()->json([
            'table' => view('admin.driver_record_submission.partials._driver_record_submission_table_body', [
                'submissions' => $submissions,
            ])->render(),
            'pagination' => $submissions->withQueryString()->links()->toHtml(),
        ]);
    }

    public function show($id)
    {
        ResponseService::noPermissionThenRedirect('driver_record_submission.view');

        $row = DriverRecordSubmission::with(['driver.profile', 'reviewer:id,name'])->findOrFail($id);

        return view('admin.driver_record_submission.show', [
            'row' => $row,
            // Worked out here, at review time, rather than stored when it was
            // sent: an operator may have corrected the same driver in between,
            // and approving a stale diff would silently undo them.
            'diff' => $row->diff(),
        ]);
    }

    public function approve(Request $request, $id)
    {
        ResponseService::noPermissionThenRedirect('driver_record_submission.update');

        $submission = DriverRecordSubmission::findOrFail($id);

        try {
            $this->review->approve($submission, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $this->translate($e));
        }

        return redirect()->route('admin.driver_record_submission.index')
            ->with('success', __('The details have been applied to the driver.'));
    }

    public function reject(Request $request, $id)
    {
        ResponseService::noPermissionThenRedirect('driver_record_submission.update');

        // Required, not optional. A driver told «rejected» and nothing else
        // sends the same photograph again, and it is refused for the same
        // unstated reason.
        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ], [
            'note.required' => __('Say why, so the driver knows what to send instead.'),
        ]);

        $submission = DriverRecordSubmission::findOrFail($id);

        try {
            $this->review->reject($submission, $request->user(), $data['note']);
        } catch (RuntimeException $e) {
            return back()->with('error', $this->translate($e));
        }

        return redirect()->route('admin.driver_record_submission.index')
            ->with('success', __('The driver has been told.'));
    }

    /**
     * @return Builder<DriverRecordSubmission>
     */
    private function listing(string $status): Builder
    {
        return DriverRecordSubmission::with(['driver:id,name,phone', 'reviewer:id,name'])
            ->when(
                in_array($status, [
                    DriverRecordSubmission::PENDING,
                    DriverRecordSubmission::APPROVED,
                    DriverRecordSubmission::REJECTED,
                ], true),
                fn (Builder $q) => $q->where('status', $status)
            )
            // Oldest waiting first: a driver who sent a licence three days ago
            // outranks one who sent theirs a minute ago, and «newest first»
            // buries exactly the wrong one.
            ->orderByRaw("case when status = '".DriverRecordSubmission::PENDING."' then 0 else 1 end")
            ->orderBy('created_at');
    }

    private function translate(RuntimeException $e): string
    {
        return match ($e->getMessage()) {
            'already_reviewed' => __('Somebody has already decided on this one.'),
            'driver_missing' => __('That driver no longer exists.'),
            default => __('We could not complete that.'),
        };
    }
}
