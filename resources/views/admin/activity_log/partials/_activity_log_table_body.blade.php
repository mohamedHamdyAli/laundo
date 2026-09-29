{{--
    One row per change, worded for the owner by ActivityPresenter: what happened
    as one sentence, who by their role, where from in words. The arrow opens
    only the fields that mean something, each value as the screens show it.
--}}
@forelse ($rows as $row)
    @php
        $log = $row['log'];
        $detailsId = 'activity-'.$log->id;
        [$icon, $tone] = match ($row['event']) {
            'created' => ['bi-plus-circle', 'text-success'],
            'deleted' => ['bi-trash', 'text-danger'],
            'login' => ['bi-box-arrow-in-right', 'text-primary'],
            'logout' => ['bi-box-arrow-right', 'text-muted'],
            default => ['bi-pencil-square', 'text-warning'],
        };
    @endphp
    <div class="stack-row">
        <div>
            <span class="row-main">{{ humanDate($log->created_at, 'Y-m-d') }}</span>
            <span class="row-sub">{{ humanDate($log->created_at, 'h:i A') }}</span>
        </div>

        <div>
            <span class="row-main">
                <i class="bi {{ $icon }} {{ $tone }} me-1" aria-hidden="true"></i>{{ $row['title'] }}
            </span>
            @if ($log->order_id && canDo('order.view'))
                <span class="row-sub">
                    <a href="{{ route('admin.order.show', $log->order_id) }}">{{ __('Open the order') }}</a>
                </span>
            @endif
        </div>

        <div>
            <span class="row-lead">{{ $row['actor'] }}</span>
            @if ($row['role'])
                <span class="row-sub">{{ $row['role'] }}</span>
            @endif
        </div>

        <div>
            <span class="row-sub">{{ $row['where'] }}</span>
        </div>

        <div class="stack-actions">
            @if ($row['fields'])
                <button type="button" class="btn btn-sm action-btn activity-toggle" data-bs-toggle="collapse"
                    data-bs-target="#{{ $detailsId }}" aria-expanded="false" aria-controls="{{ $detailsId }}"
                    aria-label="{{ __('Details') }}" title="{{ __('Details') }}">
                    <i class="bi bi-chevron-down"></i>
                </button>
            @endif
        </div>

        @if ($row['fields'])
            <div class="collapse activity-details" id="{{ $detailsId }}">
                <div class="table-responsive">
                    <table class="table table-sm small mb-0 mt-2">
                        <thead>
                            <tr>
                                <th style="width: 30%">{{ __('Field') }}</th>
                                @if ($row['event'] === 'updated')
                                    <th>{{ __('Was') }}</th>
                                    <th>{{ __('Became') }}</th>
                                @else
                                    <th>{{ $row['event'] === 'deleted' ? __('Was') : __('Value') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($row['fields'] as $field)
                                <tr>
                                    <td class="text-muted">{{ $field['label'] }}</td>
                                    @if ($row['event'] === 'updated')
                                        <td class="text-break text-muted">{{ $field['old'] }}</td>
                                        <td class="text-break fw-semibold">{{ $field['new'] }}</td>
                                    @else
                                        <td class="text-break">{{ $row['event'] === 'deleted' ? $field['old'] : $field['new'] }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
