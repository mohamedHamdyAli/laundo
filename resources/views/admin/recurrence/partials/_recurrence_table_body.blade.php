@forelse ($recurrences as $row)
    <tr>
        <td>
            {{ $row->customer?->name ?? '—' }}
            <small class="text-muted d-block">{{ $row->customer?->phone }}</small>
        </td>
        <td>
            {{ $row->service ? getLocalizedValueDashboard($row->service, 'name') : '—' }}
        </td>
        <td>
            {{ __(ucfirst($row->frequency)) }}
            @if (! is_null($row->day_of_week))
                <small class="text-muted d-block">
                    {{ \Illuminate\Support\Carbon::now()->startOfWeek()->addDays((int) $row->day_of_week)->translatedFormat('l') }}
                </small>
            @endif
        </td>
        <td>
            @if ($row->status === 'active' && $row->next_prompt_on)
                {{ $row->next_prompt_on->translatedFormat('d M Y') }}
            @else
                {{-- Paused and cancelled schedules carry no next date, and showing
                     a stale one would suggest a prompt is still coming. --}}
                <span class="text-muted">—</span>
            @endif
        </td>
        <td>
            @if ($row->prompts_count === 0)
                <span class="text-muted">{{ __('Not asked yet') }}</span>
            @else
                <strong>{{ $row->answered_prompts_count }}</strong>/{{ $row->prompts_count }}
                <small class="text-muted d-block">
                    {{ $row->confirmed_prompts_count }} {{ __('became orders') }}
                </small>
            @endif
        </td>
        <td>
            @if ($row->status === 'active')
                <span class="badge bg-success">{{ __('Active') }}</span>
            @elseif ($row->status === 'paused')
                <span class="badge bg-warning">{{ __('Paused') }}</span>
            @else
                <span class="badge bg-secondary">{{ __('Cancelled') }}</span>
            @endif
        </td>
        <td class="text-center">
            <div class="d-inline-flex gap-1">
                @if (canDo('order_recurrence.view'))
                    <a href="{{ route('admin.recurrence.show', $row->id) }}"
                        class="btn btn-sm btn-outline-primary" title="{{ __('View') }}">
                        <i class="fa fa-eye"></i>
                    </a>
                @endif

                @if (canDo('order_recurrence.update') && $row->status === 'active')
                    <form method="POST" action="{{ route('admin.recurrence.pause', $row->id) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-warning" title="{{ __('Pause') }}">
                            <i class="fa fa-pause"></i>
                        </button>
                    </form>
                @endif

                @if (canDo('order_recurrence.update') && $row->status === 'paused')
                    <form method="POST" action="{{ route('admin.recurrence.resume', $row->id) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-success" title="{{ __('Resume') }}">
                            <i class="fa fa-play"></i>
                        </button>
                    </form>
                @endif

                @if (canDo('order_recurrence.delete') && $row->status !== 'cancelled')
                    {{-- Cancelling is final: the customer has to create the
                         schedule again, so it asks first. --}}
                    <form method="POST" action="{{ route('admin.recurrence.cancel', $row->id) }}" class="d-inline"
                        onsubmit="return confirm(@js(__('Cancel this schedule for good? The customer would have to set it up again.')));">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="{{ __('Cancel') }}">
                            <i class="fa fa-times"></i>
                        </button>
                    </form>
                @endif
            </div>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="7" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
