@forelse ($laundries as $laundry)
    <div class="stack-row {{ $laundry->status === 'active' ? '' : 'tone-bad' }}">
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($laundry->logo) !!}</span>
        </div>
        <div>
            <span class="row-lead">
                @if (canDo('laundry.view'))
                    <a href="{{ route('admin.laundry.show', $laundry->id) }}">{{ getLocalizedValueDashboard($laundry, 'name') }}</a>
                @else
                    {{ getLocalizedValueDashboard($laundry, 'name') }}
                @endif
            </span>
            <span class="row-sub">#{{ $laundry->id }}</span>
        </div>
        <div>
            <span class="row-main">{{ $laundry->phone ?? '-' }}</span>
        </div>
        <div>
            {{-- Which city a laundry sits in decides which orders can reach it,
                 so it belongs beside the name rather than three columns away. --}}
            <span class="row-main">{{ $laundry->city ? getLocalizedValueDashboard($laundry->city, 'name') : '-' }}</span>
        </div>
        <div>
            <x-status-toggle-button :id="$laundry->id" :status="$laundry->status"
                endpoint="{{ route('admin.laundry.toggleStatus', $laundry->id) }}" permission="laundry.toggle" />
        </div>
        <div class="stack-actions">
            @include('admin.laundry.shared.controlBut', ['row' => $laundry])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
