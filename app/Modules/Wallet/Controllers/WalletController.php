<?php

namespace App\Modules\Wallet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Enums\WalletOwnerType;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Database\Eloquent\Builder;
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
    /**
     * The filter value that means «every wallet, no rule at all».
     *
     * A third state, because the first two were sharing one. An empty `type`
     * meant both «no group chosen» *and* «only the funded ones», and the option
     * driving it was labelled «All wallets» — which is the one thing it was not.
     * The label was reported as a bug, and rightly: the note under it said the
     * truth and the label won, because nobody reads a caption to find out what a
     * dropdown they have already read does.
     *
     * The default is unchanged and still hides empty wallets, because that is
     * what makes the screen answer «where is the money». It just no longer claims
     * to be showing everything.
     */
    public const EVERY = 'all';

    public function __construct(private readonly WalletService $wallets) {}

    public function index(Request $request)
    {
        $type = WalletOwnerType::tryFrom((string) $request->get('type'));
        $every = $this->wantsEvery($request);

        $wallets = $this->query($type, $every)->paginate(15);

        $view = view('admin.wallet.index', [
            'wallets' => $wallets,
            'totals' => $this->totals($type, $every),
            'type' => $type,
            'every' => $every,
            'types' => WalletOwnerType::cases(),
        ]);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $term = $request->get('query');
            $type = WalletOwnerType::tryFrom((string) $request->get('type'));

            $wallets = $this->query($type, $this->wantsEvery($request))
                ->when($term, fn ($q) => $q->search($term, [
                    'owner.name', 'owner.phone', 'owner.email',
                ]))
                ->paginate(15);

            return response()->json([
                'table' => view('admin.wallet.partials._wallet_table_body', compact('wallets'))->render(),
                // toHtml(), not a string cast: links() returns an Htmlable, and
                // casting one is not something PHP is willing to do.
                'pagination' => $wallets->withQueryString()->links()->toHtml(),
            ]);
        }
    }

    /**
     * Did the operator ask for every wallet, empty ones included?
     *
     * Read off the same `type` parameter the groups use, so the screen keeps one
     * dropdown and one query-string key. `WalletOwnerType::tryFrom('all')` is
     * null, so the sentinel cannot collide with a group.
     */
    private function wantsEvery(Request $request): bool
    {
        return (string) $request->get('type') === self::EVERY;
    }

    /**
     * The list: one audience, everything, or the funded rows only.
     *
     * Three states, and the middle one is the reason this has a flag rather than
     * just a nullable type:
     *
     *  - **A group** — every wallet in it, empty ones included. The operator
     *    asked «show me the laundries», and answering with «the laundries that
     *    happen to hold a balance today» is how somebody concludes a laundry has
     *    no wallet at all.
     *  - **Every wallet** — no rule whatsoever. What «All» has to mean when it
     *    says «All».
     *  - **The default** — funded rows only. This screen opens on «where is the
     *    money», and a page of empty customer wallets (one is created the first
     *    time anybody opens the wallet screen in the app) buries the rows holding
     *    any. Kept as the default for exactly that reason; it is the *label* that
     *    was wrong, not the rule.
     *
     * Shared with search() so a term and a filter cannot disagree — the pair
     * drifting apart is what made searching surface rows the plain list hides.
     *
     * @return Builder<Wallet>
     */
    private function query(?WalletOwnerType $type, bool $every = false): Builder
    {
        return Wallet::with('owner:id,name,phone,email,role_id', 'owner.role:id,slug,name')
            ->when($type === null && ! $every, fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner->where('balance', '>', 0)->orWhere('pending_balance', '>', 0)
            ))
            ->when($type !== null, fn (Builder $q) => $q->whereHas(
                'owner.role',
                fn (Builder $role) => $role->whereIn('slug', $type->roleSlugs())
            ))
            ->orderByDesc('balance');
    }

    /**
     * The three figures the screen opens with, over the same set the list shows.
     *
     * They follow the filter deliberately: platform-wide totals sitting above a
     * list of eight drivers is a number nobody can reconcile against what they
     * are looking at, and «Total held» that never changes when you filter reads
     * as a broken filter rather than as a different scope.
     *
     * @return array<string, float|int>
     */
    private function totals(?WalletOwnerType $type, bool $every = false): array
    {
        $scope = fn () => $this->query($type, $every);

        return [
            'balance' => (float) $scope()->sum('balance'),
            'pending' => (float) $scope()->sum('pending_balance'),
            // Counted rather than assumed. A drifted wallet is invisible until
            // somebody disputes a figure. Loaded rather than summed in SQL
            // because the ledger comparison lives in PHP — the table is small
            // and an operator opening this screen wants the truth, not a guess.
            'unreconciled' => $scope()->get()->filter(fn (Wallet $w) => ! $w->isReconciled())->count(),
        ];
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
     * «محفظتي» — the signed-in user's own wallet and its transactions.
     *
     * Its own action with **no permission on the route**, and that is the point
     * rather than an oversight. `admin.wallet.*` is gated on `wallet.view`, which
     * a laundry owner does not have and must not be given: the list is not
     * tenant-scoped, so granting it would show every laundry what every other
     * laundry and every driver holds. Reading your own balance is not the same
     * capability as reading everybody's, so it gets its own door — the same
     * reasoning that gave laundries a second sign-in page rather than widening
     * the first.
     *
     * There is nothing to authorise beyond being signed in: the wallet is
     * resolved from `$request->user()` and never from a route parameter, so
     * there is no id to tamper with.
     */
    public function mine(Request $request)
    {
        $user = $request->user();

        $wallet = $this->wallets->forUser($user);

        $transactions = WalletTransaction::where('wallet_id', $wallet->id)
            ->with('author:id,name')
            ->latest('id')
            ->paginate(25);

        return view('admin.wallet.mine', [
            'row' => $wallet,
            'transactions' => $transactions,
            'reconciliation' => $this->wallets->reconcile($wallet),
        ]);
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
