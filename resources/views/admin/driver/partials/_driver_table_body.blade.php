@forelse ($drivers as $driver)
    @php $profile = $driver->profile; @endphp
    <tr>
        <td>{!! getImageDashboardUrl($driver->image_profile) !!}</td>
        <td>{{ $driver->id }}</td>
        <td>
            {{ $driver->name }}
            @if ($profile && $profile->hasExpiredDocuments())
                {{-- Surfaced, not enforced: by decision an expired document does
                     not stop assignment, a person decides. --}}
                <span class="badge bg-danger d-block mt-1" title="{{ __('One or more documents have expired') }}">
                    <i class="fa fa-exclamation-triangle"></i> {{ __('Documents expired') }}
                </span>
            @endif
        </td>
        <td>{{ $driver->phone ?? '-' }}</td>
        <td>{{ $profile?->vehicle_type ?? '-' }}<br><small class="text-muted">{{ $profile?->plate_number }}</small></td>
        <td>{{ $profile?->shiftLabel() ?? '-' }}</td>
        <td>
            @if ($driver->zones->isEmpty())
                <span class="text-muted">{{ __('No areas') }}</span>
            @else
                <span class="badge bg-secondary">{{ $driver->zones->count() }}</span>
                <small class="text-muted d-block">
                    {{ $driver->zones->take(2)->map(fn ($z) => getLocalizedValueDashboard($z, 'name'))->implode(', ') }}
                    {{ $driver->zones->count() > 2 ? '…' : '' }}
                </small>
            @endif
        </td>
        <td>
            @if ($profile?->is_available)
                <span class="badge bg-success">{{ __('Available') }}</span>
            @else
                <span class="badge bg-secondary">{{ __('Unavailable') }}</span>
            @endif
        </td>
        <td>
            <x-status-toggle-button :id="$driver->id" :status="$driver->status"
                endpoint="{{ route('admin.driver.toggleStatus', $driver->id) }}" permission="driver.toggle" />
        </td>
        <td class="text-center">
            @include('admin.driver.shared.controlBut', ['row' => $driver])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="10" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
