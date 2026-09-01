<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Services\InvoiceRenderer;

/**
 * «تحميل الفاتورة» from the dashboard.
 *
 * The order is loaded through the tenant-scoped model, so a laundry can print its
 * own invoices and nobody else's.
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceRenderer $renderer) {}

    public function show($id)
    {
        $order = Order::findOrFail($id);

        return view('invoices.order', $this->renderer->data($order));
    }
}
