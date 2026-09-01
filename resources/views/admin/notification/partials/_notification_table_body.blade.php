@forelse ($logs as $log)
    <tr class="{{ $log->status === \App\Modules\Notification\Models\NotificationLog::FAILED ? 'table-danger' : '' }}">
        <td>
            {{ $log->recipient?->name ?? '—' }}
            <small class="text-muted d-block">{{ $log->recipient?->phone }}</small>
        </td>
        <td>{{ __($log->event->label()) }}</td>
        <td>
            <span class="badge bg-light text-dark">{{ $log->channel }}</span>
            @if ($log->destination)
                <small class="text-muted d-block">{{ $log->destination }}</small>
            @endif
        </td>
        <td>
            {{ $log->title }}
            @if ($log->body)
                <small class="text-muted d-block">{{ Str::limit($log->body, 90) }}</small>
            @endif
        </td>
        <td>
            @php $s = $log->status; @endphp
            <span class="badge
                @if ($s === \App\Modules\Notification\Models\NotificationLog::SENT) bg-success
                @elseif ($s === \App\Modules\Notification\Models\NotificationLog::FAILED) bg-danger
                @else bg-secondary @endif">
                {{ __(ucfirst($s)) }}
            </span>
            @if ($log->failure_reason)
                <small class="d-block text-danger">{{ $log->failure_reason }}</small>
            @endif
        </td>
        <td>{{ humanDate($log->created_at) }}</td>
    </tr>
@empty
    <tr>
        <td colspan="6" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
