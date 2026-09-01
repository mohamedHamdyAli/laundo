<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Order\Enums\RatingTag;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRating;
use App\Modules\Order\Services\RatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * «ما رأيك في تجربتك؟».
 *
 * Isolation works as it does everywhere else on the customer API: every lookup
 * starts from `$request->user()->orders()`, so somebody else's order id is a 404
 * rather than a leak.
 *
 * The GET is not just symmetry. The design puts a «تقييم» button on a completed
 * order in the list, and a button that opens a screen which then refuses is worse
 * than no button — so `can_rate` is served rather than inferred by the client.
 */
class OrderRatingController extends Controller
{
    public function __construct(private readonly RatingService $ratings) {}

    /**
     * The rating screen's state: what is already there, and whether it may change.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $order = $this->find($request, $id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $rating = $this->ratings->forOrder($order);

        return successReturnData([
            'can_rate' => $this->ratings->canRate($order, $request->user()),
            'rating' => $rating ? $this->present($rating) : null,
            // The chips, with their labels translated here rather than hardcoded
            // in two apps that would then drift apart.
            'available_tags' => array_map(
                fn (RatingTag $tag) => ['value' => $tag->value, 'label' => __($tag->label())],
                RatingTag::cases()
            ),
        ]);
    }

    /**
     * «إرسال التقييم».
     */
    public function store(Request $request, $id): JsonResponse
    {
        $order = $this->find($request, $id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $validated = $request->validate([
            // The five stars in the design. Required — the aspects are optional
            // because «تخطي» exists, but a rating with no score is not a rating.
            'overall' => ['required', 'integer', 'min:1', 'max:5'],
            'service_quality' => ['nullable', 'integer', 'min:1', 'max:5'],
            'delivery' => ['nullable', 'integer', 'min:1', 'max:5'],
            'timing' => ['nullable', 'integer', 'min:1', 'max:5'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'in:'.implode(',', RatingTag::values())],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $rating = $this->ratings->rate($order, $request->user(), $validated);
        } catch (RuntimeException $e) {
            return match ($e->getMessage()) {
                // Already rated is not really an error from the customer's side —
                // they tapped twice — but it must not silently overwrite either.
                'already_rated' => failReturnMsg(__('You have already rated this order.')),
                'order_not_finished' => failReturnMsg(__('You can rate an order once it has been delivered.')),
                'not_your_order' => failReturnNotFound(__('Order not found.')),
                default => failReturnMsg(__('Could not save your rating.')),
            };
        }

        return successReturnCreated(
            $this->present($rating),
            __('Thank you. Your rating has been sent.')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OrderRating $rating): array
    {
        return [
            'id' => $rating->id,
            'overall' => $rating->overall,
            'service_quality' => $rating->service_quality,
            'delivery' => $rating->delivery,
            'timing' => $rating->timing,
            'tags' => array_map(
                fn (RatingTag $tag) => ['value' => $tag->value, 'label' => __($tag->label())],
                $rating->tagCases()
            ),
            'comment' => $rating->comment,
            'created_at' => $rating->created_at?->toIso8601String(),
        ];
    }

    private function find(Request $request, $id): ?Order
    {
        return $request->user()->orders()->find($id);
    }
}
