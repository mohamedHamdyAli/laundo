@forelse ($moderators as $moderator)
    <tr>
        <td>{!! getImageDashboardUrl($moderator->image_profile) !!}</td>
        <td>{{ $moderator->id ?? 'None' }}</td>
        <td>{{ $moderator->name ?? 'None' }}</td>
        <td>{{ $moderator->phone ?? 'None' }}</td>
        <td>{{ $moderator->role->name ?? 'None' }}</td>
        <td>
            <x-status-toggle-button :id="$moderator->id" :status="$moderator->status"
                endpoint="{{ route('admin.moderator.toggleStatus', $moderator->id) }}" permission="moderator.toggle" />
        </td>
        <td>{{ humanDate($moderator->created_at, 'Y-m-d H:i') }}</td>
        <td class="text-center">
            @include('admin.moderator.shared.controlBut', [
                'row' => $moderator,
            ])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
