@forelse ($payments as $row)
    @php
        $status = $row->status;
        $stuck = $status === \App\Modules\Payment\Enums\PaymentStatus::Pending
            && $row->created_at?->lt(now()->subHour());
        $uncaptured = $status === \App\Modules\Payment\Enums\PaymentStatus::Authorised;
    @endphp
    {{-- Authorised-and-never-captured is money the customer thinks they paid and
         we have not taken, and the authorisation expires. Flagged, not buried. --}}
    <tr class="{{ $stuck || $uncaptured ? 'table-danger' : '' }}">
        <td>
            @if ($row->order)
                <a href="{{ route('admin.order.show', $row->order->id) }}">{{ $row->order->code }}</a>
            @else
                <span class="text-muted">—</span>
            @endif
            <small class="text-muted d-block">{{ $row->provider }}</small>
        </td>
        <td>
            {{ $row->customer?->name ?? '—' }}
            <small class="text-muted d-block">{{ $row->customer?->phone }}</small>
        </td>
        <td><strong>{{ moneyFormat($row->amount) }}</strong></td>
        {{-- The enum's own label, not ucfirst() on the value — `method` is cast to
             PaymentMethod, and the label is already the customer-facing wording. --}}
        <td>{{ __($row->method->label()) }}</td>
        <td>
            @if ($status === \App\Modules\Payment\Enums\PaymentStatus::Captured)
                <span class="badge bg-success">{{ __('Captured') }}</span>
            @elseif ($status === \App\Modules\Payment\Enums\PaymentStatus::Authorised)
                <span class="badge bg-warning">{{ __('Authorised, not captured') }}</span>
            @elseif ($status === \App\Modules\Payment\Enums\PaymentStatus::Pending)
                <span class="badge bg-info">{{ __('Pending') }}</span>
            @elseif ($status === \App\Modules\Payment\Enums\PaymentStatus::Refunded)
                <span class="badge bg-secondary">{{ __('Refunded') }}</span>
            @else
                <span class="badge bg-danger">{{ __('Failed') }}</span>
            @endif

            @if ($row->failure_reason)
                <small class="text-danger d-block">{{ $row->failure_reason }}</small>
            @endif
        </td>
        <td>
            <small class="text-muted">{{ humanDate($row->created_at, 'Y-m-d H:i') }}</small>
            @if ($stuck)
                <small class="text-danger d-block">
                    {{ __('Pending for over an hour') }}
                </small>
            @endif
        </td>
        <td>
            <small class="text-muted" style="word-break: break-all;">
                {{ $row->provider_reference ?? '—' }}
            </small>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="7" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
