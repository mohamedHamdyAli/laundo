<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 * **Both drivers, not MySQL alone.** The first draft of this guarded on
 * `mysql` and returned early otherwise, on the usual assumption that SQLite
 * treats an enum as a free varchar. It does not: Laravel's SQLite grammar
 * writes the value list out as a CHECK constraint, and the new write failed the
 * constraint in the test suite. Worth keeping written down — the assumption is
 * right often enough to be dangerous.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->allow(['confirmed', 'declined', 'cancelled']);
    }

    public function down(): void
    {
        // Rows carrying the value being removed would be silently truncated to
        // '' by MySQL and would fail the rebuilt CHECK on SQLite. Reopen them
        // instead: a cancelled schedule's prompts are filtered out by their
        // schedule's status anyway, so a null answer is the honest
        // pre-migration state rather than a fabricated decline.
        if (Schema::hasTable('recurrence_prompts')) {
            DB::table('recurrence_prompts')
                ->where('answer', 'cancelled')
                ->update(['answer' => null, 'answered_at' => null]);
        }

        $this->allow(['confirmed', 'declined']);
    }

    /**
     * @param  array<int, string>  $values
     */
    private function allow(array $values): void
    {
        if (DB::getDriverName() === 'mysql') {
            $list = implode(',', array_map(fn (string $v) => "'".$v."'", $values));

            DB::statement("ALTER TABLE `recurrence_prompts` MODIFY COLUMN `answer` ENUM({$list}) NULL");

            return;
        }

        // Everything else — SQLite in the suite — rebuilds the table around the
        // new constraint.
        Schema::table('recurrence_prompts', function (Blueprint $table) use ($values) {
            $table->enum('answer', $values)->nullable()->change();
        });
    }
};
