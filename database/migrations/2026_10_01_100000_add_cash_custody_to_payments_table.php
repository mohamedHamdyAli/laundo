<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash in the driver's pocket (the owner, 2026-10-01).
 *
 * Cash collected at the door was a flag on the order and nothing else, so the
 * payments screen never saw it and nobody could say how much each driver was
 * holding. A cash payment now names the driver who took it and the leg it was
 * taken on, and stays «with the driver» until somebody at the office records
 * receiving it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('collected_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            // One cash payment per delivery leg: a retried «تأكيد» cannot take
            // the same money twice.
            $table->foreignId('order_task_id')->nullable()->unique()->after('collected_by')
                ->constrained('order_tasks')->nullOnDelete();
            $table->timestamp('handed_over_at')->nullable()->after('captured_at');
            $table->foreignId('received_by')->nullable()->after('handed_over_at')->constrained('users')->nullOnDelete();

            $table->index(['collected_by', 'handed_over_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // The foreign key first: InnoDB keeps `collected_by`'s key on the
            // composite index, and dropping that index first fails (1553).
            $table->dropForeign(['collected_by']);
            $table->dropIndex(['collected_by', 'handed_over_at']);
            $table->dropColumn('collected_by');
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn('handed_over_at');
            $table->dropConstrainedForeignId('order_task_id');
        });
    }
};
