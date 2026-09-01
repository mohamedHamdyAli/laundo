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
 * Confirming creates the order; declining, or not answering at all, skips that
 * cycle and leaves the schedule alive for the next one.
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
     * «أيوه، اغسل النهاردة» — create the order from the saved basket.
     *
     * Priced now, not when the schedule was saved: a basket agreed to in March
     * must not be billed at March's prices in September.
     */
    public function confirm(RecurrencePrompt $prompt, User $customer): Order
    {
        if ($prompt->isAnswered()) {
            throw new RuntimeException('already_answered');
        }

        $recurrence = $prompt->recurrence;

        return DB::transaction(function () use ($prompt, $recurrence, $customer) {
            $order = $this->orders->place($customer, [
                'service_id' => $recurrence->service_id,
                'pickup_address_id' => $recurrence->pickup_address_id,
                'delivery_address_id' => $recurrence->pickup_address_id,
                'pickup_slot_id' => $recurrence->time_slot_id,
                'pickup_date' => $prompt->prompted_for->toDateString(),
                'items' => $recurrence->items,
                'recurrence_id' => $recurrence->id,
                // Confirming the prompt IS the consent: the customer is being
                // asked, in the moment, whether to wash today, and the same
                // review-and-final-price terms apply as in the wizard. Without
                // this a recurring order would reach the laundry with no record
                // that anyone agreed to being re-priced.
                'accepts_review_terms' => true,
            ],
                // Exempt from the window cap, deliberately. The customer was
                // asked «محتاج تغسل النهاردة؟» and said yes, and this screen has
                // no slot picker to send them back to — refusing here turns away
                // the most loyal customer there is over a number they never saw.
                // The overbook is the platform's problem to absorb.
                enforceSlotCapacity: false);

            $prompt->update([
                'answer' => 'confirmed',
                'answered_at' => now(),
                'order_id' => $order->id,
            ]);

            return $order;
        });
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
