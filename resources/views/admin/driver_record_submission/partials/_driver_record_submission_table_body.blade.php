@forelse ($submissions as $row)
    <div class="stack-row">
        <span data-label="{{ __('Driver') }}">
            <strong>{{ $row->driver?->name ?? __('Unknown') }}</strong>
            @if ($row->driver?->phone)
                <small class="d-block text-muted" dir="ltr">{{ $row->driver->phone }}</small>
            @endif
        </span>

        {{-- What they actually sent, not how many rows the payload has. «3
             fields» tells an operator nothing they can act on; «Licence,
             Insurance» tells them which drawer to open. --}}
        <span data-label="{{ __('Sent') }}">
            @foreach (array_keys($row->payload) as $field)
                <span class="badge text-bg-light">{{ __(Str::headline($field)) }}</span>
            @endforeach
        </span>

        <span data-label="{{ __('Waiting since') }}">
            {{ humanDate($row->created_at) }}
        </span>

        <span data-label="{{ __('Status') }}">
            @if ($row->isPending())
                <span class="badge text-bg-warning">{{ __($row->statusLabel()) }}</span>
            @elseif ($row->status === \App\Modules\Driver\Models\DriverRecordSubmission::APPROVED)
                <span class="badge text-bg-success">{{ __($row->statusLabel()) }}</span>
            @else
                <span class="badge text-bg-secondary">{{ __($row->statusLabel()) }}</span>
            @endif

            @if ($row->reviewer)
                <small class="d-block text-muted">{{ $row->reviewer->name }}</small>
            @endif
        </span>

        <span class="text-end" data-label="{{ __('Action') }}">
            @if (canDo('driver_record_submission.view'))
                <a href="{{ route('admin.driver_record_submission.show', $row->id) }}"
                    class="btn btn-sm btn-outline-primary">
                    {{ $row->isPending() ? __('Review') : __('View') }}
                </a>
            @endif
        </span>
    </div>
@empty
    <div class="stack-empty">{{ __('Nothing is waiting for a review.') }}</div>
@endforelse
