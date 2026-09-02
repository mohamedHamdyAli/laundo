@forelse ($countries as $country)
    <div class="stack-row">
        <div>
            <span class="row-lead">
                @if (canDo('country.view'))
                    <a href="{{ route('admin.country.show', $country->id) }}">{{ getLocalizedValueDashboard($country, 'name') ?? '-' }}</a>
                @else
                    {{ getLocalizedValueDashboard($country, 'name') ?? '-' }}
                @endif
            </span>
            <span class="row-sub">#{{ $country->id }}</span>
        </div>
        <div>
            {{-- The two codes belong together: one names the country, the other
                 dials it. --}}
            <span class="row-main">{{ $country->code ?? '-' }}</span>
            <span class="row-sub">{{ $country->phone_code ? '+' . ltrim($country->phone_code, '+') : '-' }}</span>
        </div>
        <div>
            <x-status-toggle-button :id="$country->id" :status="$country->status"
                endpoint="{{ route('admin.country.toggleStatus', $country->id) }}" permission="country.toggle" />
        </div>
        <div>
            <span class="row-sub">{{ humanDate($country->created_at, 'Y-m-d H:i') }}</span>
        </div>
        <div class="stack-actions">
            @include('admin.country.shared.controlBut', ['row' => $country])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
