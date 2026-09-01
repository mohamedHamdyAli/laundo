@forelse ($complaints as $row)
    @php
        $state = $row->statusCase();
        $waiting = $row->waitingHours();
    @endphp
    {{-- Open for more than a day. A status column cannot say how long. --}}
    <tr class="{{ $waiting !== null && $waiting > 24 ? 'table-danger' : '' }}">
        <td>
            <a href="{{ route('admin.complaint.show', $row->id) }}">
                <strong>{{ $row->reference }}</strong>
            </a>
            @if ($row->order)
                <small class="text-muted d-block">{{ $row->order->code }}</small>
            @endif
        </td>
        <td>
            {{ $row->complainant?->name ?? '—' }}
            <small class="text-muted d-block">{{ $row->complainant?->phone }}</small>
        </td>
        <td>{{ __($row->categoryCase()->label()) }}</td>
        <td style="max-width: 320px;">
            {{ \Illuminate\Support\Str::limit($row->body, 120) }}
        </td>
        <td>
            @if ($row->laundry)
                {{ getLocalizedValueDashboard($row->laundry, 'name') }}
            @else
                <span class="text-muted">—</span>
            @endif
        </td>
        <td>
            @if ($state === \App\Modules\Complaint\Enums\ComplaintStatus::New)
                <span class="badge bg-danger">{{ __($state->label()) }}</span>
            @elseif ($state === \App\Modules\Complaint\Enums\ComplaintStatus::InProgress)
                <span class="badge bg-info">{{ __($state->label()) }}</span>
            @elseif ($state === \App\Modules\Complaint\Enums\ComplaintStatus::Resolved)
                <span class="badge bg-success">{{ __($state->label()) }}</span>
            @else
                <span class="badge bg-secondary">{{ __($state->label()) }}</span>
            @endif

            @if ($row->handler)
                <small class="text-muted d-block">{{ $row->handler->name }}</small>
            @endif
        </td>
        <td>
            @if ($waiting === null)
                <small class="text-muted">
                    {{ $row->handled_at ? humanDate($row->handled_at, 'Y-m-d H:i') : '—' }}
                </small>
            @else
                <strong class="{{ $waiting > 24 ? 'text-danger' : '' }}">{{ $waiting }}</strong>
                <span class="text-muted">{{ __('h') }}</span>
            @endif
        </td>
        <td class="text-center">
            @if (canDo('complaint.view'))
                <a href="{{ route('admin.complaint.show', $row->id) }}"
                    class="btn btn-sm btn-outline-primary" title="{{ __('View') }}">
                    <i class="fa fa-eye"></i>
                </a>
            @endif
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
