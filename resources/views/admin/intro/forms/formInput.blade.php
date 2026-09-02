@php
    $titleTranslations = isset($row)
        ? (is_string($row->title)
            ? json_decode($row->title, true)
            : (array) $row->title)
        : [];
    $descriptionTranslations = isset($row)
        ? (is_string($row->description)
            ? json_decode($row->description, true)
            : (array) $row->description)
        : [];
@endphp
{{-- Title / description / Order --}}
<div class="row g-3">
    <div class="col-md-4">
        <div class="form-group">
            <label for="intro-title" class="form-label">{{ __('Title') }}</label>
            <div class="controls">
                <input type="text" name="title[{{ getDefaultLanguage('code') }}]" class="form-control"
                    {{ Route::is('*.show') ? 'disabled' : '' }} id="intro-title" placeholder="{{ __('Enter Title') }}"
                    value="{{ $titleTranslations[getDefaultLanguage('code')] ?? '' }}"
                    {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label for="intro-description" class="form-label">{{ __('Description') }}</label>
            <div class="controls">
                <input type="description" name="description[{{ getDefaultLanguage('code') }}]" class="form-control"
                    {{ Route::is('*.show') ? 'disabled' : '' }} id="intro-description"
                    placeholder="{{ __('Enter Description') }}"
                    value="{{ $descriptionTranslations[getDefaultLanguage('code')] ?? '' }}"
                    {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Order') }}</label>
            <div class="controls">
                <input type="text" name="order" class="form-control" {{ Route::is('*.show') ? 'disabled' : '' }}
                    placeholder="{{ __('enter') }}{{ __('order') }}"
                    value="{{ isset($row) ? $row->order : old('order') }}"
                    {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
</div>

{{-- userRole / status --}}

<div class="row g-3">
    <div class="col-lg-6">
        <div class="mb-3">
            <label class="form-label">{{ __('Image') }}<span class="text-danger">*</span></label>
            <div class="card p-2 shadow-sm" style="border: 2px dashed #ddd;">
                <div class="text-center">
                    <img id="image-preview" src="{{ isset($row) && $row->image ? getImageassetUrl($row->image) : '' }}"
                        alt="Image Preview" class="img-fluid rounded mb-2"
                        style="max-height: 150px; {{ isset($row) && $row->image ? '' : 'display:none;' }}">

                    @if (!Route::is('*.show'))
                        <input type="file" name="image" class="form-control" onchange="previewImage(event)"
                            {{ Route::is('*.create') ? 'required' : '' }} accept="image/*">
                        <div class="form-text mt-2">Upload a clear image for the Intro.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Status') }}</label>
            <div class="controls">
                <select name="status" class="form-select" {{ Route::is('*.show') ? 'disabled' : '' }}>
                    <option value="active" {{ isset($row) && $row->status == 'active' ? 'selected' : '' }}>
                        {{ __('active') }}</option>
                    <option value="inactive" {{ isset($row) && $row->status == 'inactive' ? 'selected' : '' }}>
                        {{ __('inactive') }}</option>
                </select>
            </div>
        </div>
    </div>
    <div class="col-12">
        {{ __('Translation Optional') }}
    </div>

    @foreach (getAllLanguageWithoutDefault() as $language)
        <div class="col-lg-6">
            <div class="mb-3">
                <label for="intro-title-{{ $language->code }}" class="form-label">
                    Title ({{ $language->name }})
                </label>
                <input type="text" name="title[{{ $language->code }}]" class="form-control"
                    id="intro-title-{{ $language->code }}" placeholder="Enter Intro Title in {{ $language->name }}"
                    value="{{ $titleTranslations[$language->code] ?? '' }}"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="mb-3">
                <label for="intro-description-{{ $language->code }}" class="form-label">
                    Description ({{ $language->name }})
                </label>
                <input type="text" name="description[{{ $language->code }}]" class="form-control"
                    id="intro-description-{{ $language->code }}"
                    placeholder="Enter Intro Description in {{ $language->name }}"
                    value="{{ $descriptionTranslations[$language->code] ?? '' }}"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
            </div>
        </div>
    @endforeach
</div>
