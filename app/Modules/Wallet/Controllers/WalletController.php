<?php

namespace App\Modules\Wallet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Wallets, for operations.
 *
 * The one thing this screen must do that the API does not: **prove the ledger.**
 * A cached balance that has drifted from the sum of its transactions is the sort
 * of fault that is invisible until somebody disputes a figure, so it is surfaced
 * on the list rather than left to be discovered.
 *
 * An adjustment writes a transaction like everything else — there is no way to
 * set a balance here, because there is no way to set one anywhere.
 */
class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallets) {}

    public function index(Request $request)
    {
        $wallets = Wallet::with('owner:id,name,phone,email')
            ->where(fn ($q) => $q->where('balance', '>', 0)->orWhere('pending_balance', '>', 0))
            ->orderByDesc('balance')
            ->paginate(15);

        $totals = [
            'balance' => (float) Wallet::sum('balance'),
            'pending' => (float) Wallet::sum('pending_balance'),
            // Counted rather than assumed. A drifted wallet is invisible until
            // somebody disputes a figure.
            'unreconciled' => Wallet::all()->filter(fn (Wallet $w) => ! $w->isReconciled())->count(),
        ];

        $view = view('admin.wallet.index', compact('wallets', 'totals'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $term = $request->get('query');

            $wallets = Wallet::with('owner:id,name,phone,email')
                ->when($term, fn ($q) => $q->whereHas('owner', fn ($o) => $o->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")))
                ->orderByDesc('balance')
                ->paginate(15);

            return response()->json([
                'table' => view('admin.wallet.partials._wallet_table_body', compact('wallets'))->render(),
                // toHtml(), not a string cast: links() returns an Htmlable, and
                // casting one is not something PHP is willing to do.
                'pagination' => $wallets->withQueryString()->links()->toHtml(),
            ]);
        }
    }

    public function show($id)
    {
        $row = Wallet::with('owner:id,name,phone,email')->findOrFail($id);

        $transactions = WalletTransaction::where('wallet_id', $row->id)
            ->with('author:id,name')
            ->latest('id')
            ->paginate(25);

        $reconciliation = $this->wallets->reconcile($row);

        return view('admin.wallet.show', compact('row', 'transactions', 'reconciliation'));
    }

    /**
     * A manual correction — «تسوية».
     */
    public function adjust(Request $request, $id)
    {
        $request->validate([
            'direction' => ['required', Rule::in([WalletTransaction::CREDIT, WalletTransaction::DEBIT])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            // Required, not optional: an adjustment nobody explained is one
            // nobody can defend later.
            'note' => ['required', 'string', 'max:1000'],
        ]);

        $wallet = Wallet::findOrFail($id);
        $owner = User::find($wallet->user_id);

        if (! $owner) {
            return back()->with('error', __('This wallet has no owner.'));
        }

        $method = $request->get('direction') === WalletTransaction::CREDIT ? 'credit' : 'debit';

        try {
            $this->wallets->{$method}(
                $owner,
                (float) $request->get('amount'),
                TransactionReason::Adjustment,
                null,
                $request->get('note'),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->with('error', match ($e->getMessage()) {
                'insufficient_balance' => __('The balance is not enough for that adjustment.'),
                'wallet_frozen' => __('This wallet is on hold.'),
                default => __('Could not adjust this wallet.'),
            });
        }

        return back()->with('success', __('Adjustment recorded.'));
    }

    /**
     * Put a wallet on hold, or take it off.
     */
    public function toggleFreeze($id)
    {
        $wallet = Wallet::findOrFail($id);
        $wallet->update(['is_frozen' => ! $wallet->is_frozen]);

        return back()->with('success', $wallet->is_frozen
            ? __('Wallet placed on hold.')
            : __('Wallet released.'));
    }
}
