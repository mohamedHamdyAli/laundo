<?php

namespace App\Modules\Order\Services;

use App\Modules\Notification\Services\OrderNotifier;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRecurrence;
use App\Modules\Order\Models\RecurrencePrompt;
use App\Modules\User\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The repeat schedule — «كل أسبوع» / «كل أسبوعين» / «كل شهر».
 *
 * The behaviour the business asked for, stated plainly: **a schedule never places
 * an order.** On its due day it asks the customer «محتاج تغسل النهاردة؟» and waits.
 * Saying yes opens the ordinary wizard on a pre-filled basket — the pieces are
 * reviewed, a window is picked and a payment method chosen — and the order that
 * comes out of it is what closes the question. Declining, or not answering at
 * all, skips that cycle and leaves the schedule alive for the next one.
 *
 * That is why `recurrence_prompts` exists. Without a row per cycle the scheduler
 * could not tell "not asked yet" from "asked and ignored", and would either
 * pester the customer on every run or skip them forever. The table's unique
 * (recurrence_id, prompted_for) makes the run idempotent however many times the
 * command fires — a re-run on the same day is a no-op, not a second question.
 */
class RecurrenceService
{
    public function __construct(private readonly OrderService $orders) {}

    /**
     * Start a schedule.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $customer, array $data): OrderRecurrence
    {
        return DB::transaction(function () use ($customer, $data) {
            $frequency = $data['frequency'];
            $start = isset($data['starts_on'])
                ? Carbon::parse($data['starts_on'])
                : $this->nextOccurrence($frequency, $data['day_of_week'] ?? null);

            return OrderRecurrence::create([
                'user_id' => $customer->id,
                'service_id' => $data['service_id'],
                'pickup_address_id' => $data['pickup_address_id'],
                'time_slot_id' => $data['time_slot_id'] ?? null,
                'frequency' => $frequency,
                'day_of_week' => $data['day_of_week'] ?? $start->dayOfWeekIso,
                'items' => $data['items'],
                'next_prompt_on' => $start->toDateString(),
                'status' => 'active',
            ]);
        });
    }

    /**
     * Ask every schedule that is due today, once.
     *
     * Returns the prompts it opened, and — since P11 — actually asks: a prompt
     * nobody delivered was a question nobody heard, which made the whole feature
     * a row in a table.
     *
     * @return array<int, RecurrencePrompt>
     */
    public function promptDue(?Carbon $on = null): array
    {
        $on = $on ?? now();
        $opened = [];

        OrderRecurrence::due($on)->with('customer')->chunkById(100, function ($due) use (&$opened) {
            foreach ($due as $recurrence) {
                // Anchored on the cycle's own date, not on today: a scheduler
                // running late must still record Monday's prompt as Monday's.
                $prompt = $this->openPrompt($recurrence, Carbon::parse($recurrence->next_prompt_on));

                if ($prompt) {
                    $opened[] = $prompt;
                    $this->ask($prompt);
                }
            }
        });

        return $opened;
    }

    /**
     * Deliver the question.
     *
     * Separate from opening the prompt so a delivery failure cannot lose the
     * record that the customer was due to be asked — the prompt row is what makes
     * the next run idempotent.
     */
    private function ask(RecurrencePrompt $prompt): void
    {
        try {
            app(OrderNotifier::class)->recurrencePrompt($prompt);
        } catch (\Throwable $e) {
            Log::warning('[notifications] recurrence prompt', [
                'prompt' => $prompt->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Open one cycle's question.
     *
     * The insert is guarded by the unique index rather than a preceding SELECT:
     * two schedulers overlapping would both pass the check and both insert. A
     * caught constraint violation is the only honest way to say "already asked".
     */
    public function openPrompt(OrderRecurrence $recurrence, Carbon $for): ?RecurrencePrompt
    {
        $existing = RecurrencePrompt::where('recurrence_id', $recurrence->id)
            ->whereDate('prompted_for', $for->toDateString())
            ->first();

        if ($existing) {
            // Already asked for this cycle. Move the schedule on so the next run
            // looks at the next cycle instead of this one forever.
            $this->advance($recurrence, $for);

            return null;
        }

        return DB::transaction(function () use ($recurrence, $for) {
            $prompt = RecurrencePrompt::create([
                'recurrence_id' => $recurrence->id,
                'prompted_for' => $for->toDateString(),
                'prompted_at' => now(),
            ]);

            $this->advance($recurrence, $for);

            return $prompt;
        });
    }

    /**
     * «أيوه» — hand the app the basket to open the wizard with.
     *
     * Deliberately not an order. The customer is being asked whether to wash
     * today, not asked to buy a basket agreed to months ago at prices nobody has
     * shown them since — the same reason `reorder` returns intent rather than
     * placing anything. From here the app runs the ordinary wizard: review the
     * pieces, pick a window, choose how to pay.
     *
     * The prompt stays open. It closes when an order actually exists, so a
     * customer who abandons the wizard is still asked.
     *
     * @return array<string, mixed>
     */
    public function basketFor(RecurrencePrompt $prompt): array
    {
        $recurrence = $prompt->recurrence;

        return [
            'prompt_id' => $prompt->id,
            'recurrence_id' => $recurrence?->id,
            // The cycle this question is about, which is the date the wizard
            // should open on — not today, if the scheduler ran late.
            'for_date' => $prompt->prompted_for->toDateString(),
            'service_id' => $recurrence?->service_id,
            'pickup_address_id' => $recurrence?->pickup_address_id,
            // The schedule holds one address; the wizard can still be sent
            // elsewhere, but this is what it opens with.
            'delivery_address_id' => $recurrence?->pickup_address_id,
            'time_slot_id' => $recurrence?->time_slot_id,
            'items' => array_map(
                fn (array $line) => ['item_id' => (int) $line['item_id'], 'qty' => (int) $line['qty']],
                $recurrence->items ?? []
            ),
        ];
    }

    /**
     * The order the customer finished the wizard with, tied back to its cycle.
     *
     * Priced from what they just agreed to, not from the schedule: the whole
     * point of sending them through the wizard is that the basket, the window
     * and the payment method are theirs to change.
     *
     * One transaction, because an order that exists while its prompt still says
     * "unanswered" would be asked for a second time.
     *
     * @param  array<string, mixed>  $data
     */
    public function placeFromPrompt(RecurrencePrompt $prompt, User $customer, array $data): Order
    {
        if ($prompt->isAnswered()) {
            throw new RuntimeException('already_answered');
        }

        return DB::transaction(function () use ($prompt, $customer, $data) {
            // The link is ours to set, never the client's: a request naming
            // someone else's schedule would otherwise file its order under it.
            $order = $this->orders->place($customer, [
                'recurrence_id' => $prompt->recurrence_id,
            ] + $data);

            $prompt->update([
                'answer' => 'confirmed',
                'answered_at' => now(),
                'order_id' => $order->id,
            ]);

            return $order;
        });
    }

    /**
     * «تحب أخلي دي كمياتك الافتراضية؟» — after the customer edited the basket.
     *
     * Only ever on the customer's say-so. A schedule that silently rewrote
     * itself from the last order would make «كل أسبوع» mean something different
     * every week, and the customer would have no way to see it happen.
     *
     * @param  array<int, array{item_id: int|string, qty: int|string}>  $items
     */
    public function updateItems(OrderRecurrence $recurrence, array $items): OrderRecurrence
    {
        $recurrence->update([
            'items' => array_map(
                fn (array $line) => ['item_id' => (int) $line['item_id'], 'qty' => (int) $line['qty']],
                array_values($items)
            ),
        ]);

        return $recurrence->refresh();
    }

    /**
     * «مش محتاج» — skip this cycle. The schedule stays.
     */
    public function decline(RecurrencePrompt $prompt): RecurrencePrompt
    {
        if ($prompt->isAnswered()) {
            throw new RuntimeException('already_answered');
        }

        $prompt->update(['answer' => 'declined', 'answered_at' => now()]);

        return $prompt->refresh();
    }

    public function pause(OrderRecurrence $recurrence): OrderRecurrence
    {
        $recurrence->update(['status' => 'paused']);

        return $recurrence->refresh();
    }

    /**
     * Resume, and re-anchor the next question to the future.
     *
     * Without the re-anchor a schedule paused for two months would come back due
     * in the past and fire immediately.
     */
    public function resume(OrderRecurrence $recurrence): OrderRecurrence
    {
        $next = Carbon::parse($recurrence->next_prompt_on ?? now());

        while ($next->isBefore(now()->startOfDay())) {
            $next = $recurrence->advanceFrom($next);
        }

        $recurrence->update(['status' => 'active', 'next_prompt_on' => $next->toDateString()]);

        return $recurrence->refresh();
    }

    public function cancel(OrderRecurrence $recurrence): OrderRecurrence
    {
        $recurrence->update(['status' => 'cancelled', 'next_prompt_on' => null]);

        return $recurrence->refresh();
    }

    /**
     * Advance the cycle, from the cycle date rather than from today.
     *
     * A scheduler that runs a day late must not drag every future cycle a day
     * later with it — «كل يوم اثنين» has to stay on Mondays. The loop covers a
     * schedule that has been unattended for several cycles.
     */
    private function advance(OrderRecurrence $recurrence, Carbon $from): void
    {
        $next = $recurrence->advanceFrom($from);

        while ($next->isBefore(now()->startOfDay())) {
            $next = $recurrence->advanceFrom($next);
        }

        $recurrence->update(['next_prompt_on' => $next->toDateString()]);
    }

    /**
     * The first date a new schedule should ask on.
     */
    private function nextOccurrence(string $frequency, ?int $dayOfWeek): Carbon
    {
        $today = now()->startOfDay();

        if ($frequency === 'monthly' || $dayOfWeek === null) {
            return $today->copy()->addWeek();
        }

        // Iso weekdays: 1 = Monday … 7 = Sunday. Today never counts — a schedule
        // created this morning should not ask this afternoon.
        $target = $today->copy()->addDay();

        while ($target->dayOfWeekIso !== $dayOfWeek) {
            $target->addDay();
        }

        return $target;
    }
}
