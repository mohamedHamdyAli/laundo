@forelse ($items as $item)
    <tr>
        <td>{!! getImageDashboardUrl($item->image) !!}</td>
        <td>{{ $item->id }}</td>
        <td>{{ getLocalizedValueDashboard($item, 'name') }}</td>
        <td>{{ $item->category ? getLocalizedValueDashboard($item->category, 'name') : '—' }}</td>
        <td>{{ $item->sort_order }}</td>
        <td>
            <x-status-toggle-button :id="$item->id" :status="$item->status"
                endpoint="{{ route('admin.item.toggleStatus', $item->id) }}" permission="item.toggle" />
        </td>
        <td class="text-center">
            @include('admin.item.shared.controlBut', ['row' => $item])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
