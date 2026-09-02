@forelse ($recurrences as $row)
    <div class="stack-row {{ $row->status === 'cancelled' ? 'tone-bad' : '' }}">
        <div>
            <span class="row-lead">{{ $row->customer?->name ?? '—' }}</span>
            <span class="row-sub">{{ $row->customer?->phone }}</span>
        </div>
        <div>
            <span class="row-main">
                {{ $row->service ? getLocalizedValueDashboard($row->service, 'name') : '—' }}
            </span>
            <span class="row-sub">
                {{ __(ucfirst($row->frequency)) }}@if (! is_null($row->day_of_week)) ·
                    {{ \Illuminate\Support\Carbon::now()->startOfWeek()->addDays((int) $row->day_of_week)->translatedFormat('l') }}
                @endif
            </span>
        </div>
        <div>
            @if ($row->prompts_count === 0)
                <span class="row-sub">{{ __('Not asked yet') }}</span>
            @else
                <span class="row-main">{{ $row->answered_prompts_count }}/{{ $row->prompts_count }}</span>
                <span class="row-sub">{{ $row->confirmed_prompts_count }} {{ __('became orders') }}</span>
            @endif
        </div>
        <div>
            @if ($row->status === 'active')
                <span class="status-pill tone-ok">{{ __('Active') }}</span>
            @elseif ($row->status === 'paused')
                <span class="status-pill tone-warn">{{ __('Paused') }}</span>
            @else
                <span class="status-pill tone-bad">{{ __('Cancelled') }}</span>
            @endif
            {{-- Paused and cancelled schedules carry no next date, and showing a
                 stale one would suggest a prompt is still coming. --}}
            <span class="row-sub">
                @if ($row->status === 'active' && $row->next_prompt_on)
                    {{ __('Next') }} {{ $row->next_prompt_on->translatedFormat('d M Y') }}
                @else
                    —
                @endif
            </span>
        </div>
        <div class="stack-actions">
            @if (canDo('order_recurrence.view'))
                <a href="{{ route('admin.recurrence.show', $row->id) }}" class="btn btn-sm action-btn action-view"
                    title="{{ __('View') }}" aria-label="{{ __('View') }}">
                    <i class="fa fa-eye"></i>
                </a>
            @endif

            @if (canDo('order_recurrence.update') && $row->status === 'active')
                <form method="POST" action="{{ route('admin.recurrence.pause', $row->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm action-btn action-edit"
                        title="{{ __('Pause') }}" aria-label="{{ __('Pause') }}">
                        <i class="fa fa-pause"></i>
                    </button>
                </form>
            @endif

            @if (canDo('order_recurrence.update') && $row->status === 'paused')
                <form method="POST" action="{{ route('admin.recurrence.resume', $row->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm action-btn action-view"
                        title="{{ __('Resume') }}" aria-label="{{ __('Resume') }}">
                        <i class="fa fa-play"></i>
                    </button>
                </form>
            @endif

            @if (canDo('order_recurrence.delete') && $row->status !== 'cancelled')
                {{-- Cancelling is final: the customer has to create the schedule
                     again, so it asks first. --}}
                <form method="POST" action="{{ route('admin.recurrence.cancel', $row->id) }}" class="d-inline"
                    onsubmit="return confirm(@js(__('Cancel this schedule for good? The customer would have to set it up again.')));">
                    @csrf
                    <button type="submit" class="btn btn-sm action-btn action-delete"
                        title="{{ __('Cancel') }}" aria-label="{{ __('Cancel') }}">
                        <i class="fa fa-times"></i>
                    </button>
                </form>
            @endif
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
