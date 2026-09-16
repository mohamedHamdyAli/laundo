@forelse ($rows as $row)
    {{-- Struck red when a laundry has been deducted more than has reached it.
         A real state, not an error: it is the one row on the page somebody has
         to do something about. --}}
    <div class="stack-row {{ $row['net_payable'] < 0 ? 'tone-bad' : '' }}">
        <div>
            <span class="row-lead">{{ $row['name'] }}</span>
            <span class="row-sub">
                {{ $row['email'] }}
                @if ($row['areas'] > 0)
                    · {{ trans_choice(':count area|:count areas', $row['areas'], ['count' => $row['areas']]) }}
                @endif
            </span>
        </div>
        <div>
            <span class="row-main">{{ $row['orders'] }}</span>
            {{-- The three outcomes under the total, because «13 orders» says
                 nothing about whether the laundry earned from them. They add
                 back to the total by construction. --}}
            <span class="row-sub">
                {{ $row['completed'] }} {{ __('done') }}
                · {{ $row['in_progress'] }} {{ __('running') }}
                · {{ $row['cancelled'] }} {{ __('lost') }}
            </span>
        </div>
        <div>
            <span class="row-main">{{ moneyFormat($row['user_paid']) }}</span>
            <span class="row-sub">
                @if ($row['tax'] > 0)
                    {{ __('tax') }} {{ moneyFormat($row['tax']) }}
                @else
                    {{ __('paid orders only') }}
                @endif
            </span>
        </div>
        <div>
            <span class="row-main">{{ moneyFormat($row['commission']) }}</span>
            {{-- The blended rate the window came to, derived from the result:
                 stacking charges have no single rate to quote. --}}
            <span class="row-sub">
                {{ __('at') }} {{ rtrim(rtrim(number_format($row['commission_rate'], 2), '0'), '.') }}%
            </span>
        </div>
        <div>
            <span class="row-main">{{ moneyFormat($row['entitled']) }}</span>
            {{-- «مستحق» against «محصّل». A settlement is recorded when the price
                 is agreed and only settles when the order completes, so the gap
                 between these two is the figure worth looking at. --}}
            <span class="row-sub">
                {{ __('received') }} {{ moneyFormat($row['received']) }}
            </span>
        </div>
        <div>
            <span class="row-main {{ $row['deducted'] > 0 ? 'text-danger' : '' }}">
                {{ $row['deducted'] > 0 ? '−'.moneyFormat($row['deducted']) : '—' }}
            </span>
            {{-- The newest reason, in full on hover. A deduction without its
                 reason on the same line is a number a laundry can only argue
                 with — the same rule the settlement lines follow. --}}
            <span class="row-sub" title="{{ $row['reasons']->pluck('reason')->implode(' · ') }}">
                @if ($row['reasons']->isNotEmpty())
                    {{ \Illuminate\Support\Str::limit($row['reasons']->first()->reason, 40) }}
                    @if ($row['reasons']->count() > 1)
                        · +{{ $row['reasons']->count() - 1 }}
                    @endif
                @else
                    —
                @endif
            </span>
        </div>
        <div class="row-amount">
            {{ moneyFormat($row['net_payable']) }}
        </div>
        <div class="stack-actions">
            @if (canDo('setting.update'))
                <button type="button" class="btn btn-sm action-btn action-edit js-deduction-btn"
                    title="{{ __('Add deduction') }}" aria-label="{{ __('Add deduction') }}"
                    data-action="{{ route('admin.laundry_revenue.deduct', $row['id']) }}"
                    data-name="{{ $row['name'] }}">
                    {{-- `fa-cut`, not `fa-scissors`: the panel ships Font Awesome
                         5, where the scissors glyph is named `cut` and the other
                         spelling renders an empty box. --}}
                    <i class="fa fa-cut"></i>
                </button>

                {{-- Shown only when there is something to withdraw, and counted
                     off every standing deduction rather than the window on
                     screen: a claim is standing whatever dates are in view. --}}
                @if ($row['standing_deductions'] > 0)
                    <form method="POST" action="{{ route('admin.laundry_revenue.reverse', $row['id']) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm action-btn action-delete"
                            title="{{ __('Withdraw deductions') }}" aria-label="{{ __('Withdraw deductions') }}"
                            onclick="return confirm('{{ trans_choice('Withdraw the deduction standing against this laundry?|Withdraw all :count deductions standing against this laundry?', $row['standing_deductions'], ['count' => $row['standing_deductions']]) }}')">
                            <i class="fa fa-undo"></i>
                        </button>
                    </form>
                @endif
            @endif
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
