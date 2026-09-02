@forelse ($banners as $banner)
    <div class="stack-row">
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($banner->image) !!}</span>
        </div>
        <div>
            {{-- The name leads and carries the link; the id follows as its
                 qualifier rather than taking a column of its own. --}}
            <span class="row-lead">
                @if (canDo('banner.view'))
                    <a href="{{ route('admin.banner.show', $banner->id) }}">{{ getLocalizedValueDashboard($banner, 'name') ?? '-' }}</a>
                @else
                    {{ getLocalizedValueDashboard($banner, 'name') ?? '-' }}
                @endif
            </span>
            <span class="row-sub">#{{ $banner->id }}</span>
        </div>
        <div>
            <span class="row-sub">{{ \Illuminate\Support\Str::limit(getLocalizedValueDashboard($banner, 'description') ?? '-', 90) }}</span>
        </div>
        <div>
            <x-status-toggle-button :id="$banner->id" :status="$banner->status"
                endpoint="{{ route('admin.banner.toggleStatus', $banner->id) }}" permission="banner.toggle" />
        </div>
        <div>
            <span class="row-sub">{{ humanDate($banner->created_at, 'Y-m-d H:i') }}</span>
        </div>
        <div class="stack-actions">
            @include('admin.banner.shared.controlBut', ['row' => $banner])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
