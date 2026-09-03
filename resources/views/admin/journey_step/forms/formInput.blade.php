@php
    // Defensive unwrap: the accessor hands back a stdClass, but a value coming
    // straight from a failed validation round-trip is still a string.
    $titleTranslations = isset($row)
        ? (is_string($row->title) ? json_decode($row->title, true) : (array) $row->title)
        : [];
    $descriptionTranslations = isset($row)
        ? (is_string($row->description) ? json_decode($row->description, true) : (array) $row->description)
        : [];
    $defaultCode = getDefaultLanguage('code');
@endphp

<div class="row g-3">
    <div class="col-md-5">
        <div class="form-group">
            <label for="step-title" class="form-label">{{ __('Title') }}</label>
            <div class="controls">
                {{-- No `required` on either language box: the rule is «copy in at
                     least one language», which no single `required` attribute can
                     express. JourneyStepRequest enforces it, with its own
                     message. --}}
                <input type="text" name="title[{{ $defaultCode }}]" class="form-control"
                    {{ Route::is('*.show') ? 'disabled' : '' }} id="step-title"
                    placeholder="{{ __('Enter Title') }}"
                    value="{{ $titleTranslations[$defaultCode] ?? '' }}">
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="form-group">
            <label for="step-sort" class="form-label">{{ __('Order') }}</label>
            <div class="controls">
                <input type="number" name="sort_order" id="step-sort" class="form-control" min="0" step="1"
                    {{ Route::is('*.show') ? 'disabled' : '' }}
                    placeholder="{{ __('Enter Order') }}"
                    value="{{ isset($row) ? $row->sort_order : old('sort_order', 0) }}">
                {{-- The «1 · 2 · 3» beside each card on the home screen is this
                     position, not a stored number — so changing the order
                     renumbers the cards and the two can never disagree. --}}
                <div class="form-text">{{ __('This is the number shown beside the step in the app.') }}</div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label for="step-status" class="form-label">{{ __('Status') }}</label>
            <div class="controls">
                <select name="status" id="step-status" class="form-select"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
                    <option value="active" {{ old('status', $row->status ?? 'active') === 'active' ? 'selected' : '' }}>
                        {{ __('active') }}
                    </option>
                    <option value="inactive" {{ old('status', $row->status ?? '') === 'inactive' ? 'selected' : '' }}>
                        {{ __('inactive') }}
                    </option>
                </select>
                <div class="form-text">{{ __('Only active steps are sent to the app.') }}</div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="form-group">
            <label for="step-description" class="form-label">{{ __('Description') }}</label>
            <div class="controls">
                <textarea name="description[{{ $defaultCode }}]" class="form-control" rows="3"
                    {{ Route::is('*.show') ? 'disabled' : '' }} id="step-description"
                    placeholder="{{ __('Enter Description') }}">{{ $descriptionTranslations[$defaultCode] ?? '' }}</textarea>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="form-group">
            <label for="step-image" class="form-label">
                {{ __('Icon') }}<span class="text-danger">*</span>
            </label>
            <div class="upload-field">
                <div class="text-center">
                    <img id="image-preview" src="{{ isset($row) && $row->image ? getImageassetUrl($row->image) : '' }}"
                        alt="{{ __('Icon') }}" class="img-fluid rounded mb-2"
                        style="max-height: 150px; {{ isset($row) && $row->image ? '' : 'display:none;' }}">

                    @if (!Route::is('*.show'))
                        <input type="file" name="image" id="step-image" class="form-control"
                            onchange="previewImage(event)"
                            {{ Route::is('*.create') ? 'required' : '' }} accept="image/*">
                        <div class="form-text mt-2">{{ __('A small square illustration, shown beside the step.') }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<div class="col-12 form-divider">
    <div class="form-section-legend">{{ __('Translation') }}</div>
</div>

<div class="row g-3">
    @foreach (getAllLanguageWithoutDefault() as $language)
        <div class="col-md-4">
            <div class="form-group">
                <label for="step-title-{{ $language->code }}" class="form-label">
                    {{ __('Title') }} ({{ $language->name }})
                </label>
                <div class="controls">
                    <input type="text" name="title[{{ $language->code }}]" class="form-control"
                        id="step-title-{{ $language->code }}"
                        placeholder="{{ __('Enter Title') }} ({{ $language->name }})"
                        value="{{ $titleTranslations[$language->code] ?? '' }}"
                        {{ Route::is('*.show') ? 'disabled' : '' }}>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="form-group">
                <label for="step-description-{{ $language->code }}" class="form-label">
                    {{ __('Description') }} ({{ $language->name }})
                </label>
                <div class="controls">
                    <textarea name="description[{{ $language->code }}]" class="form-control" rows="3"
                        id="step-description-{{ $language->code }}"
                        placeholder="{{ __('Enter Description') }} ({{ $language->name }})"
                        {{ Route::is('*.show') ? 'disabled' : '' }}>{{ $descriptionTranslations[$language->code] ?? '' }}</textarea>
                </div>
            </div>
        </div>
    @endforeach
</div>
