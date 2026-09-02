@forelse ($coupons as $coupon)
    @php
        // A coupon that has expired or been fully claimed is dead weight in the
        // list; the stripe says so without the operator reading the dates.
        $spent = $coupon->hasExpired() || $coupon->isExhausted();
        $rowTone = $spent || $coupon->status !== 'active' ? 'tone-bad' : '';
        $isPercentage = $coupon->type === \App\Modules\Coupon\Models\Coupon::PERCENTAGE;
    @endphp
    <div class="stack-row {{ $rowTone }}">
        <div>
            <span class="row-lead">
                @if (canDo('coupon.view'))
                    <a href="{{ route('admin.coupon.show', $coupon->id) }}">{{ $coupon->code }}</a>
                @else
                    {{ $coupon->code }}
                @endif
            </span>
            <span class="row-sub">{{ getLocalizedValueDashboard($coupon, 'name') ?: '—' }}</span>
        </div>
        <div>
            <span class="row-main">
                @if ($isPercentage)
                    {{ rtrim(rtrim($coupon->value, '0'), '.') }}%
                @else
                    {{ moneyFormat($coupon->value) }}
                @endif
            </span>
            {{-- What the discount does and does not cover, in one line: a
                 percentage with no cap and one capped at 20 are different offers. --}}
            <span class="row-sub">
                @if ($isPercentage && $coupon->max_discount)
                    {{ __('max') }} {{ moneyFormat($coupon->max_discount) }}{{ $coupon->applies_to_delivery ? ' · ' . __('+ delivery') : '' }}
                @elseif ($coupon->applies_to_delivery)
                    {{ __('+ delivery') }}
                @else
                    {{ __('Order total only') }}
                @endif
            </span>
        </div>
        <div>
            <span class="row-main">
                {{ $coupon->redemptions_count }}{{ $coupon->max_redemptions ? ' / ' . $coupon->max_redemptions : '' }}
            </span>
            <span class="row-sub">{{ $coupon->max_per_user }} {{ __('per customer') }}</span>
        </div>
        <div>
            {{-- «Fully claimed» used to hide under the redemption count, where a
                 coupon that can no longer be used looked like one that can. --}}
            @if (! $coupon->hasStarted())
                <span class="status-pill tone-live">{{ __('Not started') }}</span>
                <span class="row-sub">{{ humanDate($coupon->starts_at) }}</span>
            @elseif ($coupon->hasExpired())
                <span class="status-pill tone-bad">{{ __('Expired') }}</span>
                <span class="row-sub">{{ humanDate($coupon->ends_at) }}</span>
            @elseif ($coupon->isExhausted())
                <span class="status-pill tone-bad">{{ __('Fully claimed') }}</span>
            @else
                <span class="status-pill tone-ok">{{ __('Running') }}</span>
                <span class="row-sub">
                    {{ $coupon->ends_at ? __('until') . ' ' . humanDate($coupon->ends_at) : __('No end date') }}
                </span>
            @endif
        </div>
        <div>
            <x-status-toggle-button :id="$coupon->id" :status="$coupon->status"
                endpoint="{{ route('admin.coupon.toggleStatus', $coupon->id) }}" permission="coupon.toggle" />
        </div>
        <div class="stack-actions">
            @include('admin.coupon.shared.controlBut', ['row' => $coupon])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
