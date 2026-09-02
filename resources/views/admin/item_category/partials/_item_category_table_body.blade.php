@forelse ($itemCategories as $category)
    <div class="stack-row">
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($category->image) !!}</span>
        </div>
        <div>
            <span class="row-lead">
                @if (canDo('item_category.view'))
                    <a href="{{ route('admin.item_category.show', $category->id) }}">{{ getLocalizedValueDashboard($category, 'name') }}</a>
                @else
                    {{ getLocalizedValueDashboard($category, 'name') }}
                @endif
            </span>
            <span class="row-sub">#{{ $category->id }} · {{ __('Order') }} {{ $category->sort_order }}</span>
        </div>
        <div>
            {{-- A category with no pieces in it prices nothing, so the count is
                 the fact worth stating about one. --}}
            <span class="row-main">{{ $category->items_count ?? $category->items()->count() }}</span>
            <span class="row-sub">{{ __('Items') }}</span>
        </div>
        <div>
            <x-status-toggle-button :id="$category->id" :status="$category->status"
                endpoint="{{ route('admin.item_category.toggleStatus', $category->id) }}"
                permission="item_category.toggle" />
        </div>
        <div class="stack-actions">
            @include('admin.item_category.shared.controlBut', ['row' => $category])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
