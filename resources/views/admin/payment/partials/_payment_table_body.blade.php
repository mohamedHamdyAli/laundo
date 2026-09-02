@forelse ($payments as $row)
    @php
        $status = $row->status;
        $stuck = $status === \App\Modules\Payment\Enums\PaymentStatus::Pending
            && $row->created_at?->lt(now()->subHour());
        $uncaptured = $status === \App\Modules\Payment\Enums\PaymentStatus::Authorised;
    @endphp
    {{-- Authorised-and-never-captured is money the customer thinks they paid and
         we have not taken, and the authorisation expires. Flagged with the
         stripe, not buried. --}}
    <div class="stack-row {{ $stuck || $uncaptured ? 'tone-bad' : '' }}">
        <div>
            <span class="row-lead">
                @if ($row->order)
                    <a href="{{ route('admin.order.show', $row->order->id) }}">{{ $row->order->code }}</a>
                @else
                    —
                @endif
            </span>
            <span class="row-sub">{{ $row->provider }}</span>
        </div>
        <div>
            <span class="row-main">{{ $row->customer?->name ?? '—' }}</span>
            <span class="row-sub">{{ $row->customer?->phone }}</span>
        </div>
        <div>
            {{-- The enum's own label, not ucfirst() on the value — `method` is cast
                 to PaymentMethod, and the label is already the customer-facing
                 wording. --}}
            <span class="row-main">{{ __($row->method->label()) }}</span>
            <span class="row-sub">{{ $row->provider_reference ?? '—' }}</span>
        </div>
        <div>
            @if ($status === \App\Modules\Payment\Enums\PaymentStatus::Captured)
                <span class="status-pill tone-ok">{{ __('Captured') }}</span>
            @elseif ($status === \App\Modules\Payment\Enums\PaymentStatus::Authorised)
                <span class="status-pill tone-warn">{{ __('Authorised, not captured') }}</span>
            @elseif ($status === \App\Modules\Payment\Enums\PaymentStatus::Pending)
                <span class="status-pill tone-live">{{ __('Pending') }}</span>
            @elseif ($status === \App\Modules\Payment\Enums\PaymentStatus::Refunded)
                <span class="status-pill tone-warn">{{ __('Refunded') }}</span>
            @else
                <span class="status-pill tone-bad">{{ __('Failed') }}</span>
            @endif

            @if ($row->failure_reason)
                <span class="row-sub">{{ $row->failure_reason }}</span>
            @elseif ($stuck)
                <span class="row-sub">{{ __('Pending for over an hour') }}</span>
            @endif
        </div>
        <div class="row-amount">
            {{ moneyFormat($row->amount) }}
            <span class="row-sub">{{ humanDate($row->created_at, 'Y-m-d H:i') }}</span>
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
