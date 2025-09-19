@foreach ($categories as $category)
    <option value="{{ $category->id }}" {{ isset($row) && $row->parent_id == $category->id ? 'selected' : '' }}
        @if ($level === 0) style="font-weight:bold;" @endif>
        {{ str_repeat('⮑ ', $level) . getLocalizedValueDashboard($category, 'name') }}
    </option>

    @if ($category->children && $category->children->count())
        @include('admin.category.partials._category_options', [
            'categories' => $category->children,
            'level' => $level + 1,
        ])
    @endif
@endforeach
