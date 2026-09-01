<?php

namespace App\Modules\Recurrence\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\OrderRecurrence;
use App\Modules\Order\Models\RecurrencePrompt;
use App\Modules\Order\Services\RecurrenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The repeat schedules — the last thing in this system nobody could see.
 *
 * `recurrences` has had a full API and a daily scheduled command since P6:
 * `PromptRecurringOrders` asks «محتاج تغسل النهاردة؟» every morning, and P11 made
 * that a real notification. None of it had a screen. Operations could not answer
 * "how many customers are on a schedule", "is anybody actually saying yes", or
 * "why is this customer getting a message every week" — and support had no way to
 * stop one.
 *
 * Not tenant-scoped, and deliberately so: a schedule belongs to a customer and
 * names a service, not a laundry. Its permission is the gate.
 */
class RecurrenceController extends Controller
{
    public function __construct(private readonly RecurrenceService $recurrences) {}

    public function index(Request $request)
    {
        $status = (string) $request->get('status', 'all');

        $recurrences = $this->listing($status)->paginate(15);

        $view = view('admin.recurrence.index', [
            'recurrences' => $recurrences,
            'status' => $status,
            'counts' => $this->counts(),
        ]);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if (! $request->ajax()) {
            return response()->json([], 400);
        }

        $term = (string) $request->get('query');
        $status = (string) $request->get('status', 'all');

        $recurrences = $this->listing($status)
            ->when($term !== '', fn ($q) => $q->whereHas(
                'customer',
                fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")
            ))
            ->paginate(15);

        return response()->json([
            'table' => view('admin.recurrence.partials._recurrence_table_body', [
                'recurrences' => $recurrences,
            ])->render(),
            'pagination' => $recurrences->withQueryString()->links()->toHtml(),
        ]);
    }

    /**
     * One schedule and every time it has asked.
     *
     * The prompt history is the point of this screen. A schedule that has asked
     * eight times and been answered twice is not a subscription, it is a weekly
     * notification the customer has learned to ignore — and that is invisible
     * from a status column.
     */
    public function show($id)
    {
        $recurrence = OrderRecurrence::with([
            'customer:id,name,phone',
            'service:id,name',
            'pickupAddress',
            'timeSlot',
        ])->findOrFail($id);

        $prompts = $recurrence->prompts()
            ->with('order:id,code,status')
            ->latest('prompted_for')
            ->paginate(20);

        return view('admin.recurrence.show', [
            'row' => $recurrence,
            'prompts' => $prompts,
            'answerRate' => $this->answerRate($recurrence),
        ]);
    }

    public function pause($id)
    {
        $recurrence = OrderRecurrence::findOrFail($id);

        if ($recurrence->status !== 'active') {
            return back()->with('error', __('Only an active schedule can be paused.'));
        }

        $this->recurrences->pause($recurrence);

        return back()->with('success', __('Schedule paused. No more prompts will be sent.'));
    }

    public function resume($id)
    {
        $recurrence = OrderRecurrence::findOrFail($id);

        if ($recurrence->status !== 'paused') {
            return back()->with('error', __('Only a paused schedule can be resumed.'));
        }

        $this->recurrences->resume($recurrence);

        return back()->with('success', __('Schedule resumed.'));
    }

    /**
     * Cancelling is final — the customer would have to create the schedule again.
     */
    public function cancel($id)
    {
        $recurrence = OrderRecurrence::findOrFail($id);

        if ($recurrence->status === 'cancelled') {
            return back()->with('error', __('This schedule is already cancelled.'));
        }

        $this->recurrences->cancel($recurrence);

        return back()->with('success', __('Schedule cancelled.'));
    }

    /**
     * @return Builder<OrderRecurrence>
     */
    private function listing(string $status)
    {
        return OrderRecurrence::with(['customer:id,name,phone', 'service:id,name', 'timeSlot'])
            ->withCount([
                'prompts',
                'prompts as answered_prompts_count' => fn ($q) => $q->whereNotNull('answer'),
                // `confirmed`/`declined`, not yes/no — the enum on
                // recurrence_prompts. A wrong value here matches nothing and
                // reports 0 orders for every schedule, forever, with no error.
                'prompts as confirmed_prompts_count' => fn ($q) => $q->where('answer', 'confirmed'),
            ])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw('status = ? desc', ['active'])
            ->orderBy('next_prompt_on')
            ->latest('id');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'active' => OrderRecurrence::where('status', 'active')->count(),
            'paused' => OrderRecurrence::where('status', 'paused')->count(),
            'cancelled' => OrderRecurrence::where('status', 'cancelled')->count(),
            // Asked and never answered. The figure that says whether the daily
            // prompt is a service or a nuisance.
            'unanswered' => RecurrencePrompt::whereNull('answer')->whereNotNull('prompted_at')->count(),
        ];
    }

    /**
     * How often this customer answers at all — null when never asked, because
     * "0%" and "never asked" are different things.
     */
    private function answerRate(OrderRecurrence $recurrence): ?float
    {
        $asked = $recurrence->prompts()->whereNotNull('prompted_at')->count();

        if ($asked === 0) {
            return null;
        }

        $answered = $recurrence->prompts()->whereNotNull('answer')->count();

        return round($answered / $asked * 100, 1);
    }
}
