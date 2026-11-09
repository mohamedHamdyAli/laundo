<?php

namespace App\Modules\Order\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\FinishedWashing;
use App\Support\LaundryContext;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * «خلص الغسيل» from the order screen (the client's sheet, item 18, 2026-11-08).
 *
 * The laundry's person presses it — the owner, or staff, who hold only
 * `order.view` and are the ones at the machines: inside a laundry the order
 * being its own (the tenant scope, so another laundry's id is a 404) is the
 * gate. The platform presses it on a laundry's behalf with `order.update`.
 */
class OrderWashingController extends Controller
{
    public function __construct(private readonly FinishedWashing $washing) {}

    public function finish(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        abort_unless(self::mayFinish(), 403);

        try {
            $this->washing->mark($order, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', match ($e->getMessage()) {
                'not_cleaning' => __('Only an order being cleaned can be marked finished.'),
                default => throw $e,
            });
        }

        return back()->with('success', __('Marked as finished — a driver will be sent to collect it.'));
    }

    /**
     * Who sees and may press the button: anyone at the laundry who can open
     * the order, or the platform with `order.update`.
     */
    public static function mayFinish(): bool
    {
        return LaundryContext::currentId() !== null ? canDo('order.view') : canDo('order.update');
    }
}
