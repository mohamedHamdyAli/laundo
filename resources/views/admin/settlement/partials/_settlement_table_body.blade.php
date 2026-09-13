@forelse ($settlements as $row)
    @php
        $pending = $row->status === \App\Modules\Payment\Models\OrderSettlement::PENDING;
        $cancelled = $row->status === \App\Modules\Payment\Models\OrderSettlement::CANCELLED;
    @endphp
    <div class="stack-row {{ $cancelled ? 'tone-bad' : '' }}">
        <div>
            <span class="row-lead">
                @if ($row->order)
                    <a href="{{ route('admin.order.show', $row->order->id) }}">{{ $row->order->code }}</a>
                @else
                    —
                @endif
            </span>
            <span class="row-sub">{{ humanDate($row->created_at, 'Y-m-d H:i') }}</span>
        </div>
        @unless ($isTenant)
            {{-- Withheld from a laundry's own view: every row it can see is its
                 own, so a column repeating its name on each line is a column
                 carrying no information. --}}
            <div>
                <span class="row-main">
                    {{ $row->laundry ? getLocalizedValueDashboard($row->laundry, 'name') : __('Unassigned') }}
                </span>
            </div>
        @endunless
        <div>
            {{-- The basis and the rate, not just the result. A laundry disputing
                 its share needs to see the sum, the same way a driver disputing
                 an earning does. --}}
            <span class="row-main">{{ moneyFormat($row->basis) }}</span>
            <span class="row-sub">
                {{ __('at') }} {{ rtrim(rtrim(number_format((float) $row->commission_rate, 2), '0'), '.') }}%
                @if ((float) $row->tax_amount > 0)
                    · {{ __('tax') }} {{ moneyFormat($row->tax_amount) }}
                @endif
            </span>
        </div>
        <div>
            <span class="row-main">{{ moneyFormat($row->commission_amount) }}</span>
            {{-- The charges behind the total. «العمولة ٣١ ج» is a number a
                 laundry can only accept or argue with; naming the three charges
                 is what makes it checkable. --}}
            <span class="row-sub">
                @if ($row->lines->isEmpty())
                    {{ __('Platform') }}
                @else
                    {{ $row->lines->map(fn ($line) => getLocalizedValueDashboard($line, 'name').' '.$line->explain())->implode(' · ') }}
                @endif
            </span>
        </div>
        <div>
            @if ($pending)
                {{-- «معلقة». A settlement that has been pending since before the
                     order completed is one whose payee could not be found — the
                     screen is where that surfaces. --}}
                <span class="status-pill tone-warn">{{ __('Pending') }}</span>
            @elseif ($cancelled)
                <span class="status-pill tone-bad">{{ __('Cancelled') }}</span>
            @else
                <span class="status-pill tone-ok">{{ __('Settled') }}</span>
            @endif
            <span class="row-sub">
                {{ $row->settled_at ? humanDate($row->settled_at, 'Y-m-d H:i') : '—' }}
            </span>
        </div>
        <div class="row-amount">
            {{ moneyFormat($row->laundry_amount) }}
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
