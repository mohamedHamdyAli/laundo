@forelse ($orders as $order)
    <tr>
        <td><strong>#{{ $order->code }}</strong></td>
        <td>
            {{ $order->customer?->name ?? '-' }}
            <small class="text-muted d-block">{{ $order->customer?->phone }}</small>
        </td>
        <td>{{ $order->service ? getLocalizedValueDashboard($order->service, 'name') : '-' }}</td>
        <td>
            @if ($order->laundry)
                {{ getLocalizedValueDashboard($order->laundry, 'name') }}
            @else
                {{-- Accepted unassigned by decision: nothing covered the zone, and
                     an operator places it rather than the customer being refused. --}}
                <span class="badge bg-warning text-dark">{{ __('Unassigned') }}</span>
            @endif
        </td>
        <td>
            <span class="badge {{ $order->status->isTerminal() ? 'bg-secondary' : 'bg-info' }}">
                {{ __($order->status->label()) }}
            </span>
        </td>
        <td>
            {{ moneyFormat($order->payableTotal()) }}
            @if ($order->final_total !== null)
                <small class="text-muted d-block">{{ __('Final') }}</small>
            @endif
        </td>
        <td>{{ $order->pickup_date ? humanDate($order->pickup_date, 'Y-m-d') : '-' }}</td>
        <td>{{ humanDate($order->created_at) }}</td>
        <td class="text-center">
            @if (canDo('order.view'))
                <a href="{{ route('admin.order.show', $order->id) }}" class="btn btn-sm btn-outline-primary">
                    <i class="fa fa-eye"></i>
                </a>
            @endif
        </td>
    </tr>
@empty
    <tr>
        <td colspan="9" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
