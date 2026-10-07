@forelse ($coverageRequests as $coverageRequest)
    @php
        $nowCovered = $coverageRequest->isNowCovered();
        $contacted = $coverageRequest->isContacted();
    @endphp
    {{-- The warn stripe is the row somebody can act on today: served now, and
         nobody has rung. A row still outside every zone has nothing to say yet. --}}
    <div class="stack-row {{ $nowCovered && ! $contacted ? 'tone-warn' : '' }}">
        <div>
            <span class="row-lead">{{ $coverageRequest->customer?->name ?? '—' }}</span>
        </div>

        {{-- One tap: the only thing anybody does on this screen is ring them. --}}
        <div>
            @if ($coverageRequest->customer?->phone)
                <a href="tel:{{ $coverageRequest->customer->phone }}" dir="ltr">{{ $coverageRequest->customer->phone }}</a>
            @else
                —
            @endif
        </div>

        <div>
            <span class="row-main">{{ $coverageRequest->address_line ?? '—' }}</span>
            {{-- The pin as it was when they asked, on OpenStreetMap — the map
                 this panel already uses for places. --}}
            <a class="row-sub" target="_blank" rel="noopener noreferrer"
                href="https://www.openstreetmap.org/?mlat={{ (float) $coverageRequest->lat }}&mlon={{ (float) $coverageRequest->lng }}#map=17/{{ (float) $coverageRequest->lat }}/{{ (float) $coverageRequest->lng }}">
                <i class="bi bi-geo-alt" aria-hidden="true"></i> {{ __('On the map') }}
            </a>
        </div>

        <div>
            <span class="row-main">{{ $coverageRequest->attempts }}</span>
            <span class="row-sub">{{ __('Last: :when', ['when' => humanDate($coverageRequest->last_attempt_at)]) }}</span>
        </div>

        <div>
            @if ($nowCovered)
                <span class="status-pill tone-ok">{{ __('Covered now') }}</span>
                @if ($coverageRequest->address?->zone)
                    <span class="row-sub">{{ getLocalizedValueDashboard($coverageRequest->address->zone, 'name') }}</span>
                @endif
            @elseif ($coverageRequest->address === null)
                <span class="status-pill tone-neutral">{{ __('Address deleted') }}</span>
            @else
                <span class="status-pill tone-bad">{{ __('Still outside') }}</span>
            @endif
        </div>

        <div>
            @if ($contacted)
                <span class="status-pill tone-ok">{{ __('Contacted') }}</span>
                @if ($coverageRequest->contacter)
                    <span class="row-sub">{{ $coverageRequest->contacter->name }}</span>
                @endif
            @else
                <span class="status-pill tone-warn">{{ __('Not called yet') }}</span>
            @endif
        </div>

        <div class="text-end">
            @if (canDo('coverage_request.toggle'))
                <form method="POST" action="{{ route('admin.coverage_request.contacted', $coverageRequest->id) }}"
                    class="d-inline">
                    @csrf
                    <button type="submit"
                        class="btn btn-sm {{ $contacted ? 'btn-outline-secondary' : 'btn-success' }}">
                        {{ $contacted ? __('Put back') : __('We called them') }}
                    </button>
                </form>
            @endif
        </div>
    </div>
@empty
    <div class="stack-empty">
        {{ __('Nobody has been turned away for their area.') }}
    </div>
@endforelse
