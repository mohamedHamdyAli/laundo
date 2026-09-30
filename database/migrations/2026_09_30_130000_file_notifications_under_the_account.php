<?php

use App\Modules\Driver\Models\Driver;
use App\Modules\LaundryStaff\Models\LaundryStaff;
use App\Modules\Moderator\Models\Moderator;
use App\Modules\User\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One list per account (the owner, 2026-09-30: «مفيش اشعارات بتوصل للدرايفر»).
 *
 * A notification was filed under the class it was sent through, so a driver's
 * rows were split: every leg assigned through a `Driver`, a closed complaint
 * or a broadcast through `User` — and the driver app reads one of the two.
 * `User::notifications()` files and reads them under the account from now on;
 * this moves the rows written before, so nothing a driver was told goes missing.
 *
 * No `down()`: which class wrote a row carried no meaning worth restoring.
 */
return new class extends Migration
{
    public function up(): void
    {
        $moved = DB::table('notifications')
            ->whereIn('notifiable_type', [Driver::class, Moderator::class, LaundryStaff::class])
            ->update(['notifiable_type' => User::class]);

        Log::info("[notifications] {$moved} rows filed under the account.");
    }

    public function down(): void
    {
        //
    }
};
