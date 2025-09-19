@php
    $nameTranslations = isset($row) ? (is_string($row->name) ? json_decode($row->name, true) : (array) $row->name) : [];
@endphp

<div class="row">
    <div class="col-lg-6" id="col-name">
        <div class="mb-3">
            <label for="category-name" class="form-label">{{ __('Name') }} <span class="text-danger">*</span></label>
            <input type="text" name="name[{{ getDefaultLanguage('code') }}]" class="form-control" id="category-name"
                placeholder="{{ __('Enter Category Name') }}"
                value="{{ $nameTranslations[getDefaultLanguage('code')] ?? '' }}" required
                {{ Route::is('*.show') ? 'disabled' : '' }}>
        </div>
    </div>

    <div class="col-lg-6" id="col-parent">
        <div class="mb-3">
            <label for="parent-category" class="form-label">{{ __('Parent Category') }}</label>
            <select name="parent_id" id="parent-category" class="form-select"
                {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="">{{ __('Select Category') }}</option>
                @include('admin.category.partials._category_options', [
                    'categories' => $Categories,
                    'level' => 0,
                ])
            </select>
        </div>
    </div>

    <div class="col-lg-4" id="default-price-wrapper" style="display: none;">
        <div class="mb-3">
            <label for="default-price" class="form-label">{{ __('Default Price') }}</label>
            <input type="number" step="0.01" name="default_price" id="default-price" class="form-control"
                placeholder="{{ __('Enter Default Price') }}"
                value="{{ old('default_price', $row->default_price ?? '') }}"
>
        </div>
    </div>


    <div class="col-lg-6">
        <div class="mb-3">
            <label for="status-select" class="form-label">{{ __('Status') }}</label>
            <select name="status" id="status-select" class="form-select" {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="active" {{ isset($row) && $row->status == 'active' ? 'selected' : '' }}>
                    {{ __('Active') }}
                </option>
                <option value="inactive" {{ isset($row) && $row->status == 'inactive' ? 'selected' : '' }}>
                    {{ __('Inactive') }}
                </option>
            </select>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="mb-3">
            <label class="form-label">{{ __('Category Image') }}<span class="text-danger">*</span></label>
            <div class="card p-2 shadow-sm" style="border: 2px dashed #ddd;">
                <div class="text-center">
                    <img id="image-preview" src="{{ isset($row) && $row->image ? getImageassetUrl($row->image) : '' }}"
                        alt="Image Preview" class="img-fluid rounded mb-2"
                        style="max-height: 150px; {{ isset($row) && $row->image ? '' : 'display:none;' }}">

                    @if (!Route::is('*.show'))
                        <input type="file" name="image" class="form-control" onchange="previewImage(event)"
                            {{ Route::is('*.create') ? 'required' : '' }} accept="image/*">
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        {{ __('Translation') }}
    </div>

    @foreach (getAllLanguageWithoutDefault() as $language)
        <div class="col-lg-6">
            <div class="mb-3">
                <label for="category-name-{{ $language->code }}" class="form-label">
                    {{ __('Name') }} ({{ $language->name }})
                </label>
                <input type="text" name="name[{{ $language->code }}]" class="form-control"
                    id="category-name-{{ $language->code }}"
                    placeholder="{{ __('Enter Category Name in') }} {{ $language->name }}"
                    value="{{ $nameTranslations[$language->code] ?? '' }}"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
            </div>
        </div>
    @endforeach
</div>



