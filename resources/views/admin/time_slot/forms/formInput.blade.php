<div class="row">
    <div class="col-lg-3">
        <div class="mb-3">
            <label class="form-label">{{ __('Start Time') }} <span class="text-danger">*</span></label>
            <input type="time" name="start_time" class="form-control"
                value="{{ old('start_time', isset($row) ? substr($row->start_time, 0, 5) : '') }}"
                {{ Route::is('*.create') ? 'required' : '' }} {{ Route::is('*.show') ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="mb-3">
            <label class="form-label">{{ __('End Time') }} <span class="text-danger">*</span></label>
            <input type="time" name="end_time" class="form-control"
                value="{{ old('end_time', isset($row) ? substr($row->end_time, 0, 5) : '') }}"
                {{ Route::is('*.create') ? 'required' : '' }} {{ Route::is('*.show') ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="mb-3">
            <label class="form-label">{{ __('Used For') }}</label>
            <select name="applies_to" class="form-select" {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="both" {{ old('applies_to', $row->applies_to ?? 'both') === 'both' ? 'selected' : '' }}>
                    {{ __('Pickup & Delivery') }}</option>
                <option value="pickup" {{ old('applies_to', $row->applies_to ?? '') === 'pickup' ? 'selected' : '' }}>
                    {{ __('Pickup only') }}</option>
                <option value="delivery" {{ old('applies_to', $row->applies_to ?? '') === 'delivery' ? 'selected' : '' }}>
                    {{ __('Delivery only') }}</option>
            </select>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="mb-3">
            <label class="form-label">{{ __('Capacity') }}</label>
            <input type="number" min="1" name="capacity" class="form-control"
                placeholder="{{ __('Leave empty for unlimited') }}"
                value="{{ old('capacity', $row->capacity ?? '') }}" {{ Route::is('*.show') ? 'readonly' : '' }}>
            <div class="form-text">{{ __('Maximum orders in this window. Empty means no limit.') }}</div>
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
</div>
