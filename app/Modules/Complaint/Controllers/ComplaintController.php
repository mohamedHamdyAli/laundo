<?php

namespace App\Modules\Complaint\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Complaint\Enums\ComplaintCategory;
use App\Modules\Complaint\Enums\ComplaintStatus;
use App\Modules\Complaint\Models\Complaint;
use App\Modules\Complaint\Services\ComplaintService;
use App\Modules\Order\Models\OrderRating;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The complaint queue.
 *
 * Two things worth knowing before changing this.
 *
 * First, it is **not** tenant-scoped and must not be. `Complaint` carries a
 * `laundry_id` so operations can ask which laundry generates the most complaints,
 * but the owner decided complaints are the platform's to handle. The protection is
 * `complaint.*` being granted to the super admin only — hand it to a laundry role
 * and every customer's complaint opens to them.
 *
 * Second, a rating of two stars or fewer **with a comment** appears in the same
 * queue. The rating screen's own placeholder is «اكتب ملاحظاتك أو شكواك», so those
 * are complaints that happened to arrive through a different form. Showing them
 * anywhere else would mean operations working two lists and believing each was
 * the whole picture.
 */
class ComplaintController extends Controller
{
    public function __construct(private readonly ComplaintService $complaints) {}

    public function index(Request $request)
    {
        $status = (string) $request->get('status', 'open');

        $view = view('admin.complaint.index', [
            'complaints' => $this->listing($status)->paginate(15),
            'status' => $status,
            'counts' => $this->counts(),
            'byCategory' => $this->byCategory(),
            // The other half of the queue. Not merged into the paginator: they are
            // a different table with different actions, and pretending otherwise
            // would mean a "resolve" button that has nothing to resolve.
            'ratingComplaints' => $this->ratingComplaints(),
            'statuses' => ComplaintStatus::cases(),
        ]);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if (! $request->ajax()) {
            return response()->json([], 400);
        }

        $term = (string) $request->get('query');

        $complaints = $this->listing((string) $request->get('status', 'open'))
            ->when($term !== '', function (Builder $q) use ($term) {
                $q->where(function (Builder $inner) use ($term) {
                    $inner->where('reference', 'like', "%{$term}%")
                        ->orWhere('body', 'like', "%{$term}%")
                        ->orWhereHas('complainant', fn ($c) => $c->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%"))
                        ->orWhereHas('order', fn ($o) => $o->where('code', 'like', "%{$term}%"));
                });
            })
            ->paginate(15);

        return response()->json([
            'table' => view('admin.complaint.partials._complaint_table_body', [
                'complaints' => $complaints,
                'statuses' => ComplaintStatus::cases(),
            ])->render(),
            'pagination' => $complaints->withQueryString()->links()->toHtml(),
        ]);
    }

    public function show($id)
    {
        $complaint = Complaint::with([
            'complainant:id,name,phone,email',
            'order:id,code,status,laundry_id',
            'laundry:id,name',
            'handler:id,name',
            'attachments',
        ])->findOrFail($id);

        return view('admin.complaint.show', [
            'row' => $complaint,
            // Only the moves this status allows, so the screen cannot offer a
            // transition the service will refuse.
            'nextStatuses' => $complaint->statusCase()->allowedNext(),
        ]);
    }

    public function transition(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', ComplaintStatus::values())],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $complaint = Complaint::findOrFail($id);

        try {
            $this->complaints->transition(
                $complaint,
                ComplaintStatus::from($validated['status']),
                $request->user(),
                $validated['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', match ($e->getMessage()) {
                'already_in_that_state' => __('This complaint is already in that state.'),
                'transition_not_allowed' => __('A complaint cannot move straight to that state.'),
                default => __('Could not update this complaint.'),
            });
        }

        return back()->with('success', __('Complaint updated.'));
    }

    public function note(Request $request, $id)
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $this->complaints->note(Complaint::findOrFail($id), $request->user(), $validated['note']);

        return back()->with('success', __('Note added.'));
    }

    /**
     * @return Builder<Complaint>
     */
    private function listing(string $status): Builder
    {
        return Complaint::with(['complainant:id,name,phone', 'order:id,code', 'laundry:id,name', 'handler:id,name'])
            ->when($status === 'open', fn (Builder $q) => $q->open())
            ->when(
                in_array($status, ComplaintStatus::values(), true),
                fn (Builder $q) => $q->where('status', $status)
            )
            // Oldest open first: a complaint waiting three days outranks one that
            // arrived a minute ago, and "newest first" buries exactly the wrong one.
            ->orderByRaw('case when status in (?, ?) then 0 else 1 end', [
                ComplaintStatus::New->value,
                ComplaintStatus::InProgress->value,
            ])
            ->orderBy('created_at');
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'new' => Complaint::where('status', ComplaintStatus::New->value)->count(),
            'in_progress' => Complaint::where('status', ComplaintStatus::InProgress->value)->count(),
            'resolved' => Complaint::where('status', ComplaintStatus::Resolved->value)->count(),
            // Open for more than a day. The figure that says the queue is not
            // being worked, which a total never says.
            'stale' => Complaint::open()->where('created_at', '<', now()->subDay())->count(),
            'from_ratings' => $this->ratingComplaintsQuery()->count(),
        ];
    }

    /**
     * «أكتر سبب شكوى إيه» — the only reason the category is a closed set.
     *
     * @return array<int, array{category: ComplaintCategory, count: int}>
     */
    private function byCategory(): array
    {
        $counts = Complaint::selectRaw('category, count(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        $out = [];

        foreach (ComplaintCategory::cases() as $case) {
            $out[] = ['category' => $case, 'count' => (int) ($counts[$case->value] ?? 0)];
        }

        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /**
     * Low ratings carrying a comment — complaints that arrived through the rating
     * form instead.
     *
     * @return Collection<int, OrderRating>
     */
    private function ratingComplaints()
    {
        return $this->ratingComplaintsQuery()
            ->with(['customer:id,name,phone', 'order:id,code', 'laundry:id,name'])
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /**
     * @return Builder<OrderRating>
     */
    private function ratingComplaintsQuery(): Builder
    {
        // withoutGlobalScopes: this screen is the platform's view, and the tenant
        // scope on OrderRating would empty it for nobody's benefit.
        return OrderRating::withoutGlobalScopes()
            ->poor()
            ->whereNotNull('comment');
    }
}
