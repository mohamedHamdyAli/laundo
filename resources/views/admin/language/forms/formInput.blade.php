{{-- username / email / code --}}
<div class="row g-1">
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Name') }}</label>
            <div class="controls">
                <input type="text" name="name" class="form-control" placeholder="{{ __('Enter Name') }}"
                    value="{{ isset($row) ? $row->name : old('name') }}" {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Name En') }}</label>
            <div class="controls">
                <input type="text" name="name_en" class="form-control" placeholder="{{ __('Enter Name En') }}"
                    value="{{ isset($row) ? $row->name_en : old('name_en') }}"
                    {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Code') }}</label>
            <div class="controls">
                <input type="text" name="code" class="form-control" placeholder="{{ __('Enter Code') }}"
                    value="{{ isset($row) ? $row->code : old('code') }}" {{ Route::is('*.create') ? 'required' : '' }}
                    {{ Route::is('*.edit') ? 'readonly' : '' }}>
            </div>
        </div>
    </div>
</div>

{{-- userRole / is_rtl --}}

<div class="row g-1">
    <div class="col-md-6">
        <div class="form-group">
            <label class="form-label">{{ __('Status') }}</label>
            <div class="controls">
                <select name="status" class="form-control" {{ Route::is('*.show') ? 'disabled' : '' }}>
                    <option value="active" {{ isset($row) && $row->status == 'active' ? 'selected' : '' }}>
                        {{ __('active') }}</option>
                    <option value="inactive" {{ isset($row) && $row->status == 'inactive' ? 'selected' : '' }}>
                        {{ __('inactive') }}</option>
                </select>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label class="form-label">{{ __('Is Rtl') }}</label>
            <div class="controls">
                <select name="is_rtl" class="form-control">
                    <option value="true" {{ isset($row) && $row->is_rtl == 'true' ? 'selected' : '' }}>
                        {{ __('True') }}</option>
                    <option value="false" {{ isset($row) && $row->is_rtl == 'false' ? 'selected' : '' }}>
                        {{ __('False') }}</option>
                </select>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-6">
            <label class="form-label">{{ __('icon') }}<span class="text-danger">*</span></label>
            <div class="card p-2 shadow-sm" style="border: 2px dashed #ddd;">
                <div class="text-center">
                    <img id="icon-preview" src="{{ isset($row) && $row->icon ? getImageassetUrl($row->icon) : '' }}"
                        alt="icon Preview" class="img-fluid rounded mb-2"
                        style="max-height: 150px; {{ isset($row) && $row->icon ? '' : 'display:none;' }}">

                    @if (!Route::is('*.show'))
                        <input type="file" name="icon" class="form-control" onchange="previewImage(event)"
                            {{ Route::is('*.create') ? 'required' : '' }} accept="icon/*">
                        <div class="form-text mt-2">Upload a clear icon for the Intro.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label class="form-label">{{ __('Country Code') }}</label>
            <div class="controls">
                <input type="text" name="country_code" class="form-control"
                    placeholder="{{ __('Enter Country Code') }}"
                    value="{{ Route::is('*.show') ? $row->country_code : (isset($row) ? $row->country_code : old('country_code')) }}"
                    {{ Route::is('*.create') ? 'required' : '' }} {{ Route::is('*.show') ? 'readonly' : '' }}>
            </div>
        </div>
    </div>
</div>