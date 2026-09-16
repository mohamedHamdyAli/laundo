<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money the platform takes back off a laundry, and the reason it gave.
 *
 * **Why a ledger rather than a column on `laundries`.** A single
 * `deduction_total` would answer «how much» and nothing else — not who decided
 * it, not when, not what for, and not what it looked like before somebody
 * changed it. Every other money record in this project stores its components
 * (`order_settlements` keeps basis, rate and both shares; `driver_earnings`
 * keeps the rate), for the same reason: the question a laundry actually asks is
 * «why that number», and a total cannot answer it.
 *
 * **It does not move a wallet balance, and that is deliberate.** `WalletService`
 * refuses a debit the balance cannot cover — a laundry whose share has not
 * settled yet has nothing to take — so realising a deduction as a wallet
 * transaction would fail exactly when it is most needed, and would have to
 * invent a negative balance the ledger has no way to express. A deduction is
 * therefore a *claim*: recorded here, subtracted from what the revenue screen
 * reports as payable, and carried until it is reversed.
 *
 * **Reversed, never deleted.** Same rule the rest of the money code follows — a
 * settled settlement is frozen, a refund is rejected rather than removed. A
 * deduction that vanishes takes its reason with it, and «why was I charged 200
 * in March» stops being answerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_deductions', function (Blueprint $table) {
            $table->id();

            // Not nullable, unlike `order_settlements.laundry_id`: a settlement
            // can precede the assignment of a laundry, but a deduction is taken
            // off somebody by definition.
            $table->foreignId('laundry_id')->constrained('laundries')->cascadeOnDelete();

            $table->decimal('amount', 10, 2);

            // Required at the database as well as in the request. A deduction
            // with no reason is the one thing this table exists to prevent.
            $table->text('reason');

            // applied  — standing against the laundry
            // reversed — withdrawn, kept for the record
            $table->string('status')->default('applied')->index();

            // Who took it, and who gave it back. Nullable only because a
            // deleted operator must not take the record with them.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();

            $table->timestamps();

            // The screen's one query: this laundry's standing deductions.
            $table->index(['laundry_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_deductions');
    }
};
