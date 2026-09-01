@forelse ($zones as $zone)
    <tr>
        <td>{{ $zone->id }}</td>
        <td>{{ getLocalizedValueDashboard($zone, 'name') }}</td>
        <td>{{ $zone->city ? getLocalizedValueDashboard($zone->city, 'name') : '-' }}</td>
        <td>{{ $zone->sort_order }}</td>
        <td>
            <x-status-toggle-button :id="$zone->id" :status="$zone->status"
                endpoint="{{ route('admin.zone.toggleStatus', $zone->id) }}" permission="zone.toggle" />
        </td>
        <td class="text-center">
            @include('admin.zone.shared.controlBut', ['row' => $zone])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="6" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
