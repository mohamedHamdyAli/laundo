@forelse ($services as $service)
    <div class="stack-row">
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($service->image) !!}</span>
        </div>
        <div>
            <span class="row-lead">
                @if (canDo('service.view'))
                    <a href="{{ route('admin.service.show', $service->id) }}">{{ getLocalizedValueDashboard($service, 'name') }}</a>
                @else
                    {{ getLocalizedValueDashboard($service, 'name') }}
                @endif
            </span>
            <span class="row-sub">#{{ $service->id }} · {{ __('Order') }} {{ $service->sort_order }}</span>
        </div>
        <div>
            @if ($service->pricing_mode === 'per_item')
                <span class="status-pill tone-live">{{ __('Per item') }}</span>
            @else
                {{-- Quoted after inspection: this service has no per-piece prices. --}}
                <span class="status-pill tone-warn">{{ __('Quoted') }}</span>
            @endif
        </div>
        <div>
            @php $d = $service->durationLabel(); @endphp
            <span class="row-main">{{ $d ? $d . ' ' . __($service->duration_unit === 'day' ? 'days' : 'hours') : '—' }}</span>
            <span class="row-sub">{{ __('Turnaround') }}</span>
        </div>
        <div>
            <x-status-toggle-button :id="$service->id" :status="$service->status"
                endpoint="{{ route('admin.service.toggleStatus', $service->id) }}" permission="service.toggle" />
        </div>
        <div class="stack-actions">
            @include('admin.service.shared.controlBut', ['row' => $service])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
