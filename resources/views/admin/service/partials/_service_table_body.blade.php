@forelse ($services as $service)
    <tr>
        <td>{!! getImageDashboardUrl($service->image) !!}</td>
        <td>{{ $service->id }}</td>
        <td>{{ getLocalizedValueDashboard($service, 'name') }}</td>
        <td>
            @if ($service->pricing_mode === 'per_item')
                <span class="badge bg-info">{{ __('Per item') }}</span>
            @else
                {{-- Quoted after inspection: this service has no per-piece prices. --}}
                <span class="badge bg-secondary">{{ __('Quoted') }}</span>
            @endif
        </td>
        <td>
            @php $d = $service->durationLabel(); @endphp
            {{ $d ? $d . ' ' . __($service->duration_unit === 'day' ? 'days' : 'hours') : '—' }}
        </td>
        <td>{{ $service->sort_order }}</td>
        <td>
            <x-status-toggle-button :id="$service->id" :status="$service->status"
                endpoint="{{ route('admin.service.toggleStatus', $service->id) }}" permission="service.toggle" />
        </td>
        <td class="text-center">
            @include('admin.service.shared.controlBut', ['row' => $service])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
