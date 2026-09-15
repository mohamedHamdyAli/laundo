<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A third way a question can stop being a question.
 *
 * `answer` was `confirmed` | `declined` — the two things a customer can say.
 * But a schedule that is cancelled while one of its cycles is still open leaves
 * a prompt nobody will ever answer, and the app kept asking «محتاج تغسل
 * النهاردة؟» for a schedule the customer had already deleted.
 *
 * Closing those as `declined` was the alternative and it was rejected: the
 * customer did not decline that cycle, they cancelled the whole schedule, and
 * collapsing the two loses the only record of which happened. The same reason
 * `returned` is not `cancelled` on an order.
 *
 * MySQL only, deliberately. The test suite runs on SQLite, where an enum is a
 * varchar and every value already fits — so this would pass in tests and the
 * write would fail in the app. That is the trap this guard exists for, not a
 * shortcut around it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE `recurrence_prompts` MODIFY COLUMN `answer` ENUM('confirmed','declined','cancelled') NULL"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Rows carrying the value being removed would be silently truncated to
        // ''. Reopen them instead: a cancelled schedule's prompt is filtered out
        // by its schedule's status anyway, so a null answer is the honest
        // pre-migration state rather than a fabricated decline.
        if (Schema::hasTable('recurrence_prompts')) {
            DB::table('recurrence_prompts')
                ->where('answer', 'cancelled')
                ->update(['answer' => null, 'answered_at' => null]);
        }

        DB::statement(
            "ALTER TABLE `recurrence_prompts` MODIFY COLUMN `answer` ENUM('confirmed','declined') NULL"
        );
    }
};
