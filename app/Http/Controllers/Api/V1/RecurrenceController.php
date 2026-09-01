<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
            ->whereHas('recurrence', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with(['recurrence.service:id,name'])
            ->orderBy('prompted_for')
            ->get();

        $payload = [];

        foreach ($prompts as $prompt) {
            $payload[] = [
                'id' => $prompt->id,
                'recurrence_id' => $prompt->recurrence_id,
                'for_date' => $prompt->prompted_for->toDateString(),
                'service' => $prompt->recurrence?->service
                    ? getLocalizedValue($prompt->recurrence->service, 'name')
                    : null,
                'question' => __('Do you need a wash today?'),
            ];
        }

        return successReturnData($payload);
    }

    /**
     * «أيوه» — the order is created here, from the saved basket at today's prices.
     */
    public function confirmPrompt(Request $request, $id): JsonResponse
    {
        $prompt = $this->findPrompt($request, $id);

        if (! $prompt) {
            return failReturnNotFound(__('Request not found.'));
        }

        try {
            $order = $this->recurrences->confirm($prompt, $request->user());
        } catch (RuntimeException $e) {
            return $e->getMessage() === 'already_answered'
                ? failReturnMsg(__('You have already answered this request.'))
                : failReturnMsg(__('We could not create your order.'));
        }

        return successReturnCreated([
            'order_id' => $order->id,
            'code' => $order->code,
            'total' => (float) $order->estimated_total,
        ], __('Your order has been placed.'));
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
     * @return array<string, mixed>
     */
    private function present(OrderRecurrence $schedule): array
    {
        $items = [];

        foreach ($schedule->items as $line) {
            $items[] = ['item_id' => (int) $line['item_id'], 'qty' => (int) $line['qty']];
        }

        return [
            'id' => $schedule->id,
            'frequency' => $schedule->frequency,
            'day_of_week' => $schedule->day_of_week,
            'status' => $schedule->status,
            'service' => $schedule->service ? getLocalizedValue($schedule->service, 'name') : null,
            'service_id' => $schedule->service_id,
            'pickup_address_id' => $schedule->pickup_address_id,
            'time_slot' => $schedule->timeSlot?->label(),
            'items' => $items,
            'next_prompt_on' => $schedule->next_prompt_on?->toDateString(),
        ];
    }
}
