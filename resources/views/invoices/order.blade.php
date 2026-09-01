{{--
    The invoice.

    Deliberately self-contained: inline styles, no layout, no assets. It is
    printed and it is emailed, and both of those strip anything that has to be
    fetched.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ getDefaultLanguage('is_rtl') === 'true' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $number }}</title>
    <style>
        body { font-family: "Segoe UI", Tahoma, Arial, sans-serif; color: #222; margin: 0; padding: 32px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #222; padding-bottom: 16px; }
        .muted { color: #666; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; }
        th, td { padding: 8px 10px; text-align: start; border-bottom: 1px solid #eee; font-size: 14px; }
        th { background: #f6f6f6; }
        .totals { margin-top: 16px; width: 320px; margin-inline-start: auto; }
        .totals td { border: none; padding: 4px 10px; }
        .grand { border-top: 2px solid #222; font-weight: bold; font-size: 16px; }
        .stamp { display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 13px; }
        .paid { background: #e6f6ea; color: #157347; }
        .unpaid { background: #fdeaea; color: #b02a37; }
        .estimate { background: #fff6e0; color: #8a6100; }
        @media print { body { padding: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="head">
        <div>
            <h2 style="margin:0;">{{ getSettingValue('App_Name') ?: 'Laundo' }}</h2>
            <div class="muted">{{ __('Invoice') }} {{ $number }}</div>
            <div class="muted">{{ humanDate($issued_at) }}</div>
        </div>
        <div style="text-align:end;">
            <div><strong>{{ __('Order') }} #{{ $order->code }}</strong></div>
            <div class="muted">{{ $order->customer?->name }}</div>
            <div class="muted">{{ $order->customer?->phone }}</div>
            @if ($order->laundry)
                <div class="muted">{{ getLocalizedValueDashboard($order->laundry, 'name') }}</div>
            @endif
        </div>
    </div>

    @unless ($is_final)
        {{-- Said plainly, because an estimate that looks like a bill is how a
             dispute starts. --}}
        <p class="stamp estimate" style="margin-top:16px;">
            {{ __('Estimated — the final price is set after the pieces are reviewed.') }}
        </p>
    @endunless

    <table>
        <thead>
            <tr>
                <th>{{ __('Item') }}</th>
                <th style="width:70px;">{{ __('Qty') }}</th>
                <th style="width:110px;">{{ __('Unit Price') }}</th>
                <th style="width:110px;">{{ __('Total') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line['name'] }}</td>
                    <td>{{ $line['qty'] }}</td>
                    <td>{{ moneyFormat($line['unit_price']) }}</td>
                    <td>{{ moneyFormat($line['line_total']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="muted">
                        {{ __('This service is priced after inspection.') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>{{ __('Subtotal') }}</td>
            <td style="text-align:end;">{{ moneyFormat($subtotal) }}</td>
        </tr>
        <tr>
            <td>{{ __('Delivery fee') }}</td>
            <td style="text-align:end;">{{ moneyFormat($delivery_fee) }}</td>
        </tr>
        @if ($discount > 0)
            <tr>
                <td>{{ __('Discount') }}</td>
                <td style="text-align:end;">- {{ moneyFormat($discount) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td>{{ __('Total') }}</td>
            <td style="text-align:end;">{{ moneyFormat($total) }}</td>
        </tr>
    </table>

    <p style="margin-top:24px;">
        <span class="stamp {{ $paid ? 'paid' : 'unpaid' }}">
            {{ $paid ? __('Paid') : __('Unpaid') }}
        </span>
        @if ($order->payment_method)
            <span class="muted"> — {{ __(ucfirst($order->payment_method)) }}</span>
        @endif
        @if ($transaction_reference)
            <span class="muted"> · {{ __('Transaction') }} {{ $transaction_reference }}</span>
        @endif
    </p>

    <p class="no-print muted" style="margin-top:32px;">
        <button onclick="window.print()">{{ __('Print') }}</button>
    </p>
</body>
</html>
