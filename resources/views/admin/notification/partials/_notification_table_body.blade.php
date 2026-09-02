@forelse ($logs as $log)
    @php
        $s = $log->status;
        $failed = $s === \App\Modules\Notification\Models\NotificationLog::FAILED;
    @endphp
    {{-- A notification that never arrived is the only row here worth chasing. --}}
    <div class="stack-row {{ $failed ? 'tone-bad' : '' }}">
        <div>
            <span class="row-lead">{{ $log->recipient?->name ?? '—' }}</span>
            <span class="row-sub">{{ $log->recipient?->phone }}</span>
        </div>
        <div>
            <span class="row-main">{{ __($log->event->label()) }}</span>
            <span class="row-sub">{{ $log->channel }}{{ $log->destination ? ' · ' . $log->destination : '' }}</span>
        </div>
        <div>
            <span class="row-main">{{ $log->title }}</span>
            <span class="row-sub">{{ $log->body ? Str::limit($log->body, 90) : '—' }}</span>
        </div>
        <div>
            @if ($s === \App\Modules\Notification\Models\NotificationLog::SENT)
                <span class="status-pill tone-ok">{{ __(ucfirst($s)) }}</span>
            @elseif ($failed)
                <span class="status-pill tone-bad">{{ __(ucfirst($s)) }}</span>
            @else
                <span class="status-pill tone-live">{{ __(ucfirst($s)) }}</span>
            @endif
            <span class="row-sub">{{ $log->failure_reason ?: humanDate($log->created_at) }}</span>
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
