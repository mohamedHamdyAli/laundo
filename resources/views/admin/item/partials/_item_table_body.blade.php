@forelse ($items as $item)
    <div class="stack-row">
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($item->image) !!}</span>
        </div>
        <div>
            <span class="row-lead">
                @if (canDo('item.view'))
                    <a href="{{ route('admin.item.show', $item->id) }}">{{ getLocalizedValueDashboard($item, 'name') }}</a>
                @else
                    {{ getLocalizedValueDashboard($item, 'name') }}
                @endif
            </span>
            <span class="row-sub">#{{ $item->id }} · {{ __('Order') }} {{ $item->sort_order }}</span>
        </div>
        <div>
            <span class="row-main">{{ $item->category ? getLocalizedValueDashboard($item->category, 'name') : '—' }}</span>
        </div>
        <div>
            <x-status-toggle-button :id="$item->id" :status="$item->status"
                endpoint="{{ route('admin.item.toggleStatus', $item->id) }}" permission="item.toggle" />
        </div>
        <div class="stack-actions">
            @include('admin.item.shared.controlBut', ['row' => $item])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
