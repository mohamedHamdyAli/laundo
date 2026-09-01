@forelse ($laundries as $laundry)
    <tr>
        <td>{!! getImageDashboardUrl($laundry->logo) !!}</td>
        <td>{{ $laundry->id ?? 'None' }}</td>
        <td>{{ getLocalizedValueDashboard($laundry, 'name') }}</td>
        <td>{{ $laundry->phone ?? 'None' }}</td>
        <td>{{ $laundry->city ? getLocalizedValueDashboard($laundry->city, 'name') : 'None' }}</td>
        <td>
            <x-status-toggle-button :id="$laundry->id" :status="$laundry->status"
                endpoint="{{ route('admin.laundry.toggleStatus', $laundry->id) }}" permission="laundry.toggle" />
        </td>
        <td>{{ humanDate($laundry->created_at, 'Y-m-d H:i') }}</td>
        <td class="text-center">
            @include('admin.laundry.shared.controlBut', ['row' => $laundry])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
