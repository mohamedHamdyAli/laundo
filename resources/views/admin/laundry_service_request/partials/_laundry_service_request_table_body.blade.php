{{--
    One row per request. Pending rows carry approve and reject; decided rows say
    who decided, when, and — for a refusal — the note the laundry was sent.
--}}
@forelse ($requests as $row)
    @php
        $laundryName = $row->laundry ? getLocalizedValueDashboard($row->laundry, 'name') : '—';
        $serviceName = $row->service ? getLocalizedValueDashboard($row->service, 'name') : '—';
        $tone = match ($row->status) {
            'pending' => 'tone-warn',
            'approved' => 'tone-ok',
            default => 'tone-bad',
        };
    @endphp
    <div class="stack-row">
        <div>
            <span class="row-lead">{{ $laundryName }}</span>
            <span class="row-sub">{{ $row->requester?->name }}</span>
        </div>

        <div>
            <span class="row-main">
                {{ $row->opens() ? __('Open') : __('Close') }} · {{ $serviceName }}
            </span>
            <span class="row-sub">
                {{ $row->opens() ? __('Starts receiving orders in it once approved') : __('Stops receiving new orders in it once approved') }}
            </span>
        </div>

        <div>
            <span class="row-main">{{ humanDate($row->created_at, 'Y-m-d H:i') }}</span>
        </div>

        <div>
            <span class="status-pill {{ $tone }}">
                {{ match ($row->status) {
                    'pending' => __('Awaiting review'),
                    'approved' => __('Approved'),
                    default => __('Rejected'),
                } }}
            </span>
            @if ($row->reviewed_at)
                <span class="row-sub">{{ $row->reviewer?->name }} · {{ humanDate($row->reviewed_at, 'Y-m-d H:i') }}</span>
            @endif
            @if ($row->status === 'rejected' && $row->note)
                <span class="row-sub text-danger">{{ $row->note }}</span>
            @endif
        </div>

        <div class="stack-actions">
            @if ($row->isPending() && canDo('laundry_service_request.update'))
                <form method="POST" action="{{ route('admin.laundry_service_request.approve', $row->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success">{{ __('Approve') }}</button>
                </form>
                <button type="button" class="btn btn-sm btn-outline-danger js-service-request-reject"
                    data-action="{{ route('admin.laundry_service_request.reject', $row->id) }}"
                    data-summary="{{ $laundryName }} — {{ $row->opens() ? __('Open') : __('Close') }} · {{ $serviceName }}">
                    {{ __('Reject') }}
                </button>
            @endif
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
