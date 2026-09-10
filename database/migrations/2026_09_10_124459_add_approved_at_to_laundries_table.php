<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A laundry that applied and is waiting to be let in.
 *
 * Deliberately a timestamp and not a third value on `status`. `status` is the
 * binary the list screen's toggle button drives and that a dozen queries filter
 * on; a `pending` case would have to be taught to every one of them, and the
 * toggle — which only knows active and inactive — would silently make a pending
 * laundry live. A pending row is simply `inactive`, which every one of those
 * queries already excludes, plus a null here to say *why* it is inactive.
 *
 * `rejected_at` is its own column rather than a status, for the same reason and
 * one more: a rejected application that is later accepted has to keep the fact
 * that it was once turned down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundries', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('status');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->text('rejection_reason')->nullable()->after('rejected_at');

            // The list screen's pending filter is `whereNull('approved_at')`
            // ordered by when the application arrived.
            $table->index(['approved_at', 'created_at']);
        });

        // Every laundry that existed before applications did was put there by an
        // operator, which is approval. Leaving them null would file the whole
        // estate as pending and lock out every owner the moment sign-in started
        // checking status.
        DB::table('laundries')->whereNull('approved_at')->update([
            'approved_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('laundries', function (Blueprint $table) {
            $table->dropIndex(['approved_at', 'created_at']);
            $table->dropColumn(['approved_at', 'rejected_at', 'rejection_reason']);
        });
    }
};
