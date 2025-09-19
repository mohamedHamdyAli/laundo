{{-- username / email / phone --}}
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
            <label class="form-label">{{ __('Email') }}</label>
            <div class="controls">
                <input type="email" name="email" class="form-control" placeholder="{{ __('Enter Email') }}"
                    value="{{ isset($row) ? $row->email : old('email') }}"
                    {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Phone') }}</label>
            <div class="controls">
                <input type="text" name="phone" class="form-control" placeholder="{{ __('Enter Phone') }}"
                    value="{{ Route::is('*.show') ? $row->phone : (isset($userAuth) ? $userAuth->phone : old('phone')) }}"
                    {{ Route::is('*.create') ? 'required' : '' }} {{ Route::is('*.show') ? 'readonly' : '' }}>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="mb-3">
            <label class="form-label">{{ __('Image Profile') }}<span class="text-danger">*</span></label>
            <div class="card p-2 shadow-sm" style="border: 2px dashed #ddd;">
                <div class="text-center">
                    <img id="image-preview"
                        src="{{ isset($row) && $row->image_profile ? getImageassetUrl($row->image_profile) : '' }}"
                        alt="Image Preview" class="img-fluid rounded mb-2"
                        style="max-height: 150px; {{ isset($row) && $row->image_profile ? '' : 'display:none;' }}">

                    @if (!Route::is('*.show'))
                        <input type="file" name="image_profile" class="form-control" onchange="previewImage(event)"
                            {{ Route::is('*.create') ? 'required' : '' }} accept="image/*">
                        <div class="form-text mt-2">Upload a clear image for the User.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Status') }}</label>
            <div class="controls">
                <select name="status" class="form-control">
                    <option value="active" {{ isset($row) && $row->status == 'active' ? 'selected' : '' }}>
                        {{ __('Active') }}</option>
                    <option value="inactive" {{ isset($row) && $row->status == 'inactive' ? 'selected' : '' }}>
                        {{ __('Inactive') }}</option>
                </select>
            </div>
        </div>
    </div>
</div>