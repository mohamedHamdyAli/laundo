@forelse ($categories as $category)
    <div class="stack-row">
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($category->image) !!}</span>
        </div>
        <div>
            <span class="row-lead">
                @if (canDo('category.view'))
                    <a href="{{ route('admin.category.show', $category->id) }}">{{ getLocalizedValueDashboard($category, 'name') ?? '-' }}</a>
                @else
                    {{ getLocalizedValueDashboard($category, 'name') ?? '-' }}
                @endif
            </span>
            <span class="row-sub">#{{ $category->id }}</span>
        </div>
        <div>
            {{-- The link is only worth drawing when there is something behind
                 it, so a leaf category states that instead. --}}
            @if ($category->children && $category->children->count() > 0)
                <a href="{{ route('admin.category.showSubCategories', $category->id) }}" class="row-main">
                    {{ $category->children->count() }} {{ __('Subcategories') }}
                </a>
            @else
                <span class="row-sub">{{ __('No subcategories') }}</span>
            @endif
        </div>
        <div>
            <x-status-toggle-button :id="$category->id" :status="$category->status"
                endpoint="{{ route('admin.category.toggleStatus', $category->id) }}" permission="category.toggle" />
        </div>
        <div>
            <span class="row-sub">{{ $category->created_at ? humanDate($category->created_at, 'Y-m-d H:i') : '-' }}</span>
        </div>
        <div class="stack-actions">
            @include('admin.category.shared.controlBut', ['row' => $category])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
