<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Models\Refund;
use App\Modules\Payment\Services\RefundService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The refund queue — «قيد المراجعة» made into a screen somebody works through.
 *
 * Until now the services existed and nothing could reach them: operations had no
 * way to approve a refund except through code. This is that gap closed.
 *
 * Not tenant-scoped. A refund is money the business pays back, not a laundry's
 * work, and the permission is what gates it.
 */
class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    public function index(Request $request)
    {
        $status = $request->get('status', Refund::PENDING);

        $query = Refund::with(['customer:id,name,phone', 'order:id,code,payment_method', 'reviewer:id,name']);

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $refunds = $query->latest('id')->paginate(15);

        $counts = [
            'pending' => Refund::pending()->count(),
            // Approved but never paid out — the case somebody has to chase, and
            // the reason approving and settling are separate columns.
            'unsettled' => Refund::where('status', Refund::APPROVED)->whereNull('settled_at')->count(),
        ];

        $view = view('admin.refund.index', compact('refunds', 'counts', 'status'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $term = $request->get('query');

            $refunds = Refund::with(['customer:id,name,phone', 'order:id,code', 'reviewer:id,name'])
                ->when($term, function ($q) use ($term) {
                    $q->whereHas('order', fn ($o) => $o->where('code', 'like', "%{$term}%"))
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%"));
                })
                ->when($request->get('status') && $request->get('status') !== 'all',
                    fn ($q) => $q->where('status', $request->get('status')))
                ->latest('id')
                ->paginate(15);

            return response()->json([
                'table' => view('admin.refund.partials._refund_table_body', compact('refunds'))->render(),
                // toHtml(), not a string cast: links() returns an Htmlable, and
                // casting one is not something PHP is willing to do.
                'pagination' => $refunds->withQueryString()->links()->toHtml(),
            ]);
        }
    }

    public function approve(Request $request, $id)
    {
        $request->validate([
            'destination' => ['required', Rule::in([Refund::TO_WALLET, Refund::TO_SOURCE])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $refund = Refund::findOrFail($id);

        try {
            $result = $this->refunds->approve(
                $refund,
                $request->user(),
                $request->get('destination'),
                $request->get('note'),
            );
        } catch (RuntimeException $e) {
            return back()->with('error', match ($e->getMessage()) {
                'refund_not_pending' => __('This request has already been decided.'),
                'no_payment_to_refund_against' => __('There is no card payment to refund. Send it to the wallet instead.'),
                default => __('Could not approve this refund.'),
            });
        }

        // Approved but not settled is a real outcome, not a failure — a gateway
        // refund is in flight, and saying it is done would be a lie.
        $message = $result->status === Refund::SETTLED
            ? __('Refund approved and paid.')
            : __('Refund approved. The payout is still in progress.');

        return back()->with('success', $message);
    }

    public function reject(Request $request, $id)
    {
        $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->refunds->reject(Refund::findOrFail($id), $request->user(), $request->get('note'));
        } catch (RuntimeException) {
            return back()->with('error', __('This request has already been decided.'));
        }

        return back()->with('success', __('Refund request rejected.'));
    }

    /**
     * Retry a payout that was approved but never left.
     */
    public function settle($id)
    {
        $refund = Refund::findOrFail($id);

        try {
            $result = $this->refunds->settle($refund);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage() === 'refund_not_approved'
                ? __('Only an approved refund can be paid out.')
                : __('Could not pay out this refund.'));
        }

        $settled = $result->status === Refund::SETTLED;

        $message = $settled
            ? __('Refund paid.')
            : __('The payout did not go through. Try again or refund to the wallet.');

        return back()->with($settled ? 'success' : 'error', $message);
    }
}
