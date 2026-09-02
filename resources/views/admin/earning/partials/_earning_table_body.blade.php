@forelse ($earnings as $row)
    @php
        $held = $row->status === \App\Modules\Payment\Models\DriverEarning::PENDING;
        $cancelled = $row->status === \App\Modules\Payment\Models\DriverEarning::CANCELLED;
    @endphp
    <div class="stack-row {{ $cancelled ? 'tone-bad' : '' }}">
        <div>
            <span class="row-lead">{{ $row->payee->name }}</span>
            <span class="row-sub">{{ $row->payee->phone }}</span>
        </div>
        <div>
            <span class="row-main">
                @if ($row->order)
                    <a href="{{ route('admin.order.show', $row->order->id) }}">{{ $row->order->code }}</a>
                @else
                    —
                @endif
            </span>
            <span class="row-sub">{{ $row->task ? __($row->task->type->label()) : '—' }}</span>
        </div>
        <div>
            {{-- How the figure was reached. A driver disputing their pay needs the
                 basis and the rate, not just the result. --}}
            <span class="row-sub">{{ $row->explain() }}</span>
        </div>
        <div>
            @if ($held)
                <span class="status-pill tone-warn">{{ __('Held') }}</span>
            @elseif ($cancelled)
                <span class="status-pill tone-bad">{{ __('Cancelled') }}</span>
            @else
                <span class="status-pill tone-ok">{{ __('Released') }}</span>
            @endif
            <span class="row-sub">
                {{ $row->released_at ? humanDate($row->released_at, 'Y-m-d H:i') : humanDate($row->created_at, 'Y-m-d H:i') }}
            </span>
        </div>
        <div class="row-amount">
            {{ moneyFormat($row->amount) }}
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
