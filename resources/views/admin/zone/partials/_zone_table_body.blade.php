@forelse ($zones as $zone)
    <div class="stack-row">
        <div>
            <span class="row-lead">
                @if (canDo('zone.view'))
                    <a href="{{ route('admin.zone.show', $zone->id) }}">{{ getLocalizedValueDashboard($zone, 'name') }}</a>
                @else
                    {{ getLocalizedValueDashboard($zone, 'name') }}
                @endif
            </span>
            <span class="row-sub">#{{ $zone->id }}</span>
        </div>
        <div>
            <span class="row-main">{{ $zone->city ? getLocalizedValueDashboard($zone->city, 'name') : '-' }}</span>
        </div>
        <div>
            {{-- Sort order is a number the operator sets, so it is stated as
                 one rather than left to be inferred from row position. --}}
            <span class="row-main">{{ $zone->sort_order }}</span>
        </div>
        <div>
            <x-status-toggle-button :id="$zone->id" :status="$zone->status"
                endpoint="{{ route('admin.zone.toggleStatus', $zone->id) }}" permission="zone.toggle" />
        </div>
        <div class="stack-actions">
            @include('admin.zone.shared.controlBut', ['row' => $zone])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
