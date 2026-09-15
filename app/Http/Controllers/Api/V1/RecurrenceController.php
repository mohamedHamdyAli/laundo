<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecurrenceItemsRequest;
use App\Http\Requests\Api\V1\RecurrenceRequest;
use App\Modules\Order\Models\OrderRecurrence;
use App\Modules\Order\Models\RecurrencePrompt;
use App\Modules\Order\Services\RecurrenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Repeat schedules, and the question they raise.
 *
 * The flow the business specified: the schedule comes due, the customer is asked
 * «محتاج تغسل النهاردة؟», and *their answer* decides whether an order exists. So
 * this controller has two halves — managing schedules, and answering prompts.
 */
class RecurrenceController extends Controller
{
    public function __construct(private readonly RecurrenceService $recurrences) {}

    public function index(Request $request): JsonResponse
    {
        $schedules = OrderRecurrence::where('user_id', $request->user()->id)
            ->with(['service:id,name', 'pickupAddress:id,label,street', 'timeSlot'])
            ->latest('id')
            ->get();

        $payload = [];

        foreach ($schedules as $schedule) {
            $payload[] = $this->present($schedule);
        }

        return successReturnData($payload);
    }

    public function store(RecurrenceRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Same rule as everywhere else: the address must be the caller's own.
        if (! $request->user()->addresses()->whereKey($data['pickup_address_id'])->exists()) {
            return failReturnNotFound(__('Pickup address not found.'));
        }

        $schedule = $this->recurrences->create($request->user(), $data);

        return successReturnCreated(
            $this->present($schedule->fresh(['service', 'pickupAddress', 'timeSlot'])),
            __('Repeat schedule saved.')
        );
    }

    public function pause(Request $request, $id): JsonResponse
    {
        $schedule = $this->findSchedule($request, $id);

        if (! $schedule) {
            return failReturnNotFound(__('Schedule not found.'));
        }

        return successReturnData(
            $this->present($this->recurrences->pause($schedule)),
            __('Repeat schedule paused.')
        );
    }

    public function resume(Request $request, $id): JsonResponse
    {
        $schedule = $this->findSchedule($request, $id);

        if (! $schedule) {
            return failReturnNotFound(__('Schedule not found.'));
        }

        return successReturnData(
            $this->present($this->recurrences->resume($schedule)),
            __('Repeat schedule resumed.')
        );
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $schedule = $this->findSchedule($request, $id);

        if (! $schedule) {
            return failReturnNotFound(__('Schedule not found.'));
        }

        $this->recurrences->cancel($schedule);

        return returnSuccessMsg(__('Repeat schedule cancelled.'));
    }

    /**
     * The questions waiting for an answer.
     */
    public function pendingPrompts(Request $request): JsonResponse
    {
        $prompts = RecurrencePrompt::whereNull('answer')
            ->whereHas('recurrence', fn ($q) => $q->where('user_id', $request->user()->id)
                // **Only a schedule that is still running may ask.** Without
                // this the list was «every unanswered row», so a cancelled or
                // paused schedule went on raising the question — the customer
                // was asked about a cycle they had already ended. Cancelling
                // closes its own prompts as well; this is the second half,
                // because pausing must not destroy a question a resumed
                // schedule would still want to ask.
                ->where('status', 'active'))
            ->with(['recurrence.service:id,name'])
            ->orderBy('prompted_for')
            ->get();

        $payload = [];

        foreach ($prompts as $prompt) {
            $recurrence = $prompt->recurrence;

            $payload[] = [
                'id' => $prompt->id,
                'recurrence_id' => $prompt->recurrence_id,
                // The same date under both names. `for_date` is what this
                // endpoint has always sent; `prompted_for` is the column's own
                // name and what the app reads. Cheaper than a breaking rename.
                'for_date' => $prompt->prompted_for->toDateString(),
                'prompted_for' => $prompt->prompted_for->toDateString(),
                'service' => $recurrence?->service
                    ? getLocalizedValue($recurrence->service, 'name')
                    : null,
                'service_id' => $recurrence?->service_id,
                // The basket this cycle is about. `confirm` hands back the same
                // thing, but a banner that has to fetch before it can say what
                // it is asking about cannot draw itself.
                'pickup_address_id' => $recurrence?->pickup_address_id,
                'items' => $this->basketOf($recurrence),
                // Both states, so a client logging a surprise can say which half
                // produced it. `status` is always `pending` here by definition —
                // it is sent so the row's shape does not change when a future
                // endpoint returns answered ones too.
                'status' => $prompt->answer ?? 'pending',
                'recurrence_status' => $recurrence?->status,
                'question' => __('Do you need a wash today?'),
            ];
        }

        return successReturnData($payload);
    }

    /**
     * «أيوه» — hands back the basket to open the wizard with, not an order.
     *
     * The customer still reviews the pieces, picks a window and chooses how to
     * pay; `POST /orders` carries `prompt_id` back and that is what closes this
     * question. Kept as a POST because it is the app's "yes" — it has no side
     * effect, and calling it twice returns the same basket.
     */
    public function confirmPrompt(Request $request, $id): JsonResponse
    {
        $prompt = $this->findPrompt($request, $id);

        if (! $prompt) {
            return failReturnNotFound(__('Request not found.'));
        }

        if ($prompt->isAnswered()) {
            return failReturnMsg(__('You have already answered this request.'));
        }

        return successReturnData($this->recurrences->basketFor($prompt));
    }

    /**
     * «تحب أخلي دي كمياتك الافتراضية؟» — offered after the customer edited the
     * basket on their way through the wizard.
     */
    public function updateItems(RecurrenceItemsRequest $request, $id): JsonResponse
    {
        $schedule = $this->findSchedule($request, $id);

        if (! $schedule) {
            return failReturnNotFound(__('Schedule not found.'));
        }

        return successReturnData(
            $this->present($this->recurrences->updateItems($schedule, $request->validated()['items'])),
            __('Repeat schedule updated.')
        );
    }

    /**
     * «مش محتاج» — skip this cycle. The schedule itself is untouched.
     */
    public function declinePrompt(Request $request, $id): JsonResponse
    {
        $prompt = $this->findPrompt($request, $id);

        if (! $prompt) {
            return failReturnNotFound(__('Request not found.'));
        }

        try {
            $this->recurrences->decline($prompt);
        } catch (RuntimeException) {
            return failReturnMsg(__('You have already answered this request.'));
        }

        return returnSuccessMsg(__('Skipped. We will ask again next time.'));
    }

    private function findSchedule(Request $request, $id): ?OrderRecurrence
    {
        return OrderRecurrence::where('user_id', $request->user()->id)->find($id);
    }

    private function findPrompt(Request $request, $id): ?RecurrencePrompt
    {
        return RecurrencePrompt::whereHas(
            'recurrence',
            fn ($q) => $q->where('user_id', $request->user()->id)
        )->with('recurrence')->find($id);
    }

    /**
     * The basket a schedule carries, as the app reads it.
     *
     * @return array<int, array{item_id: int, qty: int}>
     */
    private function basketOf(?OrderRecurrence $schedule): array
    {
        $items = [];

        foreach ($schedule?->items ?: [] as $line) {
            $items[] = ['item_id' => (int) $line['item_id'], 'qty' => (int) $line['qty']];
        }

        return $items;
    }

    /**
     * What a schedule looks like on the Repeat Schedules screen.
     *
     * @return array<string, mixed>
     */
    private function present(OrderRecurrence $schedule): array
    {
        $address = $schedule->pickupAddress;

        return [
            'id' => $schedule->id,
            'frequency' => $schedule->frequency,
            'day_of_week' => $schedule->day_of_week,
            'status' => $schedule->status,
            // The state in words as well as as a key. The screen draws a
            // «موقوفة» chip and was building it from the raw enum, which means
            // every client owns its own copy of our vocabulary.
            'status_label' => __($schedule->statusLabel()),
            // Derived here rather than left to the client: `status === 'paused'`
            // is our spelling of it, and the screen's rule — hide the next run
            // date while paused — should not depend on knowing that.
            'is_paused' => $schedule->status === 'paused',
            'service' => $schedule->service ? getLocalizedValue($schedule->service, 'name') : null,
            'service_id' => $schedule->service_id,
            'pickup_address_id' => $schedule->pickup_address_id,
            // The address itself, not only its id: the row names where the
            // pickup happens, and an id is not a name.
            'pickup_address' => $address ? [
                'id' => $address->id,
                'label' => $address->label,
                'line' => $address->street,
            ] : null,
            'time_slot_id' => $schedule->time_slot_id,
            'time_slot' => $schedule->timeSlot?->label(),
            'items' => $this->basketOf($schedule),
            // Two names for one date, for the same reason as the prompt above:
            // `next_prompt_on` is the column and what this endpoint has always
            // sent, `next_run_on` is what the app reads. Null while paused or
            // cancelled, which is the honest answer — there is no next one.
            'next_prompt_on' => $schedule->next_prompt_on?->toDateString(),
            'next_run_on' => $schedule->next_prompt_on?->toDateString(),
        ];
    }
}
