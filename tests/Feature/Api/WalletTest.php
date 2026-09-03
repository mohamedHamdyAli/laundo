<?php

namespace Tests\Feature\Api;

use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The wallet ledger.
 *
 * The claim worth protecting above all others: **the balance is the sum of the
 * transactions.** A wallet that cannot be reconciled against its own history is a
 * wallet nobody can defend when a customer asks «فين فلوسي».
 */
class WalletTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->customer = $this->customer();
        $this->wallets = app(WalletService::class);
    }

    #[Test]
    public function a_wallet_is_created_on_first_use_and_starts_empty(): void
    {
        $this->assertSame(0, Wallet::count());

        $wallet = $this->wallets->forUser($this->customer);

        $this->assertSame('0.00', $wallet->balance);
        $this->assertSame('0.00', $wallet->pending_balance);

        // Asking twice does not make two.
        $this->wallets->forUser($this->customer);
        $this->assertSame(1, Wallet::count());
    }

    #[Test]
    public function every_change_writes_a_transaction_and_the_balance_agrees(): void
    {
        $this->wallets->credit($this->customer, 100, TransactionReason::TopUp);
        $this->wallets->credit($this->customer, 50, TransactionReason::Refund);
        $this->wallets->debit($this->customer, 30, TransactionReason::OrderPayment);

        $wallet = $this->wallets->forUser($this->customer)->fresh();

        $this->assertSame('120.00', $wallet->balance);
        $this->assertSame(3, WalletTransaction::count());

        // The whole point: the cache and the ledger tell the same story.
        $this->assertTrue($wallet->isReconciled());
        $this->assertSame(120.0, $wallet->ledgerBalance());
    }

    #[Test]
    public function each_transaction_records_the_running_balance(): void
    {
        $this->wallets->credit($this->customer, 100, TransactionReason::TopUp);
        $this->wallets->debit($this->customer, 40, TransactionReason::OrderPayment);
        $this->wallets->credit($this->customer, 10, TransactionReason::Refund);

        $running = WalletTransaction::orderBy('id')->pluck('balance_after')->all();

        // Redundant by construction, and that is the point: any single row can be
        // checked against everything before it.
        $this->assertSame(['100.00', '60.00', '70.00'], $running);
    }

    #[Test]
    public function a_debit_beyond_the_balance_is_refused(): void
    {
        $this->wallets->credit($this->customer, 50, TransactionReason::TopUp);

        try {
            $this->wallets->debit($this->customer, 51, TransactionReason::OrderPayment);
            $this->fail('An overdraft was allowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('insufficient_balance', $e->getMessage());
        }

        // And nothing was written — a refused debit must leave no trace but the
        // balance it did not change.
        $this->assertSame('50.00', $this->wallets->forUser($this->customer)->fresh()->balance);
        $this->assertSame(1, WalletTransaction::count());
    }

    #[Test]
    public function a_zero_or_negative_amount_is_refused(): void
    {
        foreach ([0, -10] as $amount) {
            try {
                $this->wallets->credit($this->customer, $amount, TransactionReason::TopUp);
                $this->fail("An amount of {$amount} was accepted.");
            } catch (RuntimeException $e) {
                // A credit of zero explains nothing; a negative one is a debit
                // wearing the wrong label.
                $this->assertSame('amount_must_be_positive', $e->getMessage());
            }
        }

        $this->assertSame(0, WalletTransaction::count());
    }

    #[Test]
    public function a_frozen_wallet_accepts_nothing(): void
    {
        $wallet = $this->wallets->forUser($this->customer);
        $wallet->update(['balance' => 100, 'is_frozen' => true]);

        foreach (['credit', 'debit'] as $method) {
            try {
                $this->wallets->{$method}($this->customer, 10, TransactionReason::Adjustment);
                $this->fail("A frozen wallet accepted a {$method}.");
            } catch (RuntimeException $e) {
                $this->assertSame('wallet_frozen', $e->getMessage());
            }
        }
    }

    #[Test]
    public function pending_money_is_not_spendable_until_released(): void
    {
        $this->wallets->addPending($this->customer, 75);

        $wallet = $this->wallets->forUser($this->customer)->fresh();

        // Earned, but not in the ledger and not in the balance.
        $this->assertSame('75.00', $wallet->pending_balance);
        $this->assertSame('0.00', $wallet->balance);
        $this->assertSame(0, WalletTransaction::count());
        $this->assertTrue($wallet->isReconciled());

        $this->wallets->release($this->customer, 75, TransactionReason::Earning);

        $wallet->refresh();
        $this->assertSame('0.00', $wallet->pending_balance);
        $this->assertSame('75.00', $wallet->balance);
        $this->assertSame(1, WalletTransaction::count());
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function releasing_more_than_is_pending_does_not_go_negative(): void
    {
        $this->wallets->addPending($this->customer, 20);
        $this->wallets->release($this->customer, 50, TransactionReason::Earning);

        // A negative pending balance would be a bug, and letting it through would
        // make it permanent.
        $this->assertSame('0.00', $this->wallets->forUser($this->customer)->fresh()->pending_balance);
    }

    // ------------------------------------------------------------------ the API

    #[Test]
    public function the_wallet_endpoint_shows_both_balances(): void
    {
        $this->wallets->credit($this->customer, 250, TransactionReason::TopUp);
        $this->wallets->addPending($this->customer, 30);

        Sanctum::actingAs($this->customer);

        $this->getJson('/api/v1/wallet', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.balance', 250)
            ->assertJsonPath('data.pending_balance', 30)
            ->assertJsonPath('data.is_frozen', false);
    }

    #[Test]
    public function the_history_shows_signed_amounts_and_filters_by_group(): void
    {
        $this->wallets->credit($this->customer, 100, TransactionReason::TopUp);
        $this->wallets->debit($this->customer, 40, TransactionReason::OrderPayment);
        $this->wallets->credit($this->customer, 25, TransactionReason::Refund);

        Sanctum::actingAs($this->customer);

        $all = $this->getJson('/api/v1/wallet/transactions', $this->apiHeaders());
        $all->assertOk();
        $this->assertCount(3, $all->json('data'));

        // «+100 ج.م» / «-150 ج.م» — signed for display, unsigned in store.
        // Cast before comparing: PHP encodes a whole float as `100`, so the
        // decoded value is an int. Ordinary JSON behaviour, not a bug.
        $amounts = collect($all->json('data'))->map(fn ($row) => (float) $row['amount'])->all();
        $this->assertContains(100.0, $amounts);
        $this->assertContains(-40.0, $amounts);

        // الإضافات / المدفوعات / الاستردادات
        $this->assertCount(1, $this->getJson('/api/v1/wallet/transactions?group=additions', $this->apiHeaders())->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/wallet/transactions?group=payments', $this->apiHeaders())->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/wallet/transactions?group=refunds', $this->apiHeaders())->json('data'));
    }

    #[Test]
    public function a_top_up_does_not_credit_anything(): void
    {
        Sanctum::actingAs($this->customer);

        // Crediting here would let anybody with a token print money. Money has to
        // arrive through a provider first.
        $this->postJson('/api/v1/wallet/top-up',
            ['amount' => 500, 'method' => 'card'], $this->apiHeaders())
            ->assertStatus(400);

        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame('0.00', $this->wallets->forUser($this->customer)->fresh()->balance);
    }

    #[Test]
    public function a_withdrawal_debits_and_a_too_large_one_is_refused(): void
    {
        $this->wallets->credit($this->customer, 200, TransactionReason::TopUp);

        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/wallet/withdraw', ['amount' => 500], $this->apiHeaders())
            ->assertStatus(400);

        $this->postJson('/api/v1/wallet/withdraw', ['amount' => 120], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.balance', 80);

        $wallet = $this->wallets->forUser($this->customer)->fresh();
        $this->assertSame('80.00', $wallet->balance);
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function a_customer_only_ever_sees_their_own_wallet(): void
    {
        $this->wallets->credit($this->customer, 300, TransactionReason::TopUp);

        $stranger = $this->customer('+201088776655');
        Sanctum::actingAs($stranger);

        // Reached through its owner and never by key, so there is no id to guess.
        $this->getJson('/api/v1/wallet', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.balance', 0);

        $this->getJson('/api/v1/wallet/transactions', $this->apiHeaders())
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_wallet_requires_a_token(): void
    {
        $this->getJson('/api/v1/wallet', $this->apiHeaders())->assertUnauthorized();
        $this->postJson('/api/v1/wallet/withdraw', ['amount' => 10], $this->apiHeaders())->assertUnauthorized();
    }
}
