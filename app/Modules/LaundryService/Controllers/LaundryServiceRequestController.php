<?php

namespace App\Modules\LaundryService\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LaundryService\Models\LaundryServiceRequest;
use App\Modules\LaundryService\Services\LaundryServiceRequestReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * «طلبات خدمات المغاسل» — laundries asking to open or close a service.
 *
 * HTTP only; the rules are `LaundryServiceRequestReview`'s. Its own screen, with
 * a sidebar badge counting what waits, because an approval queue somebody has to
 * go looking for is a queue nobody works — the same reasoning as the driver
 * document reviews. Gated on `laundry_service_request.*`, so the job can be
 * handed to somebody short of super admin.
 */
class LaundryServiceRequestController extends Controller
{
    public function __construct(private readonly LaundryServiceRequestReview $review) {}

    public function index(Request $request)
    {
        $status = (string) $request->get('status', LaundryServiceRequest::PENDING);

        $view = view('admin.laundry_service_request.index', [
            'requests' => $this->listing($status)->paginate(20),
            'status' => $status,
            'pendingCount' => LaundryServiceRequest::pending()->count(),
        ]);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if (! $request->ajax()) {
            return response()->json([], 400);
        }

        $term = (string) $request->get('query');

        $requests = $this->listing((string) $request->get('status', LaundryServiceRequest::PENDING))
            ->when($term !== '', fn (Builder $q) => $q->search($term, ['laundry.name', 'service.name', 'action', 'status', 'note']))
            ->paginate(20);

        return response()->json([
            'table' => view('admin.laundry_service_request.partials._laundry_service_request_table_body', [
                'requests' => $requests,
            ])->render(),
            'pagination' => $requests->withQueryString()->links()->toHtml(),
        ]);
    }

    public function approve(Request $request, $id)
    {
        try {
            $this->review->approve(LaundryServiceRequest::findOrFail($id), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $this->translate($e));
        }

        return back()->with('success', __('Approved. The laundry\'s services have been updated.'));
    }

    public function reject(Request $request, $id)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        try {
            $this->review->reject(LaundryServiceRequest::findOrFail($id), $request->user(), $data['note']);
        } catch (RuntimeException $e) {
            return back()->with('error', $this->translate($e));
        }

        return back()->with('success', __('Rejected. The laundry will see your note.'));
    }

    /**
     * @return Builder<LaundryServiceRequest>
     */
    private function listing(string $status): Builder
    {
        return LaundryServiceRequest::with(['laundry:id,name', 'service:id,name', 'requester:id,name', 'reviewer:id,name'])
            ->when(
                in_array($status, [LaundryServiceRequest::PENDING, LaundryServiceRequest::APPROVED, LaundryServiceRequest::REJECTED], true),
                fn (Builder $q) => $q->where('status', $status),
                // «All» leaves out the superseded: they are history of a
                // question that was asked again, not decisions anybody made.
                fn (Builder $q) => $q->where('status', '<>', LaundryServiceRequest::SUPERSEDED)
            )
            // Oldest first while waiting — the laundry that asked first has
            // waited longest — and newest first once decided.
            ->when(
                $status === LaundryServiceRequest::PENDING,
                fn (Builder $q) => $q->oldest('id'),
                fn (Builder $q) => $q->latest('id')
            );
    }

    private function translate(RuntimeException $e): string
    {
        return match ($e->getMessage()) {
            'not_pending' => __('This request has already been decided.'),
            'not_yours_to_review' => __('Requests are reviewed by the platform, not by a laundry.'),
            'note_required' => __('Write the reason, so the laundry knows what to change.'),
            default => __('Could not complete that.'),
        };
    }
}
