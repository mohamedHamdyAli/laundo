@forelse ($coupons as $coupon)
    <tr>
        <td><code class="fs-6">{{ $coupon->code }}</code></td>
        <td>{{ getLocalizedValueDashboard($coupon, 'name') ?: '—' }}</td>
        <td>
            @if ($coupon->type === \App\Modules\Coupon\Models\Coupon::PERCENTAGE)
                {{ rtrim(rtrim($coupon->value, '0'), '.') }}%
                @if ($coupon->max_discount)
                    <small class="text-muted d-block">
                        {{ __('max') }} {{ moneyFormat($coupon->max_discount) }}
                    </small>
                @endif
            @else
                {{ moneyFormat($coupon->value) }}
            @endif
            @if ($coupon->applies_to_delivery)
                <span class="badge bg-light text-dark">{{ __('+ delivery') }}</span>
            @endif
        </td>
        <td>
            {{ $coupon->redemptions_count ?? $coupon->redemptions_count }}
            @if ($coupon->max_redemptions)
                / {{ $coupon->max_redemptions }}
                @if ($coupon->isExhausted())
                    <span class="badge bg-secondary d-block mt-1">{{ __('Fully claimed') }}</span>
                @endif
            @endif
            <small class="text-muted d-block">
                {{ $coupon->max_per_user }} {{ __('per customer') }}
            </small>
        </td>
        <td>
            @if (! $coupon->hasStarted())
                <span class="badge bg-info">{{ __('Not started') }}</span>
                <small class="text-muted d-block">{{ humanDate($coupon->starts_at) }}</small>
            @elseif ($coupon->hasExpired())
                <span class="badge bg-secondary">{{ __('Expired') }}</span>
                <small class="text-muted d-block">{{ humanDate($coupon->ends_at) }}</small>
            @elseif ($coupon->ends_at)
                <small class="text-muted">{{ __('until') }} {{ humanDate($coupon->ends_at) }}</small>
            @else
                <span class="text-muted">{{ __('No end date') }}</span>
            @endif
        </td>
        <td>
            <x-status-toggle-button :id="$coupon->id" :status="$coupon->status"
                endpoint="{{ route('admin.coupon.toggleStatus', $coupon->id) }}" permission="coupon.toggle" />
        </td>
        <td class="text-center">
            @include('admin.coupon.shared.controlBut', ['row' => $coupon])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="7" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
