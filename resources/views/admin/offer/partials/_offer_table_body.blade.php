@forelse ($offers as $offer)
    @php
        // The stripe carries the one thing on this list somebody has to act on:
        // an offer that is switched on and still going nowhere, because it is
        // outside its own window or its coupon has expired or run out. It is
        // occupying a slot on the home screen and showing no discount.
        $badge = $offer->badge();
        $window = $offer->status === 'active'
            && (($offer->starts_at && $offer->starts_at->isFuture())
                || ($offer->ends_at && $offer->ends_at->isPast()));
        $deadBadge = $offer->coupon_id && $badge === null;
        $rowTone = $offer->status !== 'active' ? 'tone-bad' : (($window || $deadBadge) ? 'tone-warn' : '');
    @endphp
    <div class="stack-row {{ $rowTone }}">
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($offer->image) !!}</span>
        </div>
        <div>
            <span class="row-lead">
                @if (canDo('offer.view'))
                    <a href="{{ route('admin.offer.show', $offer->id) }}">{{ getLocalizedValueDashboard($offer, 'title') ?: '—' }}</a>
                @else
                    {{ getLocalizedValueDashboard($offer, 'title') ?: '—' }}
                @endif
            </span>
            <span class="row-sub">{{ \Illuminate\Support\Str::limit(getLocalizedValueDashboard($offer, 'description') ?: '—', 70) }}</span>
        </div>
        <div>
            {{-- The badge exactly as the app would draw it, so an operator sees
                 what the customer sees rather than the coupon it came from. --}}
            <span class="row-main">{{ $badge ?? '—' }}</span>
            @if ($deadBadge)
                <span class="row-sub">{{ __('Coupon not usable') }}</span>
            @elseif ($offer->coupon)
                <span class="row-sub">{{ $offer->coupon->code }}</span>
            @else
                <span class="row-sub">{{ __('No coupon') }}</span>
            @endif
        </div>
        <div>
            @if ($offer->starts_at || $offer->ends_at)
                <span class="row-main">
                    {{ $offer->starts_at ? humanDate($offer->starts_at, 'Y-m-d') : __('Now') }}
                    →
                    {{ $offer->ends_at ? humanDate($offer->ends_at, 'Y-m-d') : __('Open') }}
                </span>
                <span class="row-sub">
                    @if ($offer->starts_at && $offer->starts_at->isFuture())
                        {{ __('Not started yet') }}
                    @elseif ($offer->ends_at && $offer->ends_at->isPast())
                        {{ __('Ended') }}
                    @else
                        {{ __('Running') }}
                    @endif
                </span>
            @else
                <span class="row-main">{{ __('Always') }}</span>
                <span class="row-sub">{{ __('No window set') }}</span>
            @endif
        </div>
        <div>
            <x-status-toggle-button :id="$offer->id" :status="$offer->status"
                endpoint="{{ route('admin.offer.toggleStatus', $offer->id) }}" permission="offer.toggle" />
        </div>
        <div class="stack-actions">
            @include('admin.offer.shared.controlBut', ['row' => $offer])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
