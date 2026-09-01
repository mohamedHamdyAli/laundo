<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Complaint\Enums\ComplaintCategory;
use App\Modules\Complaint\Models\Complaint;
use App\Modules\Complaint\Services\ComplaintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * «تقديم شكوى» — for the customer and the driver alike; both apps offer it.
 *
 * Operations answers by phone, so this returns no reply thread. It returns a
 * reference the complainant can quote on that call, and lets them see the status.
 * That is the minimum that stops a complaint being a black hole: without it the
 * customer taps send and never learns whether anything happened.
 *
 * `internal_note` is never in a response here. It is where operations writes what
 * was said on the phone, and it is not addressed to the complainant.
 */
class ComplaintController extends Controller
{
    public function __construct(private readonly ComplaintService $complaints) {}

    /**
     * The categories to offer, so two apps do not maintain the same list.
     */
    public function categories(): JsonResponse
    {
        return successReturnData($this->complaints->categories());
    }

    /**
     * The complainant's own complaints, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $complaints = Complaint::with('attachments')->where('user_id', $request->user()->id)
            ->with('order:id,code')
            ->latest('id')
            ->get()
            ->map(fn (Complaint $c) => $this->present($c))
            ->values();

        return successReturnData($complaints);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'in:'.implode(',', ComplaintCategory::values())],
            'body' => ['required', 'string', 'min:5', 'max:2000'],
            // Optional: the driver complains about no order in particular, and the
            // customer reaches this from an order screen.
            'order_id' => ['nullable', 'integer'],

            // «المرفقات (اختياري) — أرفق صورًا توضح المشكلة بوضوح». For the
            // complaints this exists for — a stain that did not come out, a torn
            // seam — the photograph is most of the evidence.
            'photos' => ['nullable', 'array', 'max:'.ComplaintService::MAX_ATTACHMENTS],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        // `validate()` returns the uploaded files as arrays on some clients; the
        // service wants UploadedFile objects, and this is where they live.
        $validated['photos'] = $request->file('photos', []);

        try {
            $complaint = $this->complaints->submit($request->user(), $validated);
        } catch (RuntimeException $e) {
            return $e->getMessage() === 'order_not_found'
                ? failReturnNotFound(__('Order not found.'))
                : failReturnMsg(__('Could not submit your complaint.'));
        }

        return successReturnCreated(
            $this->present($complaint),
            // The reference is the point of this message: it is what gets quoted
            // when somebody calls back.
            __('Your complaint has been received. Reference: :reference', ['reference' => $complaint->reference])
        );
    }

    public function show(Request $request, $id): JsonResponse
    {
        $complaint = Complaint::with('attachments')->where('user_id', $request->user()->id)
            ->with('order:id,code')
            ->find($id);

        if (! $complaint) {
            return failReturnNotFound(__('Complaint not found.'));
        }

        return successReturnData($this->present($complaint));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Complaint $complaint): array
    {
        return [
            'id' => $complaint->id,
            'reference' => $complaint->reference,
            'category' => $complaint->category,
            'category_label' => __($complaint->categoryCase()->label()),
            'body' => $complaint->body,
            'status' => $complaint->status,
            // Translated here so the app does not keep its own copy of four labels.
            'status_label' => __($complaint->statusCase()->label()),
            'is_open' => $complaint->statusCase()->isOpen(),
            'order_code' => $complaint->order?->code,
            'created_at' => $complaint->created_at?->toIso8601String(),
            'attachments' => $complaint->attachments->map(fn ($a) => $a->url())->values(),
            // Deliberately absent: internal_note. It is not addressed to them.
        ];
    }
}
