@forelse ($cities as $city)
    <tr>
        <td>{{ $city->id ?? 'None' }}</td>
        <td>{{ getLocalizedValueDashboard($city, 'name') ?? '-' }}</td>
        <td>{{ getLocalizedValueDashboard($city->country, 'name') ?? '-' }}</td>
        <td>
            <x-status-toggle-button :id="$city->id" :status="$city->status"
                endpoint="{{ route('admin.city.toggleStatus', $city->id) }}" permission="city.toggle" />
        </td>
        <td>{{ humanDate($city->created_at, 'Y-m-d H:i') }}</td>
        <td class="text-center">
            @include('admin.city.shared.controlBut', [
                'row' => $city,
            ])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
