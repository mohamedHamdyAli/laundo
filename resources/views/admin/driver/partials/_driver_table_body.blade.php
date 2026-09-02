@forelse ($drivers as $driver)
    @php
        $profile = $driver->profile;
        $docsExpired = $profile && $profile->hasExpiredDocuments();
        // An expired document is the one thing on this list somebody has to act
        // on, so it takes the stripe ahead of a merely inactive account.
        $rowTone = $docsExpired || $driver->status !== 'active' ? 'tone-bad' : '';
    @endphp
    <div class="stack-row {{ $rowTone }}">
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($driver->image_profile) !!}</span>
        </div>
        <div>
            <span class="row-lead">
                @if (canDo('driver.view'))
                    <a href="{{ route('admin.driver.show', $driver->id) }}">{{ $driver->name }}</a>
                @else
                    {{ $driver->name }}
                @endif
            </span>
            @if ($docsExpired)
                {{-- Surfaced, not enforced: by decision an expired document does
                     not stop assignment, a person decides. --}}
                <span class="status-pill tone-bad" title="{{ __('One or more documents have expired') }}">
                    {{ __('Documents expired') }}
                </span>
            @else
                <span class="row-sub">{{ $driver->phone ?? '-' }}</span>
            @endif
        </div>
        <div>
            <span class="row-main">{{ $profile?->vehicle_type ?? '-' }}</span>
            <span class="row-sub">{{ $profile?->plate_number ?: $profile?->shiftLabel() ?: '-' }}</span>
        </div>
        <div>
            @if ($driver->zones->isEmpty())
                <span class="row-sub">{{ __('No areas') }}</span>
            @else
                <span class="row-main">{{ $driver->zones->count() }} {{ __('areas') }}</span>
                <span class="row-sub">
                    {{ $driver->zones->take(2)->map(fn ($z) => getLocalizedValueDashboard($z, 'name'))->implode(', ') }}{{ $driver->zones->count() > 2 ? ' …' : '' }}
                </span>
            @endif
        </div>
        <div>
            {{-- Availability is the driver's own switch; status is the platform's.
                 They answer different questions and both belong on the row. --}}
            @if ($profile?->is_available)
                <span class="status-pill tone-ok">{{ __('Available') }}</span>
            @else
                <span class="status-pill tone-warn">{{ __('Unavailable') }}</span>
            @endif
        </div>
        <div>
            <x-status-toggle-button :id="$driver->id" :status="$driver->status"
                endpoint="{{ route('admin.driver.toggleStatus', $driver->id) }}" permission="driver.toggle" />
        </div>
        <div class="stack-actions">
            @include('admin.driver.shared.controlBut', ['row' => $driver])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
