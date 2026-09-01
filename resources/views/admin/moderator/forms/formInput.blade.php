<div class="row g-1">
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Name') }}</label>
            <div class="controls">
                <input type="text" name="name" class="form-control" placeholder="{{ __('Enter Name') }}"
                    value="{{ isset($row) ? $row->name : old('name') }}" {{ Route::is('*.create') ? 'required' : '' }}
                    {{ Route::is('*.show') ? 'readonly' : '' }}>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Email') }}</label>
            <div class="controls">
                <input type="email" name="email" class="form-control" placeholder="{{ __('Enter Email') }}"
                    value="{{ isset($row) ? $row->email : old('email') }}"
                    {{ Route::is('*.create') ? 'required' : '' }} {{ Route::is('*.show') ? 'readonly' : '' }}>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Phone') }}</label>
            <div class="controls">
                <input type="text" name="phone" class="form-control"
                    placeholder="{{ __('Egyptian number, e.g. 01XXXXXXXXX or +201XXXXXXXXX') }}"
                    value="{{ isset($row) ? $row->phone : old('phone') }}"
                    {{ Route::is('*.create') ? 'required' : '' }} {{ Route::is('*.show') ? 'readonly' : '' }}>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Role') }}<span class="text-danger">*</span></label>
            <div class="controls">
                <select name="role_id" class="form-control" {{ Route::is('*.create') ? 'required' : '' }}
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
                    <option value="">{{ __('Select Role') }}</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->id }}"
                            {{ isset($row) && $row->role_id == $role->id ? 'selected' : '' }}>
                            {{ $role->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="mb-3">
            <label class="form-label">{{ __('Profile Image') }}<span class="text-danger">*</span></label>
            <div class="card p-2 shadow-sm" style="border: 2px dashed #ddd;">
                <div class="text-center">
                    <img id="image-preview"
                        src="{{ isset($row) && $row->image_profile ? getImageassetUrl($row->image_profile) : '' }}"
                        alt="Image Preview" class="img-fluid rounded mb-2"
                        style="max-height: 150px; {{ isset($row) && $row->image_profile ? '' : 'display:none;' }}">

                    @if (!Route::is('*.show'))
                        <input type="file" name="image_profile" class="form-control" onchange="previewImage(event)"
                            {{ Route::is('*.create') ? 'required' : '' }} accept="image/*">
                        <div class="form-text mt-2">{{ __('Upload a clear image for the Moderator.') }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Status') }}</label>
            <div class="controls">
                <select name="status" class="form-control" {{ Route::is('*.show') ? 'disabled' : '' }}>
                    <option value="active" {{ isset($row) && $row->status == 'active' ? 'selected' : '' }}>
                        {{ __('Active') }}</option>
                    <option value="inactive" {{ isset($row) && $row->status == 'inactive' ? 'selected' : '' }}>
                        {{ __('Inactive') }}</option>
                </select>
            </div>
        </div>
    </div>

    @if (!Route::is('*.show'))
        <div class="col-md-4">
            <div class="form-group">
                <label class="form-label">{{ __('Password') }}{{ Route::is('*.create') ? '' : ' ' . __('(leave blank to keep current)') }}<span class="text-danger">*</span></label>
                <div class="controls">
                    <input type="password" name="password" class="form-control"
                        placeholder="{{ __('Enter Password') }}" {{ Route::is('*.create') ? 'required' : '' }}>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="form-group">
                <label class="form-label">{{ __('Confirm Password') }}<span class="text-danger">*</span></label>
                <div class="controls">
                    <input type="password" name="password_confirmation" class="form-control"
                        placeholder="{{ __('Confirm Password') }}" {{ Route::is('*.create') ? 'required' : '' }}>
                </div>
            </div>
        </div>
    @endif
</div>
