<?php

namespace App\Modules\Notification\Services;

use App\Modules\Notification\Data\NotificationMessage;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderPriceQuery;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Models\RecurrencePrompt;
use App\Modules\Payment\Models\Refund;
use App\Modules\User\Models\User;

/**
 * The copy, and who gets it.
 *
 * Kept apart from the dispatcher on purpose: the dispatcher knows about channels
 * and preferences and knows nothing about laundry, and this knows what to say and
 * nothing about how it travels.
 *
 * The wording reuses the vocabulary already agreed in the Arabic translation —
 * «تمت مراجعة القطع», «السعر النهائي», «في الطريق للاستلام» — so a notification
 * and the screen it opens say the same words. The design never specified this
 * copy: its own notifications frame is untouched boilerplate from a car-servicing
 * template, so the moments come from the business flow and the words come from
 * the status vocabulary.
 */
class OrderNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function orderPlaced(Order $order): void
    {
        $this->toCustomer($order, new NotificationMessage(
            event: NotificationEvent::OrderPlaced,
            title: __('Your order has been placed.'),
            body: __('Order :code — we will collect your pieces at the chosen time.', ['code' => $order->code]),
            url: "/orders/{$order->id}",
            data: ['order_id' => (string) $order->id],
            subject: $order,
        ));
    }

    public function driverOnWay(Order $order): void
    {
        $this->toCustomer($order, new NotificationMessage(
            event: NotificationEvent::DriverOnWay,
            title: __('Driver on the way'),
            body: __('Our driver is on the way to collect order :code.', ['code' => $order->code]),
            url: "/orders/{$order->id}/track",
            data: ['order_id' => (string) $order->id],
            subject: $order,
        ));
    }

    /**
     * The one that matters most: until this is read, the order does not move.
     */
    public function finalPriceReady(Order $order): void
    {
        $this->toCustomer($order, new NotificationMessage(
            event: NotificationEvent::FinalPriceReady,
            title: __('The final price is ready'),
            body: __('We reviewed your pieces. Order :code comes to :total — confirm to start cleaning.', [
                'code' => $order->code,
                'total' => moneyFormat($order->payableTotal()),
            ]),
            url: "/orders/{$order->id}/review",
            data: ['order_id' => (string) $order->id],
            subject: $order,
        ));
    }

    /**
     * The laundry is told it may start.
     */
    public function priceConfirmed(Order $order): void
    {
        $laundry = $order->laundry;

        if (! $laundry) {
            return;
        }

        $message = new NotificationMessage(
            event: NotificationEvent::PriceConfirmed,
            title: __('Price confirmed'),
            body: __('The customer confirmed order :code. You can start cleaning.', ['code' => $order->code]),
            url: "/admin/order/show/{$order->id}",
            data: ['order_id' => (string) $order->id],
            subject: $order,
        );

        $this->dispatcher->sendMany(User::where('laundry_id', $laundry->id)->get(), $message);
    }

    public function readyForDelivery(Order $order): void
    {
        $this->toCustomer($order, new NotificationMessage(
            event: NotificationEvent::OrderReadyForDelivery,
            title: __('Ready for delivery'),
            body: __('Order :code is clean and on its way back to you.', ['code' => $order->code]),
            url: "/orders/{$order->id}/track",
            data: ['order_id' => (string) $order->id],
            subject: $order,
        ));
    }

    public function delivered(Order $order): void
    {
        $this->toCustomer($order, new NotificationMessage(
            event: NotificationEvent::OrderDelivered,
            title: __('Delivered'),
            body: __('Order :code has been delivered. Thank you.', ['code' => $order->code]),
            url: "/orders/{$order->id}",
            data: ['order_id' => (string) $order->id],
            subject: $order,
        ));
    }

    /**
     * «محتاج تغسل النهاردة؟» — the question the whole recurrence feature rests on.
     */
    public function recurrencePrompt(RecurrencePrompt $prompt): void
    {
        $customer = $prompt->recurrence?->customer;

        if (! $customer) {
            return;
        }

        $this->dispatcher->send($customer, new NotificationMessage(
            event: NotificationEvent::RecurrencePrompt,
            title: __('Do you need a wash today?'),
            body: __('Your repeat order is due today. Confirm and we will collect, or skip this time.'),
            url: "/recurrences/prompts/{$prompt->id}",
            data: ['prompt_id' => (string) $prompt->id],
            subject: $prompt,
        ));
    }

    public function taskAssigned(OrderTask $task): void
    {
        $driver = $task->driver;

        if (! $driver) {
            return;
        }

        $this->dispatcher->send($driver, new NotificationMessage(
            event: NotificationEvent::TaskAssigned,
            title: __($task->type->label()),
            body: __('Order :code has been assigned to you.', ['code' => $task->order?->code]),
            url: "/driver/tasks/{$task->id}",
            data: ['task_id' => (string) $task->id],
            subject: $task,
        ));
    }

    public function refundDecided(Refund $refund): void
    {
        $customer = $refund->customer;

        if (! $customer) {
            return;
        }

        $settled = $refund->status === Refund::SETTLED;

        $this->dispatcher->send($customer, new NotificationMessage(
            event: NotificationEvent::RefundDecided,
            title: __($refund->statusLabel()),
            body: $settled
                ? __('Your refund of :amount has been paid.', ['amount' => moneyFormat($refund->amount)])
                : __('Your refund request for order :code has been reviewed.', ['code' => $refund->order?->code]),
            url: "/orders/{$refund->order_id}/refunds",
            data: ['refund_id' => (string) $refund->id],
            subject: $refund,
        ));
    }

    public function priceQuestionAnswered(OrderPriceQuery $query): void
    {
        $customer = $query->customer;

        if (! $customer) {
            return;
        }

        $this->dispatcher->send($customer, new NotificationMessage(
            event: NotificationEvent::PriceQuestionAnswered,
            title: __('Your question has been answered'),
            body: $query->answer ?? '',
            url: "/orders/{$query->order_id}/queries",
            data: ['order_id' => (string) $query->order_id],
            subject: $query,
        ));
    }

    private function toCustomer(Order $order, NotificationMessage $message): void
    {
        $customer = $order->customer;

        if ($customer) {
            $this->dispatcher->send($customer, $message);
        }
    }
}
