<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «النقاط» — a customer's points, as a ledger.
 *
 * The owner's terms (2026-10-12): every order earns points, every hanger handed
 * back to a driver earns points, a point is worth a set amount off a later
 * order, and points expire. Every number in that sentence is a setting.
 *
 * The wallet's two rules hold here too: `point_accounts.balance` is a cache of
 * the ledger, and nothing changes it except `PointsService`, always beside the
 * row that explains it.
 *
 * What the wallet does not have is **expiry**, and that is why a credit row is
 * also a *lot*: it carries the day it expires (stamped when it is earned, never
 * re-read — changing the setting does not move points already earned) and how
 * many of its points are still unspent. A spend eats the lots that expire first,
 * and `point_lot_spends` records which, so points returned by a cancelled order
 * go back with the expiry they came from.
 *
 * Whole points only. A point is a unit, not money: what it is worth is
 * `Point_Value`, and the discount it bought is stamped on the order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // A cache of the ledger, reconcilable at any time.
            $table->unsignedInteger('balance')->default(0);

            $table->timestamps();
        });

        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // credit adds, debit removes — a sign-free amount plus a direction,
            // as on the wallet, so a negative credit cannot be written.
            $table->string('direction', 10);
            $table->unsignedInteger('points');
            $table->string('reason', 40);
            $table->unsignedInteger('balance_after');

            // A credit is a lot: what is left of it, and when it lapses. Null
            // on a debit. `expires_at` null on a credit is «never».
            $table->unsignedInteger('remaining')->nullable();
            $table->timestamp('expires_at')->nullable();
            // The «your points are about to expire» message, once per lot.
            $table->timestamp('expiry_warned_at')->nullable();

            $table->nullableMorphs('source');

            // On a return: the spend it gives back. Bounds what can come back
            // to what went out, and lets a second return (the order cancelled
            // after a review already handed some back) carry on where the
            // first stopped.
            $table->foreignId('reverses_id')->nullable()->constrained('point_transactions')->nullOnDelete();

            // What makes an automatic credit happen once: «the points for order
            // 12», «the hangers on leg 40». Null on everything a person does,
            // and on a return, which is bounded by arithmetic under the lock
            // instead. A plain unique column rather than a partial index, which
            // SQLite and MariaDB do not spell alike.
            $table->string('dedupe_key', 80)->nullable()->unique();

            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'direction', 'expires_at']);
            $table->index(['direction', 'expires_at']);
        });

        Schema::create('point_lot_spends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debit_id')->constrained('point_transactions')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('point_transactions')->cascadeOnDelete();
            $table->unsignedInteger('points');
            $table->timestamps();

            $table->index('lot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_lot_spends');
        Schema::dropIfExists('point_transactions');
        Schema::dropIfExists('point_accounts');
    }
};
