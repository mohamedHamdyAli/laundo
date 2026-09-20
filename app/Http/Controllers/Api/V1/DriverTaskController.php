<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\TaskService;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Payment\Services\EarningService;
use App\Modules\Wallet\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The driver app's task screens.
 *
 * Isolation is the same rule as everywhere else on this API: every lookup starts
 * from the authenticated driver's own tasks, so another driver's id is a 404
 * rather than a leak. `TaskService` checks the holder again on the way in —
 * belt and braces, because a service that trusts its caller breaks the first time
 * something other than this controller calls it.
 */
class DriverTaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    /**
     * «قائمة المهام», with the design's two filter rows.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->scope($request);

        // الكل / جديدة / قيد التنفيذ / مكتملة / متأخرة
        $query = match ($request->get('state', 'all')) {
            'new' => $query->where('status', TaskStatus::Assigned->value),
            'in_progress' => $query->where('status', TaskStatus::Started->value),
            'completed' => $query->where('status', TaskStatus::Completed->value),
            'late' => $query->late(),
            default => $query,
        };

        $this->applyKind($query, $request->get('kind'));
        $this->applySearch($query, $request->get('query'));

        $tasks = $query->inDueOrder()
            ->paginate(min((int) $request->get('per_page', 20), 50));

        return successReturnPaginated(
            array_map(fn (OrderTask $task) => $this->summary($task), $tasks->items()),
            $tasks
        );
    }

    /**
     * The home screen's counters plus the task in hand.
     */
    public function summaryScreen(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $today = now()->startOfDay();

        $base = fn () => OrderTask::where('driver_id', $driver->id);

        $current = $this->scope($request)
            ->whereIn('status', [TaskStatus::Started->value, TaskStatus::Assigned->value])
            ->orderByRaw("status = '".TaskStatus::Started->value."' desc")
            ->inDueOrder()
            ->first();

        return successReturnData([
            'is_available' => (bool) $driver->profile?->is_available,
            'counters' => [
                // استلام / تسليم / مكتملة / متأخرة
                'collections' => (clone $base())->open()->whereIn('type', [
                    TaskType::PickupFromCustomer->value, TaskType::CollectFromLaundry->value,
                ])->count(),
                'deliveries' => (clone $base())->open()->whereIn('type', [
                    TaskType::DeliverToLaundry->value, TaskType::DeliverToCustomer->value,
                ])->count(),
                'completed_today' => (clone $base())->where('status', TaskStatus::Completed->value)
                    ->where('completed_at', '>=', $today)->count(),
                'late' => (clone $base())->late()->count(),
            ],
            'current_task' => $current ? $this->summary($current) : null,
        ]);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $task = $this->find($request, $id);

        if (! $task) {
            return failReturnNotFound(__('Task not found.'));
        }

        return successReturnData($this->detail($task));
    }

    /**
     * «السجل» — what is already done.
     *
     * Takes the same three filters as the live list plus a day, because the
     * screen draws all four and the app was applying three of them to the page
     * it happened to be holding. Client-side filtering of a paginated list is
     * wrong by exactly the rows it has not loaded: past fifty finished legs, a
     * search for an old order code found nothing and a date in a previous week
     * came back empty — both of them confidently, which is the worst way to be
     * wrong.
     */
    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'state' => ['nullable', Rule::in(['all', 'completed', 'failed', 'cancelled'])],
            'kind' => ['nullable', Rule::in(['collection', 'delivery'])],
            // The driver's own day, not a timestamp: the screen offers a date
            // picker. Validated rather than parsed leniently so a malformed
            // value is a 422 the app can show, not a silently ignored filter.
            'date' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $this->scope($request)->finished();

        $state = $request->get('state', 'all');

        if ($state !== 'all' && $state !== null) {
            $query->where('status', TaskStatus::from($state)->value);
        }

        $this->applyKind($query, $request->get('kind'));
        $this->applySearch($query, $request->get('query'));
        $this->applyDay($query, $request->get('date'));

        // `id` behind `updated_at` for the same reason the live list is tie-broken:
        // a batch of legs finished in one transaction shares a timestamp to the
        // second, and an unstable tail duplicates rows across pages.
        $tasks = $query->latest('updated_at')->latest('id')
            ->paginate(min((int) $request->get('per_page', 20), 50));

        $payload = [];

        foreach ($tasks->items() as $task) {
            $payload[] = $this->summary($task) + [
                'started_at' => $task->started_at ? humanDate($task->started_at) : null,
                'started_at_iso' => isoDate($task->started_at),
                'finished_at' => $task->completed_at ? humanDate($task->completed_at) : null,
                'finished_at_iso' => isoDate($task->completed_at),
                'duration_minutes' => $task->durationMinutes(),
                'failure_reason' => $task->failure_reason
                    ? __($task->failure_reason->label())
                    : null,
                'failure_note' => $task->failure_note,
            ];
        }

        return successReturnPaginated($payload, $tasks);
    }

    /**
     * «بدء المهمة».
     */
    public function start(Request $request, $id): JsonResponse
    {
        $task = $this->find($request, $id);

        if (! $task) {
            return failReturnNotFound(__('Task not found.'));
        }

        try {
            $task = $this->tasks->start($task, $this->driver($request));
        } catch (RuntimeException $e) {
            return $this->translate($e);
        }

        return successReturnData($this->detail($task), __('Task started.'));
    }

    /**
     * «مسح رمز الطلب».
     */
    public function verify(Request $request, $id): JsonResponse
    {
        $task = $this->find($request, $id);

        if (! $task) {
            return failReturnNotFound(__('Task not found.'));
        }

        $request->validate(['token' => ['required', 'string', 'max:191']]);

        try {
            $this->tasks->verify($task, $this->driver($request), $request->get('token'));
        } catch (RuntimeException $e) {
            return $this->translate($e);
        }

        return successReturnData([
            'verified' => true,
            'order_code' => $task->order?->code,
        ], __('Order verified.'));
    }

    /**
     * «تأكيد».
     */
    public function complete(Request $request, $id): JsonResponse
    {
        $task = $this->find($request, $id);

        if (! $task) {
            return failReturnNotFound(__('Task not found.'));
        }

        $request->validate([
            'piece_count' => ['nullable', 'integer', 'min:0', 'max:999'],
            'receiver_name' => ['nullable', 'string', 'max:191'],
            'collected_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'signature' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        try {
            $task = $this->tasks->complete(
                $task,
                $this->driver($request),
                $request->only(['piece_count', 'receiver_name', 'collected_amount', 'note']),
                (array) $request->file('photos', []),
                $request->file('signature'),
            );
        } catch (RuntimeException $e) {
            return $this->translate($e);
        }

        return successReturnData(
            $this->detail($task) + ['order_status' => $task->order?->status->value],
            __('Task completed successfully.')
        );
    }

    /**
     * «تعذر الاستلام».
     */
    public function fail(Request $request, $id): JsonResponse
    {
        $task = $this->find($request, $id);

        if (! $task) {
            return failReturnNotFound(__('Task not found.'));
        }

        $request->validate([
            'reason' => ['required', Rule::in(TaskFailureReason::values())],
            // Free text is the point of «سبب آخر», so it is required there.
            'note' => ['nullable', 'required_if:reason,other', 'string', 'max:1000'],
        ]);

        try {
            $task = $this->tasks->fail(
                $task,
                $this->driver($request),
                TaskFailureReason::from($request->get('reason')),
                $request->get('note'),
            );
        } catch (RuntimeException $e) {
            return $this->translate($e);
        }

        return successReturnData([
            'id' => $task->id,
            'status' => $task->status->value,
            'attempts' => $task->attempts,
            // Whether anyone will be sent again, which is what the driver wants
            // to know before they drive away.
            'requeued' => $task->status === TaskStatus::Pending || $task->driver_id !== null,
        ], __('The failure has been recorded.'));
    }

    /**
     * «أرباحي» — what the driver has earned, pending and released.
     */
    public function earnings(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $summary = app(EarningService::class)->summaryFor($driver);
        $wallet = app(WalletService::class)->forUser($driver);

        $recent = [];

        foreach (DriverEarning::where('driver_id', $driver->id)
            ->with('order:id,code')->latest('id')->limit(20)->get() as $earning) {
            $recent[] = [
                'id' => $earning->id,
                'order_code' => $earning->order?->code,
                'amount' => (float) $earning->amount,
                'status' => $earning->status,
                // The sum in words, for a driver asking why a job paid what it did.
                'calculation' => $earning->explain(),
                'at' => humanDate($earning->created_at),
                'at_iso' => isoDate($earning->created_at),
            ];
        }

        return successReturnData([
            // «الرصيد المعلق» — earned, but the order has not completed yet.
            'pending' => $summary['pending'],
            'released' => $summary['released'],
            'total' => $summary['total'],
            'withdrawable_balance' => (float) $wallet->balance,
            'recent' => $recent,
        ]);
    }

    /**
     * The reasons list, so the app does not hard-code it.
     */
    public function failureReasons(): JsonResponse
    {
        $payload = [];

        foreach (TaskFailureReason::cases() as $reason) {
            $payload[] = [
                'value' => $reason->value,
                'label' => __($reason->label()),
                'requires_note' => $reason === TaskFailureReason::Other,
            ];
        }

        return successReturnData($payload);
    }

    /**
     * Re-reads the authenticated user through the Driver model.
     *
     * `$request->user()` returns a plain User, so the role scope would not have
     * applied. Going through Driver is what guarantees a customer token cannot
     * operate these endpoints even if it reached them — the same rule as
     * DriverController.
     */
    private function driver(Request $request): Driver
    {
        $driver = Driver::with('profile')->find($request->user()->id);

        abort_unless($driver !== null, 403, 'This endpoint is for drivers.');

        return $driver;
    }

    private function scope(Request $request)
    {
        return OrderTask::where('driver_id', $this->driver($request)->id)
            // The addresses and the laundry are here because `summary()` now
            // carries the destination's coordinates. Left out, a page of fifteen
            // tasks would fire fifteen address queries and fifteen laundry ones
            // to draw a map pin — the N+1 the query-count tests exist to catch.
            ->with([
                'order:id,code,user_id,laundry_id,service_id,pickup_address_id,delivery_address_id,payment_method,payment_status,estimated_total,final_total',
                'order.pickupAddress:id,street,lat,lng',
                'order.deliveryAddress:id,street,lat,lng',
                'order.laundry:id,name,address,lat,lng',
            ]);
    }

    /**
     * «الكل / استلام / تسليم».
     *
     * One definition shared by the live list and the history, because two copies
     * of this mapping is two answers to «is collecting from the laundry a
     * pickup?» — it is, and the four legs pair up two and two.
     *
     * @param  mixed  $kind
     */
    private function applyKind($query, $kind): void
    {
        if ($kind === 'collection') {
            $query->whereIn('type', [
                TaskType::PickupFromCustomer->value,
                TaskType::CollectFromLaundry->value,
            ]);
        } elseif ($kind === 'delivery') {
            $query->whereIn('type', [
                TaskType::DeliverToLaundry->value,
                TaskType::DeliverToCustomer->value,
            ]);
        }
    }

    /**
     * «ابحث برقم الطلب أو اسم العميل».
     *
     * @param  mixed  $term
     */
    private function applySearch($query, $term): void
    {
        if (blank($term)) {
            return;
        }

        $term = trim((string) $term);

        $query->whereHas('order', function ($q) use ($term) {
            $q->where('code', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%"));
        });
    }

    /**
     * One day of history, as the driver reckons a day.
     *
     * The columns are UTC and the driver is not, so this resolves the requested
     * date in the display timezone and compares against the half-open range it
     * covers. `whereDate` on a UTC column would have answered a different
     * question — everything either side of midnight lands in the wrong day by
     * exactly the offset, which is three hours of work filed under yesterday.
     *
     * The timestamp compared is the one the row reports as its own: a completed
     * leg carries `completed_at`, while a failed or cancelled one never got that
     * far and is stamped only by the write that ended it. Same expression the
     * list is ordered by, so the filter and the ordering cannot disagree.
     *
     * @param  mixed  $date
     */
    private function applyDay($query, $date): void
    {
        if (blank($date)) {
            return;
        }

        $zone = displayTimezone();
        $start = Carbon::createFromFormat('Y-m-d', (string) $date, $zone)->startOfDay();

        $query->whereRaw(
            'coalesce(order_tasks.completed_at, order_tasks.updated_at) >= ?'
            .' and coalesce(order_tasks.completed_at, order_tasks.updated_at) < ?',
            [
                $start->copy()->utc()->toDateTimeString(),
                $start->copy()->addDay()->utc()->toDateTimeString(),
            ]
        );
    }

    private function find(Request $request, $id): ?OrderTask
    {
        return OrderTask::where('driver_id', $this->driver($request)->id)
            // `lat,lng` on the laundry: a constrained eager load returns null for
            // anything left out, so the laundry legs would still have answered
            // with no coordinates after the presenter stopped hardcoding them.
            ->with(['order.customer:id,name,phone,customer_reference', 'order.laundry:id,name,address,phone,lat,lng',
                'order.pickupAddress', 'order.deliveryAddress', 'order.service:id,name'])
            ->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(OrderTask $task): array
    {
        $order = $task->order;

        return [
            'id' => $task->id,
            'type' => $task->type->value,
            'type_label' => __($task->type->label()),
            'sequence' => $task->sequence,
            'status' => $task->status->value,
            'status_label' => __($task->status->label()),
            'is_late' => $task->isLate(),
            'can_start' => $task->status->isStartable() && $task->predecessorComplete(),
            'order_code' => $order?->code,
            'customer_name' => $order?->customer?->name,
            'destination' => $task->type->destinationFor($order ?? new Order),
            // The pin for the same destination, on the list rather than only in
            // the detail — a driver planning a route should not have to open
            // four screens to find out where the four legs are.
            'destination_location' => $task->type->coordinatesFor($order ?? new Order),
            'due_at' => $task->due_at ? humanDate($task->due_at) : null,
            // Null means «no time agreed yet», which is a real state: an order
            // may be placed without a delivery window, and postponing a leg
            // clears the one it had until the customer rebooks.
            'due_at_iso' => isoDate($task->due_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(OrderTask $task): array
    {
        $order = $task->order;
        $type = $task->type;
        $atCustomer = $type->involvesCustomer();

        $address = $type === TaskType::DeliverToCustomer
            ? $order?->deliveryAddress
            : $order?->pickupAddress;

        $coordinates = $type->coordinatesFor($order ?? new Order);

        return $this->summary($task) + [
            // What the screen has to render, driven by the leg rather than by the
            // app guessing from the type string.
            'requires_signature' => $type->requiresSignature(),
            'requires_piece_count' => $type->countsPieces(),
            'collects_payment' => $type->collectsPayment(),

            'service' => $order?->service ? getLocalizedValue($order->service, 'name') : null,
            'contact' => $atCustomer
                ? ['name' => $order?->customer?->name, 'phone' => $address?->callablePhone() ?? $order?->customer?->phone]
                : ['name' => $order?->laundry ? getLocalizedValue($order->laundry, 'name') : null,
                    'phone' => $order?->laundry?->phone],
            'address' => ($atCustomer ? [
                'street' => $address?->street,
                'building' => $address?->building,
                'floor' => $address?->floor,
                'apartment' => $address?->apartment,
                'landmark' => $address?->landmark,
            ] : [
                'street' => $order?->laundry?->address,
            ]) + [
                // Both branches through the same accessor. The laundry half used
                // to be a literal `'lat' => null` while the column was filled,
                // so legs two and three had no map.
                'lat' => $coordinates['lat'] ?? null,
                'lng' => $coordinates['lng'] ?? null,
            ],

            'driver_note' => $order?->driver_note,
            'special_instructions' => $order?->special_instructions,

            // «مراجعة الكمية — القطع الأصلية: 12» on the collection from the laundry.
            // Not nullsafe: order_id is a cascade-deleting FK, so a task without
            // an order cannot exist.
            'expected_pieces' => $order->final_items_count ?? $order->estimated_items_count,
            'laundry_note' => $order?->review_note,
            'piece_count' => $task->piece_count,

            // «تفاصيل الدفع» on the final leg.
            'payment' => $type->collectsPayment() ? [
                'amount_due' => $order ? $order->payableTotal() : null,
                'method' => $order?->payment_method,
                'status' => $order?->payment_status,
                'collected' => $task->collected_amount !== null ? (float) $task->collected_amount : null,
            ] : null,

            // «طباعة البطاقة» — everything the printed label carries. Assembled
            // here rather than left to the app to piece together from four other
            // fields, because a label with the wrong order on it is only found
            // out when the clothes come back to the wrong person.
            'ticket' => [
                'order_code' => $order?->code,
                'customer_reference' => $order?->customer?->customer_reference,
                'service' => $order?->service ? getLocalizedValue($order->service, 'name') : null,
                'date' => $order?->created_at ? humanDate($order->created_at, 'j M') : null,
                // Where it is going. `delivery_address_id` is not nullable, so
                // there is no second address to fall back to.
                'destination' => $order?->deliveryAddress->label,
                // What the QR encodes. The driver holds this parcel already, and
                // the scan check exists to catch the wrong bag off a pile rather
                // than to prove the driver is present — if it is ever meant to be
                // the second, it needs a short-lived token of its own.
                'qr' => $order?->qr_token,
            ],

            'signature_url' => $task->signatureUrl(),
            'started_at' => $task->started_at ? humanDate($task->started_at) : null,
            'started_at_iso' => isoDate($task->started_at),
            'completed_at' => $task->completed_at ? humanDate($task->completed_at) : null,
            'completed_at_iso' => isoDate($task->completed_at),
        ];
    }

    private function translate(RuntimeException $e): JsonResponse
    {
        return match ($e->getMessage()) {
            'not_your_task' => failReturnForbidden(__('This task is not assigned to you.')),
            'task_not_startable' => failReturnMsg(__('This task cannot be started now.')),
            'task_not_started' => failReturnMsg(__('Start the task before completing it.')),
            'previous_leg_incomplete' => failReturnMsg(__('The previous step has not been completed yet.')),
            'qr_mismatch' => failReturnMsg(__('This code does not match the order.')),
            'signature_required' => failReturnValidation(
                ['signature' => [__('A signature is required.')]], __('A signature is required.')
            ),
            'piece_count_required' => failReturnValidation(
                ['piece_count' => [__('Please enter the number of pieces.')]],
                __('Please enter the number of pieces.')
            ),
            'task_finished' => failReturnMsg(__('This task is already finished.')),
            default => failReturnMsg(__('We could not complete that.')),
        };
    }
}
