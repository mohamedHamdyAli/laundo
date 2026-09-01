<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ledger, not a number.
 *
 * `wallets.balance` exists as a cached total, but **it is never the truth** —
 * every change is a `wallet_transactions` row, and the balance is the sum of
 * them. A wallet you can set directly is a wallet nobody can audit, and the first
 * time a customer says «فين فلوسي» there is no way to answer.
 *
 * `balance_after` on each transaction is the running total at that moment. It is
 * redundant by construction, and that is the point: it lets any single row be
 * checked against the sum of everything before it, so a corrupted ledger is
 * detectable rather than merely wrong.
 *
 * One wallet per user, whether customer or driver. A driver's earnings and a
 * customer's refund are the same mechanism pointed at different people.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // A cache of the ledger, reconcilable at any time.
            $table->decimal('balance', 12, 2)->default(0);
            $table->string('currency', 3)->default('EGP');

            // Money a driver has earned but cannot withdraw yet — «الرصيد المعلق».
            $table->decimal('pending_balance', 12, 2)->default(0);

            $table->boolean('is_frozen')->default(false);
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();

            // credit adds, debit removes. Stored as a sign-free amount plus a
            // direction rather than a signed number, so an accidental negative
            // credit is impossible to express.
            $table->string('direction');
            $table->decimal('amount', 12, 2);

            $table->string('reason');
            $table->decimal('balance_after', 12, 2);

            // What caused it — an order, a refund, a task. Polymorphic because a
            // ledger entry can be caused by anything and a column per cause would
            // grow forever.
            $table->nullableMorphs('source');

            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['wallet_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
