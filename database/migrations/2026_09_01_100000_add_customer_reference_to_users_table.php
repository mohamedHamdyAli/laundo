<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «مرجع العميل C-882» — the reference printed on the bag.
 *
 * The design's ticket card carries it beside the order number, and the two are
 * not interchangeable: the order number identifies one job, the reference
 * identifies the person, permanently. That is what lets a laundry match a bag
 * whose label is torn, and what lets a returned garment find its owner when
 * nobody remembers which order it came in with.
 *
 * Deliberately not the user's id. Drivers, staff and admins share this table, so
 * the id sequence has gaps a customer would have to be told about — "you are
 * customer 1,204" when the platform has 300 customers is a number that invites
 * exactly one question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable: only customers carry one. A driver has no bag.
            $table->string('customer_reference', 16)->nullable()->unique()->after('phone');
        });

        // Existing customers, oldest first, so the numbering matches the order
        // people actually signed up in rather than whatever the backfill saw.
        $roleId = DB::table('roles')->where('slug', Role::USER)->value('id');

        if ($roleId === null) {
            return;
        }

        $next = 1;

        DB::table('users')
            ->where('role_id', $roleId)
            ->orderBy('id')
            ->select('id')
            ->chunkById(500, function ($rows) use (&$next) {
                foreach ($rows as $row) {
                    DB::table('users')
                        ->where('id', $row->id)
                        ->update(['customer_reference' => 'C-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT)]);
                    $next++;
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['customer_reference']);
            $table->dropColumn('customer_reference');
        });
    }
};
