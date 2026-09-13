<?php

use App\Modules\Notification\Services\NotificationDispatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove preferences for channels nobody may silence.
 *
 * `database` was offered as a mutable channel and should never have been: the
 * in-app list is the record of what a person was told, not a way of telling
 * them. One account had muted it, so every notification since arrived by push
 * and was written down nowhere — the customer tapped the push, opened the list
 * it pointed at, and found it empty.
 *
 * The dispatcher now ignores these rows, so this is tidying rather than the fix
 * itself. It still has to happen: left in place they are a stored answer to a
 * question the account screen no longer asks, and the next person to read the
 * table would reasonably believe they still mean something.
 *
 * Deliberately not reversible. `down()` would have to invent which users had
 * muted what, and restoring a preference that silences the record is restoring
 * the bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_preferences')
            ->whereNotIn('channel', NotificationDispatcher::MUTABLE_CHANNELS)
            ->delete();
    }

    public function down(): void
    {
        // Nothing to put back: see above.
    }
};
