@forelse ($categories as $category)
    <tr>
        <td>{{ $loop->iteration }}</td>
        <td>{!! getImageDashboardUrl($category->image) !!}</td>
        <td>
            <div class="d-flex justify-content-between align-items-center">
                <span>{{ getLocalizedValueDashboard($category, 'name') ?? '-' }}</span>
                @if ($category->children && $category->children->count() > 0)
                    <a href="{{ route('admin.category.showSubCategories', $category->id) }}"
                        class="btn btn-primary rounded-circle d-inline-flex justify-content-center align-items-center ms-2"
                        style="width: 30px; height: 30px;" title="View Subcategories">
                        <i class="fa fa-plus text-white small"></i>
                    </a>
                @endif
            </div>
        </td>
        <td>
            <x-status-toggle-button :id="$category->id" :status="$category->status"
                endpoint="{{ route('admin.category.toggleStatus', $category->id) }}" permission="category.toggle" />
        </td>
        <td>
            {{ $category->created_at ? \Carbon\Carbon::parse($category->created_at)->translatedFormat('j M Y - g:ia') : 'None' }}
        </td>
        <td class="text-center">
            @include('admin.category.shared.controlBut', [
                'row' => $category,
            ])
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
