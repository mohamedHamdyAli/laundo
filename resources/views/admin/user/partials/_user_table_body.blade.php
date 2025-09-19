@forelse ($users as $user)
    <tr>
        <td>{!! getImageDashboardUrl($user->image_profile) !!}</td>
        <td>{{ $user->id ?? 'None' }}</td>
        <td>{{ $user->name ?? 'None' }}</td>

        <td>{{ $user->phone ?? 'None' }}</td>
        <td>
            <x-status-toggle-button :id="$user->id" :status="$user->status"
                endpoint="{{ route('admin.user.toggleStatus', $user->id) }}" />
        </td>
        <td>{{ $user->created_at->format('Y-m-d H:i') ?? 'None' }}</td>
        <td class="text-center">
            @include('admin.user.shared.controlBut', [
                'row' => $user,
            ])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
