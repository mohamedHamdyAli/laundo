@forelse ($complaints as $row)
    @php
        $state = $row->statusCase();
        $waiting = $row->waitingHours();
        // Open for more than a day. A status pill cannot say how long.
        $overdue = $waiting !== null && $waiting > 24;
    @endphp
    <div class="stack-row {{ $overdue ? 'tone-bad' : '' }}">
        <div>
            <span class="row-lead">
                <a href="{{ route('admin.complaint.show', $row->id) }}">{{ $row->reference }}</a>
            </span>
            <span class="row-sub">{{ $row->order?->code ?? __($row->categoryCase()->label()) }}</span>
        </div>
        <div>
            <span class="row-main">{{ $row->complainant?->name ?? '—' }}</span>
            <span class="row-sub">{{ $row->complainant?->phone }}</span>
        </div>
        <div>
            <span class="row-sub">{{ \Illuminate\Support\Str::limit($row->body, 110) }}</span>
        </div>
        <div>
            <span class="row-main">
                {{ $row->laundry ? getLocalizedValueDashboard($row->laundry, 'name') : '—' }}
            </span>
            <span class="row-sub">{{ $row->handler?->name ?: __('Unassigned') }}</span>
        </div>
        <div>
            @if ($state === \App\Modules\Complaint\Enums\ComplaintStatus::New)
                <span class="status-pill tone-bad">{{ __($state->label()) }}</span>
            @elseif ($state === \App\Modules\Complaint\Enums\ComplaintStatus::InProgress)
                <span class="status-pill tone-live">{{ __($state->label()) }}</span>
            @elseif ($state === \App\Modules\Complaint\Enums\ComplaintStatus::Resolved)
                <span class="status-pill tone-ok">{{ __($state->label()) }}</span>
            @else
                <span class="status-pill tone-warn">{{ __($state->label()) }}</span>
            @endif
            {{-- How long it has been open is the number that decides whether this
                 row is a problem, so it rides with the status. --}}
            <span class="row-sub">
                @if ($waiting === null)
                    {{ $row->handled_at ? humanDate($row->handled_at, 'Y-m-d H:i') : '—' }}
                @else
                    {{ __('Open') }} {{ $waiting }}{{ __('h') }}
                @endif
            </span>
        </div>
        <div class="stack-actions">
            @if (canDo('complaint.view'))
                <a href="{{ route('admin.complaint.show', $row->id) }}" class="btn btn-sm action-btn action-view"
                    title="{{ __('View') }}" aria-label="{{ __('View') }}">
                    <i class="fa fa-eye"></i>
                </a>
            @endif
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
