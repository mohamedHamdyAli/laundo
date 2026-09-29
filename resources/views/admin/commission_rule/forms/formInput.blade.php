@php
    // Defensive unwrap: the accessor hands back a stdClass, but a value coming
    // straight from a failed validation round-trip is still a string.
    $nameTranslations = isset($row)
        ? (is_string($row->name) ? json_decode($row->name, true) : (array) $row->name)
        : [];
    $defaultCode = getDefaultLanguage('code');
    $readonly = Route::is('*.show');

    $attached = old('laundry_ids', isset($row) ? $row->laundries->pluck('id')->all() : []);
@endphp

<div class="row g-3">
    <div class="col-md-6">
        <div class="form-group">
            <label for="commission-name" class="form-label">{{ __('Name') }}</label>
            <div class="controls">
                {{-- No `required`: the rule is «a name in at least one
                     language», which no single attribute can express, and
                     putting it on the default language would have the browser
                     refuse a save the server accepts. --}}
                <input type="text" name="name[{{ $defaultCode }}]" class="form-control" id="commission-name"
                    {{ $readonly ? 'disabled' : '' }}
                    placeholder="{{ __('e.g. Standard laundry share') }}"
                    value="{{ $nameTranslations[$defaultCode] ?? '' }}">
                <div class="form-text">
                    {{ __('This is the name the laundry sees on its own settlement.') }}
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="form-group">
            <label for="commission-status" class="form-label">{{ __('Status') }}</label>
            <div class="controls">
                <select name="status" id="commission-status" class="form-select" {{ $readonly ? 'disabled' : '' }}>
                    <option value="active" {{ old('status', $row->status ?? 'active') === 'active' ? 'selected' : '' }}>
                        {{ __('active') }}
                    </option>
                    <option value="inactive" {{ old('status', $row->status ?? '') === 'inactive' ? 'selected' : '' }}>
                        {{ __('inactive') }}
                    </option>
                </select>
                <div class="form-text">{{ __('A switched-off share pays nothing: every laundry on it falls back to the general share in Settings.') }}</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 border rounded p-3 mb-3 mt-1">
    <h5 class="mb-1">{{ __('What the laundry receives') }}</h5>
    <p class="text-muted small">
        {{ __('A percentage of the washing — the piece prices the laundry set, after any discount. The laundry receives this share and the rest stays with the platform. The delivery fee, the cash fee, the customer platform fee and the tax are never divided.') }}
        <strong>{{ __('A laundry is on one share at a time.') }}</strong>
    </p>

    @if (isset($row) && $row->basis->isFixed())
        {{-- A fixed rule from before the share changed sides. Saving writes it
             as a percentage — the service clears the amount — so the operator
             is told what the old terms were rather than finding them gone. --}}
        <div class="col-12">
            <div class="alert alert-warning small mb-0">
                {{ __('This rule was a fixed amount per order (:amount), which is retired. Saving turns it into a percentage share.', ['amount' => moneyFormat($row->amount)]) }}
            </div>
        </div>
    @endif

    <div class="col-md-4" id="commission-rate-field">
        <div class="form-group">
            <label for="commission-rate" class="form-label">{{ __('Laundry share') }}</label>
            <div class="input-group">
                <input type="number" step="0.01" min="0" max="100" name="rate" id="commission-rate"
                    class="form-control" {{ $readonly ? 'disabled' : '' }}
                    value="{{ old('rate', $row->rate ?? '') }}">
                <span class="input-group-text">%</span>
            </div>
            <div class="form-text">{{ __('e.g. 10 means the laundry receives 10 of every 100 and the platform keeps 90.') }}</div>
        </div>
    </div>
</div>

<div class="row g-3 border rounded p-3 mb-3">
    <h5 class="mb-1">{{ __('Which laundries are on it') }}</h5>
    <p class="text-muted small">
        {{ __('Tick every laundry paid on this share. A laundry already on another active share cannot be ticked here — move it from that share first.') }}
        <strong>{{ __('A laundry on no share follows the general share in Settings.') }}</strong>
    </p>

    <div class="col-12">
        @forelse ($laundries as $laundry)
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="laundry_ids[]"
                    id="laundry-{{ $laundry->id }}" value="{{ $laundry->id }}"
                    {{ $readonly ? 'disabled' : '' }}
                    {{ in_array($laundry->id, (array) $attached) ? 'checked' : '' }}>
                <label class="form-check-label" for="laundry-{{ $laundry->id }}">
                    {{ getLocalizedValueDashboard($laundry, 'name') }}
                </label>
            </div>
        @empty
            <p class="text-muted small mb-0">{{ __('No data found') }}</p>
        @endforelse
    </div>
</div>
