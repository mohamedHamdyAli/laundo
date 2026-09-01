@forelse ($users as $user)
    <tr>
        <td>{!! getImageDashboardUrl($user->image_profile) !!}</td>
        <td>{{ $user->id ?? 'None' }}</td>
        <td>{{ $user->name ?? 'None' }}</td>
        {{-- «مرجع العميل» — the number printed on this customer's bags. It is how
             the laundry matches a parcel whose label is torn, so it is worth more
             on this screen than the raw row id beside it. --}}
        <td>
            @if ($user->customer_reference)
                <span class="badge bg-light text-dark">{{ $user->customer_reference }}</span>
            @else
                <span class="text-muted">—</span>
            @endif
        </td>

        <td>{{ $user->phone ?? 'None' }}</td>
        <td>
            <x-status-toggle-button :id="$user->id" :status="$user->status"
                endpoint="{{ route('admin.user.toggleStatus', $user->id) }}" permission="user.toggle" />
        </td>
        <td>{{ humanDate($user->created_at, 'Y-m-d H:i') }}</td>
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
