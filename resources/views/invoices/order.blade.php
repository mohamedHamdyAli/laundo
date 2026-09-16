{{--
    The invoice.

    Deliberately self-contained: inline styles, no layout, no assets. It is
    printed and it is emailed, and both of those strip anything that has to be
    fetched.

    It was a price list with a name on top — the order's own screen knew the
    service, the dates, the windows, the addresses and how the bag changed hands,
    and `InvoiceRenderer` already eager-loaded three of those and rendered none.
    A document that charges tax and cannot say what was done or when is not one
    anybody can file.

    Two things it still deliberately leaves out:

    - **The settlement.** Commission and the laundry's share are the platform's
      arrangement with the shop. Printing them on the customer's copy publishes
      the margin to the person paying it.
    - **Driver notes, address notes, the transport legs.** Delivery logistics,
      not billing. «Special instructions» is the exception, because that is what
      the customer asked for and therefore part of what they are paying for.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ getDefaultLanguage('is_rtl') === 'true' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $number }}</title>
    <style>
        body { font-family: "Segoe UI", Tahoma, Arial, sans-serif; color: #222; margin: 0; padding: 32px; font-size: 14px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; border-bottom: 2px solid #222; padding-bottom: 16px; }
        .issuer h2 { margin: 0 0 4px; font-size: 20px; }
        .muted { color: #666; font-size: 12px; }
        .meta { text-align: end; }
        .meta .doc { font-size: 18px; font-weight: bold; }

        /* Three panels that must not wrap into a column on paper. */
        .facts { display: flex; gap: 16px; margin-top: 20px; }
        .fact { flex: 1; border: 1px solid #e6e6e6; border-radius: 6px; padding: 10px 12px; }
        .fact h4 { margin: 0 0 6px; font-size: 11px; letter-spacing: .04em; text-transform: uppercase; color: #777; font-weight: 600; }
        .fact div { font-size: 13px; line-height: 1.5; }

        table.items { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table.items th, table.items td { padding: 8px 10px; text-align: start; border-bottom: 1px solid #eee; }
        table.items th { background: #f6f6f6; font-size: 12px; }

        .totals { margin-top: 16px; width: 320px; margin-inline-start: auto; border-collapse: collapse; }
        .totals td { border: none; padding: 4px 10px; }
        .totals .sep td { border-top: 1px solid #ddd; }
        .totals .grand td { border-top: 2px solid #222; font-weight: bold; font-size: 16px; }
        .credit { color: #157347; }

        .stamp { display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 13px; }
        .paid { background: #e6f6ea; color: #157347; }
        .unpaid { background: #fdeaea; color: #b02a37; }
        .estimate { background: #fff6e0; color: #8a6100; }

        @media print { body { padding: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="head">
        {{-- Every line here is `realSetting()`-guarded in the renderer. On an
             install whose settings are still the seeded ones, this is a name and
             nothing else — which is correct. Printing «BaseCode» and
             `nahrPhpTeam@…` on a customer's invoice is worse than printing
             neither. --}}
        <div class="issuer">
            <h2>{{ $issuer['name'] }}</h2>
            @if ($issuer['address'])
                <div class="muted">{{ $issuer['address'] }}</div>
            @endif
            @if ($issuer['phone'] || $issuer['email'])
                <div class="muted">{{ collect([$issuer['phone'], $issuer['email']])->filter()->implode(' · ') }}</div>
            @endif
            @if ($issuer['tax_number'])
                <div class="muted">{{ __('Tax registration number') }}: {{ $issuer['tax_number'] }}</div>
            @endif
        </div>

        <div class="meta">
            <div class="doc">{{ __('Invoice') }} {{ $number }}</div>
            {{-- An explicit format, not the relative default. `humanDate()`
                 with no format renders «2 weeks ago», which is the right thing
                 on an operator's screen and useless on a document somebody has
                 to file against a date. --}}
            <div class="muted">{{ humanDate($issued_at, 'Y-m-d') }}</div>
            <div class="muted">{{ __('Order') }} #{{ $order->code }} — {{ $status_label }}</div>
        </div>
    </div>

    <div class="facts">
        <div class="fact">
            <h4>{{ __('Billed to') }}</h4>
            <div>
                {{ $order->customer?->name ?? '—' }}
                @if ($order->customer?->phone)
                    <div class="muted">{{ $order->customer->phone }}</div>
                @endif
                @if ($delivery['address'])
                    <div class="muted">{{ $delivery['address'] }}</div>
                @endif
            </div>
        </div>

        {{-- The shop that did the work, and the one the customer will ring about
             it. Its own row carries a real phone and address even where the
             platform's settings do not. --}}
        <div class="fact">
            <h4>{{ __('Fulfilled by') }}</h4>
            <div>
                @if ($laundry)
                    {{ getLocalizedValueDashboard($laundry, 'name') }}
                    @if ($laundry->phone)
                        <div class="muted">{{ $laundry->phone }}</div>
                    @endif
                    @if ($laundry->address)
                        <div class="muted">{{ $laundry->address }}</div>
                    @endif
                @else
                    <span class="muted">{{ __('Not assigned yet') }}</span>
                @endif
            </div>
        </div>

        <div class="fact">
            <h4>{{ __('Service') }}</h4>
            <div>
                {{ $service ? getLocalizedValueDashboard($service, 'name') : '—' }}
            </div>
        </div>
    </div>

    {{-- When it was done. An invoice without a service date is one an accountant
         has to go and ask about. --}}
    <div class="facts">
        @foreach ([['label' => __('Collection'), 'leg' => $collection], ['label' => __('Delivery'), 'leg' => $delivery]] as $step)
            <div class="fact">
                <h4>{{ $step['label'] }}</h4>
                <div>
                    {{ $step['leg']['date'] ?? '—' }}
                    @if ($step['leg']['window'])
                        <div class="muted">{{ $step['leg']['window'] }}</div>
                    @endif
                    @if ($step['leg']['address'])
                        <div class="muted">{{ $step['leg']['address'] }}</div>
                    @endif
                    @if ($step['leg']['method'])
                        <div class="muted">{{ $step['leg']['method'] }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    @unless ($is_final)
        {{-- Said plainly, because an estimate that looks like a bill is how a
             dispute starts. --}}
        <p class="stamp estimate" style="margin-top:16px;">
            {{ __('Estimated — the final price is set after the pieces are reviewed.') }}
        </p>
    @endunless

    <table class="items">
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

    {{-- `Order::moneyRows()` — the same rows the order screen's pricing card
         renders. They used to be assembled separately in each file and said
         different things about one sum. --}}
    <table class="totals">
        @foreach ($money_rows as $line)
            <tr @class(['grand' => $line['kind'] === 'total', 'sep' => $line['kind'] === 'subtotal'])>
                <td class="{{ $line['kind'] === 'credit' ? 'credit' : '' }}">
                    {{ $line['label'] }}@if ($line['note']) ({{ $line['note'] }})@endif
                </td>
                <td style="text-align:end;" class="{{ $line['kind'] === 'credit' ? 'credit' : '' }}">
                    {{ moneyFormat($line['amount']) }}
                </td>
            </tr>
        @endforeach
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

    @if (filled($order->special_instructions))
        {{-- What the customer asked to be done, which is part of what they are
             paying for. The driver's note and the note on the address are not,
             and stay on the operator's screen. --}}
        <p class="muted" style="margin-top:16px;">
            <strong>{{ __('Special instructions') }}:</strong> {{ $order->special_instructions }}
        </p>
    @endif

    <p class="no-print muted" style="margin-top:32px;">
        <button onclick="window.print()">{{ __('Print') }}</button>
    </p>
</body>
</html>
