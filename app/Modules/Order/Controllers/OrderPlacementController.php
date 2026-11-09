<?php

namespace App\Modules\Order\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddressRequest;
use App\Modules\Address\Models\Address;
use App\Modules\Order\Requests\PanelOrderQuoteRequest;
use App\Modules\Order\Requests\PanelOrderRequest;
use App\Modules\Order\Services\OutOfCoverage;
use App\Modules\Order\Services\PanelOrders;
use App\Modules\Order\Services\PlacementFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * «إنشاء طلب» — the panel taking an order for a customer. HTTP only; the rules
 * are `PanelOrders`, and behind it the app's own `OrderService`.
 *
 * Every failure comes back as a 422 against the field it is about, so
 * `form-validation.js` paints it beside that field and the operator keeps the
 * basket they typed.
 */
class OrderPlacementController extends Controller
{
    public function __construct(private readonly PanelOrders $desk) {}

    public function create()
    {
        try {
            $data = $this->desk->formData();
        } catch (RuntimeException) {
            abort(403);
        }

        return view('admin.order.create', $data);
    }

    /**
     * The customer picker — select2's `{results: [{id, text}]}`.
     */
    public function customers(Request $request): JsonResponse
    {
        $term = $request->get('query');

        try {
            $customers = $this->desk->customers(is_string($term) ? $term : null);
        } catch (RuntimeException) {
            abort(403);
        }

        return response()->json([
            'results' => $customers->map(fn ($user) => [
                'id' => $user->id,
                'text' => trim($user->name.' — '.$user->phone
                    .($user->customer_reference ? ' · '.$user->customer_reference : '')),
            ])->values(),
        ]);
    }

    public function addresses(int $id): JsonResponse
    {
        try {
            $addresses = $this->desk->addressesOf($id);
        } catch (RuntimeException $e) {
            $this->refuse($e);
        }

        return response()->json(['addresses' => $addresses->map(fn (Address $a) => $this->presentAddress($a))->values()]);
    }

    public function storeAddress(AddressRequest $request, int $id): JsonResponse
    {
        try {
            $address = $this->desk->addAddress($id, $request->validated());
        } catch (RuntimeException $e) {
            $this->refuse($e);
        }

        return response()->json(['address' => $this->presentAddress($address)], 201);
    }

    public function quote(PanelOrderQuoteRequest $request): JsonResponse
    {
        try {
            $quote = $this->desk->quote($request->validated());
        } catch (RuntimeException $e) {
            $this->refuse($e);
        }

        return response()->json($this->presentQuote($quote));
    }

    public function store(PanelOrderRequest $request): RedirectResponse
    {
        try {
            $order = $this->desk->place(
                $request->validated(),
                $request->user(),
                (array) $request->file('photos', []),
            );
        } catch (RuntimeException $e) {
            $this->refuse($e);
        }

        $done = __('Order #:code placed for :name.', ['code' => $order->code, 'name' => $order->customer?->name]);

        // To the order — unless this operator cannot open it: a role given
        // `order.create` alone would land on a 403 and read «not placed».
        return canDo('order.view')
            ? redirect()->route('admin.order.show', $order->id)->with('success', $done)
            : redirect()->route('admin.order.create')->with('success', $done);
    }

    /**
     * The service's failure codes, as the field each one is about — the
     * meaning from `PlacementFailure`, shared with the app's checkout.
     */
    private function refuse(RuntimeException $e): never
    {
        if ($e instanceof OutOfCoverage) {
            throw ValidationException::withMessages(array_map(
                fn () => __('This address is outside the areas we serve.'),
                $e->addresses
            ));
        }

        if ($e->getMessage() === 'not_yours_to_place') {
            abort(403);
        }

        if ($e->getMessage() === 'customer_not_found') {
            throw ValidationException::withMessages(['user_id' => __('This customer cannot be found, or their account is switched off.')]);
        }

        $failure = PlacementFailure::describe($e);

        throw ValidationException::withMessages([($failure['field'] ?? 'order') => $failure['message']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAddress(Address $address): array
    {
        $line = collect([$address->street, $address->building, $address->floor, $address->apartment, $address->landmark])
            ->filter(fn ($part) => filled($part))
            ->implode('، ');

        return [
            'id' => $address->id,
            'label' => $address->label,
            'line' => $line,
            'zone' => $address->zone ? getLocalizedValueDashboard($address->zone, 'name') : null,
            // An order from or to here would be refused — said when the address
            // is picked, before the basket is filled.
            'is_covered' => $address->isCovered(),
            'is_default' => (bool) $address->is_default,
        ];
    }

    /**
     * The price box: the same figures the app's summary shows, as labelled
     * rows, so the operator can read them to the customer as they are.
     *
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function presentQuote(array $quote): array
    {
        $rows = [['label' => __('Subtotal'), 'value' => moneyFormat($quote['subtotal'])]];

        // «رسوم المشوار»: one line per trip, so a visit another of the
        // customer's orders already pays shows as 0 and the operator can say why.
        $rows[] = ['label' => __('Pickup trip'), 'value' => moneyFormat($quote['pickup_fee'])];
        $rows[] = ['label' => __('Return trip'), 'value' => moneyFormat($quote['return_fee'])];

        if ((float) $quote['discount'] > 0) {
            $rows[] = ['label' => __('Discount'), 'value' => '− '.moneyFormat($quote['discount'])];
        }

        if ((float) ($quote['points_discount'] ?? 0) > 0) {
            $rows[] = [
                'label' => __('Points discount (:points points)', ['points' => $quote['points_used']]),
                'value' => '− '.moneyFormat($quote['points_discount']),
            ];
        }

        if ((float) $quote['cash_surcharge'] > 0) {
            $rows[] = ['label' => __('Cash fee'), 'value' => moneyFormat($quote['cash_surcharge'])];
        }

        if ((float) $quote['tax'] > 0) {
            $rows[] = ['label' => __('Tax'), 'value' => moneyFormat($quote['tax'])];
        }

        return [
            'items_count' => $quote['items_count'],
            'rows' => $rows,
            'total' => moneyFormat($quote['total']),
            'coupon_error' => $quote['coupon_error'] ?? null,
            'points_available' => $quote['points_available'] ?? 0,
            'laundry' => $quote['laundry']['name'] ?? null,
            'unpriced' => $quote['unpriced'] !== [],
        ];
    }
}
