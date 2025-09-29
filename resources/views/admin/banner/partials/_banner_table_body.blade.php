@forelse ($banners as $banner)
    <tr>
        <td>{!! getImageDashboardUrl($banner->image) !!}</td>
        <td>{{ $banner->id ?? 'None' }}</td>
        <td>{{ getLocalizedValueDashboard($banner, 'name') ?? '-' }}</td>
        <td>{{ getLocalizedValueDashboard($banner, 'description') ?? '-' }}</td>
        <td>
            <x-status-toggle-button :id="$banner->id" :status="$banner->status"
                endpoint="{{ route('admin.banner.toggleStatus', $banner->id) }}" />
        </td>
        <td>{{ $banner->created_at->format('Y-m-d H:i') ?? 'None' }}</td>
        <td class="text-center">
            @include('admin.banner.shared.controlBut', [
                'row' => $banner,
            ])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
