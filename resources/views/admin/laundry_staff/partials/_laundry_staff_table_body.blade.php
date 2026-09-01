@forelse ($staff as $member)
    <tr>
        <td>{!! getImageDashboardUrl($member->image_profile) !!}</td>
        <td>{{ $member->id ?? 'None' }}</td>
        <td>{{ $member->name ?? 'None' }}</td>
        <td>{{ $member->phone ?? 'None' }}</td>
        <td>{{ $member->laundry ? getLocalizedValueDashboard($member->laundry, 'name') : 'None' }}</td>
        <td>{{ $member->role->name ?? 'None' }}</td>
        <td>
            <x-status-toggle-button :id="$member->id" :status="$member->status"
                endpoint="{{ route('admin.laundry_staff.toggleStatus', $member->id) }}"
                permission="laundry_staff.toggle" />
        </td>
        <td class="text-center">
            @include('admin.laundry_staff.shared.controlBut', ['row' => $member])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
