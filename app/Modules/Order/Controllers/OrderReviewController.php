<?php

namespace App\Modules\Order\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderPriceQuery;
use App\Modules\Order\Requests\OrderReviewRequest;
use App\Modules\Order\Services\OrderReviewService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The laundry counts the pieces.
 *
 * The tenant scope on Order does the isolation here: a laundry loading an order
 * id that is not its own gets a 404 from findOrFail, so there is no ownership
 * check to forget. What this controller adds on top is the *timing* rule — an
 * order can only be priced while it is reviewable.
 */
class OrderReviewController extends Controller
{
    public function __construct(private readonly OrderReviewService $reviews) {}

    public function store(OrderReviewRequest $request, $id)
    {
        $order = Order::with('items')->findOrFail($id);

        try {
            $this->reviews->review(
                $order,
                $request->validated()['lines'],
                $request->validated()['note'] ?? null,
                $request->user()
            );
        } catch (RuntimeException $e) {
            return back()
                ->with('error', $this->message($e, $order->service && ! $order->service->isPerItem()))
                ->withInput();
        }

        return back()->with('success', __('Final price sent to the customer for confirmation.'));
    }

    /**
     * Answer «لدي استفسار عن السعر».
     */
    public function answerQuery(Request $request, $id)
    {
        $request->validate(['answer' => ['required', 'string', 'max:2000']]);

        // Reached through the order so the tenant scope applies — otherwise a
        // query id alone would be enough to read another laundry's customer.
        $order = Order::findOrFail($id);

        $query = OrderPriceQuery::where('order_id', $order->id)
            ->findOrFail($request->get('query_id'));

        try {
            $this->reviews->answerQuery($query, $request->get('answer'), $request->user());
        } catch (RuntimeException) {
            return back()->with('error', __('This question has already been answered.'));
        }

        return back()->with('success', __('Answer sent to the customer.'));
    }

    private function message(RuntimeException $e, bool $quoted = false): string
    {
        $code = $e->getMessage();

        if (str_starts_with($code, 'unpriced_items:')) {
            // The same condition, two different mistakes. On a catalogued service
            // the price list is missing a piece and somebody has to go and add
            // it; on a quoted one the person in front of the screen simply left a
            // box empty, and telling them to "set the price first" sends them
            // looking for a settings page that will not help.
            return $quoted
                ? __('Enter a price for every piece you counted.')
                : __('Some pieces have no price for this service. Set their price first.');
        }

        return match ($code) {
            'not_reviewable' => __('This order cannot be reviewed at its current stage.'),
            'empty_review' => __('Please enter at least one piece.'),
            'service_not_found' => __('This order has no service.'),
            default => __('Could not save the review.'),
        };
    }
}
