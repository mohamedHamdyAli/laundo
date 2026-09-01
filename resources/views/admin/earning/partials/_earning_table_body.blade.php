@forelse ($earnings as $row)
    <tr>
        <td>
            {{ $row->payee->name }}
            <small class="text-muted d-block">{{ $row->payee->phone }}</small>
        </td>
        <td>
            @if ($row->order)
                <a href="{{ route('admin.order.show', $row->order->id) }}">{{ $row->order->code }}</a>
            @else
                <span class="text-muted">—</span>
            @endif
        </td>
        <td>
            @if ($row->task)
                <small>{{ __($row->task->type->label()) }}</small>
            @else
                <span class="text-muted">—</span>
            @endif
        </td>
        <td><strong>{{ moneyFormat($row->amount) }}</strong></td>
        <td>
            {{-- How the figure was reached. A driver disputing their pay needs the
                 basis and the rate, not just the result. --}}
            <small class="text-muted">{{ $row->explain() }}</small>
        </td>
        <td>
            @if ($row->status === \App\Modules\Payment\Models\DriverEarning::PENDING)
                <span class="badge bg-warning">{{ __('Held') }}</span>
            @elseif ($row->status === \App\Modules\Payment\Models\DriverEarning::RELEASED)
                <span class="badge bg-success">{{ __('Released') }}</span>
            @else
                <span class="badge bg-secondary">{{ __('Cancelled') }}</span>
            @endif
        </td>
        <td>
            <small class="text-muted">
                {{ $row->released_at ? humanDate($row->released_at, 'Y-m-d H:i') : humanDate($row->created_at, 'Y-m-d H:i') }}
            </small>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="7" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
