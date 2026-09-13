@php
    // The gate keys the service records, in the operator's words. Kept here
    // rather than on the model so the wording is translatable in one place.
    $gateLabels = [
        'on_time' => __('Missed the on-time rate'),
        'rating' => __('Below the rating'),
        'failed_tasks' => __('Too many failed journeys'),
    ];
@endphp

@forelse ($awards as $award)
    @php
        $due = $award->isDue();
        $gated = $award->wasGated();
    @endphp
    <div class="stack-row {{ $award->status === \App\Modules\Driver\Models\DriverBonusAward::REJECTED ? 'tone-bad' : '' }}">
        <div>
            <span class="row-lead">{{ $award->driver?->name ?? '—' }}</span>
            <span class="row-sub">{{ $award->driver?->phone ?? '—' }}</span>
        </div>
        <div>
            <span class="row-main">{{ $award->orders_count }}</span>
            <span class="row-sub">
                {{ __('orders') }}
                @if ($award->tier_min_orders !== null)
                    · {{ __('target') }} {{ $award->tier_min_orders }}
                @endif
            </span>
        </div>
        <div>
            {{-- The three measurements the decision was made from. A driver
                 asking why September paid and October did not has to be shown
                 these, not told the answer. --}}
            <span class="row-main">
                {{ $award->on_time_rate === null ? '—' : rtrim(rtrim(number_format((float) $award->on_time_rate, 2), '0'), '.').'%' }}
            </span>
            <span class="row-sub">
                {{ __('rating') }}
                {{ $award->avg_delivery_rating === null ? '—' : rtrim(rtrim(number_format((float) $award->avg_delivery_rating, 2), '0'), '.') }}
                · {{ $award->failed_tasks }} {{ __('failed') }}
            </span>
        </div>
        <div>
            @if ($gated)
                @foreach ($award->gate_failures as $key)
                    <span class="status-pill tone-warn">{{ $gateLabels[$key] ?? $key }}</span>
                @endforeach
            @elseif ($award->status === \App\Modules\Driver\Models\DriverBonusAward::APPROVED)
                <span class="status-pill tone-ok">{{ __('Approved') }}</span>
                <span class="row-sub">{{ $award->approved_at ? humanDate($award->approved_at, 'Y-m-d') : '' }}</span>
            @elseif ($award->status === \App\Modules\Driver\Models\DriverBonusAward::REJECTED)
                <span class="status-pill tone-bad">{{ __('Declined') }}</span>
                @if ($award->note)
                    <span class="row-sub">{{ $award->note }}</span>
                @endif
            @elseif ((float) $award->amount <= 0)
                <span class="status-pill tone-neutral">{{ __('No target reached') }}</span>
            @else
                <span class="status-pill tone-warn">{{ __('Waiting for you') }}</span>
            @endif
        </div>
        <div class="row-amount">
            {{ moneyFormat($award->amount) }}
            <span class="row-sub">{{ $award->rule ? getLocalizedValueDashboard($award->rule, 'name') : '—' }}</span>
        </div>
        <div class="stack-actions">
            @if ($due && (float) $award->amount > 0 && canDo('driver_bonus_award.update'))
                {{-- Deliberately without `needs-validation`: there is nothing
                     typed here to lose, and a background submit would only hide
                     the page it leads to. --}}
                <form method="POST" action="{{ route('admin.driver_bonus.approve', $award->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm action-btn action-view" title="{{ __('Approve') }}"
                        aria-label="{{ __('Approve') }}"
                        onclick="return confirm('{{ __('Add :amount to this driver wallet?', ['amount' => moneyFormat($award->amount)]) }}')">
                        <i class="fa fa-check"></i>
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.driver_bonus.reject', $award->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm action-btn action-delete" title="{{ __('Decline') }}"
                        aria-label="{{ __('Decline') }}"
                        onclick="return confirm('{{ __('Decline this month for this driver?') }}')">
                        <i class="fa fa-times"></i>
                    </button>
                </form>
            @endif
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
