<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «تأكيد استلام من المغسلة لعدد الشماعات» — the client's sheet, item 11
 * (2026-11-08). The hangers a customer hands the driver are counted by the
 * driver and, now, confirmed by the laundry they reach; the customer's hanger
 * points are paid on the laundry's count, at its confirmation, not at the
 * driver's count as before.
 *
 * Legs that already reported hangers were paid at once under the old rule, so
 * they are stamped confirmed with their own count — otherwise every one of
 * them would appear in the laundry's queue as waiting, for points already paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_tasks', function (Blueprint $table) {
            $table->unsignedSmallInteger('hangers_confirmed')->nullable()->after('hangers_returned');
            $table->timestamp('hangers_confirmed_at')->nullable()->after('hangers_confirmed');
            $table->foreignId('hangers_confirmed_by')->nullable()->after('hangers_confirmed_at')
                ->constrained('users')->nullOnDelete();
        });

        DB::table('order_tasks')
            ->where('hangers_returned', '>', 0)
            ->whereNull('hangers_confirmed_at')
            ->update([
                'hangers_confirmed' => DB::raw('hangers_returned'),
                'hangers_confirmed_at' => DB::raw('COALESCE(completed_at, updated_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('order_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hangers_confirmed_by');
            $table->dropColumn(['hangers_confirmed', 'hangers_confirmed_at']);
        });
    }
};
