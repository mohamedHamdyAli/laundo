<?php

namespace App\Modules\Wallet\Services;

use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only thing that may change a balance.
 *
 * Two rules, and everything else is detail:
 *
 *  1. **Every change is a transaction row.** `wallets.balance` is written here and
 *     nowhere else, always alongside the row that explains it. Nothing in the
 *     application updates a balance directly — a wallet you can set is a wallet
 *     nobody can audit.
 *
 *  2. **The row is written under a lock.** Two debits racing would each read the
 *     same balance, and both would pass a sufficiency check that only one should.
 *     `lockForUpdate` inside a transaction is what makes concurrent spending
 *     safe, and it is the reason this class exists rather than a trait.
 */
class WalletService
{
    /**
     * Find or create the user's wallet.
     *
     * Created on demand rather than with the account: most users never have a
     * balance, and a row per user that is always zero is a row that only makes
     * the ledger harder to read.
     */
    public function forUser(User $user): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0, 'pending_balance' => 0]
        );
    }

    /**
     * Add money.
     */
    public function credit(
        User $user,
        float $amount,
        TransactionReason $reason,
        ?Model $source = null,
        ?string $note = null,
        ?User $actor = null,
    ): WalletTransaction {
        return $this->move($user, $amount, WalletTransaction::CREDIT, $reason, $source, $note, $actor);
    }

    /**
     * Take money, if there is enough.
     *
     * @throws RuntimeException
     */
    public function debit(
        User $user,
        float $amount,
        TransactionReason $reason,
        ?Model $source = null,
        ?string $note = null,
        ?User $actor = null,
    ): WalletTransaction {
        return $this->move($user, $amount, WalletTransaction::DEBIT, $reason, $source, $note, $actor);
    }

    /**
     * Money earned but not yet available — «الرصيد المعلق».
     *
     * Deliberately NOT a transaction: nothing has moved yet, and writing a ledger
     * row for money the driver cannot touch would make the ledger disagree with
     * the balance it is supposed to explain. It becomes a real credit in
     * `release()`.
     */
    public function addPending(User $user, float $amount): Wallet
    {
        $wallet = $this->forUser($user);

        return DB::transaction(function () use ($wallet, $amount) {
            $locked = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            $locked->update(['pending_balance' => round((float) $locked->pending_balance + $amount, 2)]);

            return $locked->refresh();
        });
    }

    /**
     * Move pending money into the spendable balance.
     */
    public function release(
        User $user,
        float $amount,
        TransactionReason $reason,
        ?Model $source = null,
        ?string $note = null,
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $reason, $source, $note) {
            $wallet = $this->forUser($user);
            $locked = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            // Floored at zero: a pending balance that went negative would be a
            // bug, and propagating it into the ledger would make it permanent.
            $locked->update([
                'pending_balance' => round(max((float) $locked->pending_balance - $amount, 0), 2),
            ]);

            return $this->write($locked, $amount, WalletTransaction::CREDIT, $reason, $source, $note, null);
        });
    }

    /**
     * Prove the cache still agrees with the ledger.
     *
     * @return array{wallet_id: int, cached: float, ledger: float, reconciled: bool}
     */
    public function reconcile(Wallet $wallet): array
    {
        $ledger = $wallet->ledgerBalance();

        return [
            'wallet_id' => $wallet->id,
            'cached' => (float) $wallet->balance,
            'ledger' => $ledger,
            'reconciled' => $wallet->isReconciled(),
        ];
    }

    private function move(
        User $user,
        float $amount,
        string $direction,
        TransactionReason $reason,
        ?Model $source,
        ?string $note,
        ?User $actor,
    ): WalletTransaction {
        if ($amount <= 0) {
            // A credit of zero explains nothing and a negative one is a debit
            // wearing the wrong label.
            throw new RuntimeException('amount_must_be_positive');
        }

        $wallet = $this->forUser($user);

        return DB::transaction(function () use ($wallet, $amount, $direction, $reason, $source, $note, $actor) {
            // The lock is the whole point: two debits racing would each read the
            // same balance and both pass a check only one should.
            $locked = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_frozen) {
                throw new RuntimeException('wallet_frozen');
            }

            if ($direction === WalletTransaction::DEBIT && ! $locked->hasAtLeast($amount)) {
                throw new RuntimeException('insufficient_balance');
            }

            return $this->write($locked, $amount, $direction, $reason, $source, $note, $actor);
        });
    }

    /**
     * Write the row and the balance together. Never one without the other.
     */
    private function write(
        Wallet $wallet,
        float $amount,
        string $direction,
        TransactionReason $reason,
        ?Model $source,
        ?string $note,
        ?User $actor,
    ): WalletTransaction {
        $delta = $direction === WalletTransaction::CREDIT ? $amount : -$amount;
        $after = round((float) $wallet->balance + $delta, 2);

        $wallet->update(['balance' => $after]);

        return WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'direction' => $direction,
            'amount' => round($amount, 2),
            'reason' => $reason,
            'balance_after' => $after,
            'source_type' => $source ? $source::class : null,
            'source_id' => $source?->getKey(),
            'note' => $note,
            'created_by' => $actor?->id,
        ]);
    }
}
