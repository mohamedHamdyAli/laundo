@php
    $nameT = isset($row) ? (is_string($row->name) ? json_decode($row->name, true) : (array) $row->name) : [];
@endphp

<div class="row">
    <div class="col-lg-6">
        <div class="mb-3">
            <label class="form-label">{{ __('Item Name') }} <span class="text-danger">*</span></label>
            <input type="text" name="name[{{ getDefaultLanguage('code') }}]" class="form-control"
                placeholder="{{ __('e.g. Shirt on hanger') }}"
                value="{{ $nameT[getDefaultLanguage('code')] ?? '' }}"
                {{ Route::is('*.create') ? 'required' : '' }} {{ Route::is('*.show') ? 'disabled' : '' }}>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="mb-3">
            <label class="form-label">{{ __('Item Category') }} <span class="text-danger">*</span></label>
            <select name="item_category_id" class="form-select" {{ Route::is('*.create') ? 'required' : '' }}
                {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="">{{ __('Select Category') }}</option>
                @foreach ($itemCategories ?? [] as $cat)
                    <option value="{{ $cat->id }}"
                        {{ old('item_category_id', $row->item_category_id ?? '') == $cat->id ? 'selected' : '' }}>
                        {{ getLocalizedValueDashboard($cat, 'name') }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="mb-3">
            <label class="form-label">{{ __('Display Order') }}</label>
            <input type="number" min="0" name="sort_order" class="form-control"
                value="{{ old('sort_order', $row->sort_order ?? 0) }}" {{ Route::is('*.show') ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="mb-3">
            <label class="form-label">{{ __('Status') }}</label>
            <select name="status" class="form-select" {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="active" {{ isset($row) && $row->status == 'active' ? 'selected' : '' }}>
                    {{ __('Active') }}</option>
                <option value="inactive" {{ isset($row) && $row->status == 'inactive' ? 'selected' : '' }}>
                    {{ __('Inactive') }}</option>
            </select>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="mb-3">
            <label class="form-label">{{ __('Image') }}</label>
            <div class="card p-2 shadow-sm" style="border: 2px dashed #ddd;">
                <div class="text-center">
                    <img id="image-preview" src="{{ isset($row) && $row->image ? getImageassetUrl($row->image) : '' }}"
                        alt="Image Preview" class="img-fluid rounded mb-2"
                        style="max-height: 150px; {{ isset($row) && $row->image ? '' : 'display:none;' }}">

                    @if (!Route::is('*.show'))
                        <input type="file" name="image" class="form-control" onchange="previewImage(event)"
                            accept="image/*">
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 form-divider">
        <div class="form-section-legend">{{ __('Translation') }}</div>
    </div>

    @foreach (getAllLanguageWithoutDefault() as $language)
        <div class="col-lg-6">
            <div class="mb-3">
                <label class="form-label">{{ __('Name') }} ({{ $language->name }})</label>
                <input type="text" name="name[{{ $language->code }}]" class="form-control"
                    value="{{ $nameT[$language->code] ?? '' }}" {{ Route::is('*.show') ? 'disabled' : '' }}>
            </div>
        </div>
    @endforeach
</div>
