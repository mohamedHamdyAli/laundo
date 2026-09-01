@forelse ($itemCategories as $category)
    <tr>
        <td>{!! getImageDashboardUrl($category->image) !!}</td>
        <td>{{ $category->id }}</td>
        <td>{{ getLocalizedValueDashboard($category, 'name') }}</td>
        <td>{{ $category->items_count ?? $category->items()->count() }}</td>
        <td>{{ $category->sort_order }}</td>
        <td>
            <x-status-toggle-button :id="$category->id" :status="$category->status"
                endpoint="{{ route('admin.item_category.toggleStatus', $category->id) }}"
                permission="item_category.toggle" />
        </td>
        <td class="text-center">
            @include('admin.item_category.shared.controlBut', ['row' => $category])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="7" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
