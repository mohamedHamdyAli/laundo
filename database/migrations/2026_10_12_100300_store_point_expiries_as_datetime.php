<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lot's expiry as DATETIME, not TIMESTAMP.
 *
 * MySQL's TIMESTAMP ends on 19 January 2038, and `Points_Expiry_Days` accepts
 * up to 3,650 days: from January 2028 on, a ten-year expiry would not fit, the
 * insert would fail, and the order whose completion was paying the points
 * could not complete. Every value is written and compared as UTC by the
 * application, so nothing reads differently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_transactions', function (Blueprint $table) {
            $table->dateTime('expires_at')->nullable()->change();
            $table->dateTime('expiry_warned_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('point_transactions', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->change();
            $table->timestamp('expiry_warned_at')->nullable()->change();
        });
    }
};
