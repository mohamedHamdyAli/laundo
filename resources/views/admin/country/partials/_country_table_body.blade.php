@forelse ($countries as $country)
    <tr>
        <td>{{ $country->id ?? 'None' }}</td>
        <td>{{ getLocalizedValueDashboard($country, 'name') ?? '-' }}</td>
        <td>{{ $country->code ?? 'None' }}</td>
        <td>{{ $country->phone_code ?? 'None' }}</td>
        <td>
            <x-status-toggle-button :id="$country->id" :status="$country->status"
                endpoint="{{ route('admin.country.toggleStatus', $country->id) }}" permission="country.toggle" />
        </td>
        <td>{{ humanDate($country->created_at, 'Y-m-d H:i') }}</td>
        <td class="text-center">
            @include('admin.country.shared.controlBut', [
                'row' => $country,
            ])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
