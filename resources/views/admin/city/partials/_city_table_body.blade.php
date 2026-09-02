@forelse ($cities as $city)
    <div class="stack-row">
        <div>
            {{-- The name leads and carries the link; the id follows as its
                 qualifier rather than taking a column of its own. --}}
            <span class="row-lead">
                @if (canDo('city.view'))
                    <a href="{{ route('admin.city.show', $city->id) }}">{{ getLocalizedValueDashboard($city, 'name') ?? '-' }}</a>
                @else
                    {{ getLocalizedValueDashboard($city, 'name') ?? '-' }}
                @endif
            </span>
            <span class="row-sub">#{{ $city->id }}</span>
        </div>
        <div>
            <span class="row-main">{{ getLocalizedValueDashboard($city->country, 'name') ?? '-' }}</span>
        </div>
        <div>
            <x-status-toggle-button :id="$city->id" :status="$city->status"
                endpoint="{{ route('admin.city.toggleStatus', $city->id) }}" permission="city.toggle" />
        </div>
        <div>
            <span class="row-sub">{{ humanDate($city->created_at, 'Y-m-d H:i') }}</span>
        </div>
        <div class="stack-actions">
            @include('admin.city.shared.controlBut', ['row' => $city])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
