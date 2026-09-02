@forelse ($intros as $intro)
    <div class="stack-row">
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($intro->image) !!}</span>
        </div>
        <div>
            <span class="row-lead">
                @if (canDo('intro.view'))
                    <a href="{{ route('admin.intro.show', $intro->id) }}">{{ getLocalizedValueDashboard($intro, 'title') ?? '-' }}</a>
                @else
                    {{ getLocalizedValueDashboard($intro, 'title') ?? '-' }}
                @endif
            </span>
            {{-- Screen order is what decides which slide a first-time user sees
                 first, so it rides with the title rather than sitting off in a
                 column of bare numbers. --}}
            <span class="row-sub">#{{ $intro->id }} · {{ __('Order') }} {{ $intro->order ?? '-' }}</span>
        </div>
        <div>
            <span class="row-sub">{{ \Illuminate\Support\Str::limit(getLocalizedValueDashboard($intro, 'description') ?? '-', 90) }}</span>
        </div>
        <div>
            <x-status-toggle-button :id="$intro->id" :status="$intro->status"
                endpoint="{{ route('admin.intro.toggleStatus', $intro->id) }}" permission="intro.toggle" />
        </div>
        <div>
            <span class="row-sub">{{ humanDate($intro->created_at, 'Y-m-d H:i') }}</span>
        </div>
        <div class="stack-actions">
            @include('admin.intro.shared.controlBut', ['row' => $intro])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
