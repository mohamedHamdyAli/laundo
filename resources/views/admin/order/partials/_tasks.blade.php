{{--
    The four journeys.

    Read-only apart from dispatch: a task is completed in the field with a scan
    and a signature, and letting an operator tick one off from a desk would
    destroy the only proof the handover happened.
--}}
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">{{ __('Transport') }}</h6>
        @if ($row->tasks->isEmpty() && canDo('order.update') && $row->confirmed_at)
            <form method="POST" action="{{ route('admin.order.tasks.generate', $row->id) }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    {{ __('Create the transport tasks') }}
                </button>
            </form>
        @endif
    </div>

    <div class="card-body">
        @if ($row->tasks->isEmpty())
            <p class="text-muted mb-0">
                {{ __('No transport tasks yet — they are created when the customer confirms the final price.') }}
            </p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 40px;">#</th>
                            <th>{{ __('Leg') }}</th>
                            <th>{{ __('Driver') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Due') }}</th>
                            <th>{{ __('Pieces') }}</th>
                            <th class="text-end">{{ __('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($row->tasks as $task)
                            <tr class="{{ $task->isLate() ? 'table-warning' : '' }}">
                                <td>{{ $task->sequence }}</td>
                                <td>
                                    {{ __($task->type->label()) }}
                                    @if ($task->failure_reason)
                                        <small class="d-block text-danger">
                                            {{ __($task->failure_reason->label()) }}
                                            @if ($task->failure_note) — {{ $task->failure_note }} @endif
                                            ({{ $task->attempts }} {{ __('attempts') }})
                                        </small>
                                    @endif
                                    @if ($task->receiver_name)
                                        <small class="d-block text-muted">
                                            {{ __('Received by') }}: {{ $task->receiver_name }}
                                        </small>
                                    @endif
                                    @if ($task->collected_amount !== null)
                                        @php $due = $row->payableTotal(); @endphp
                                        <small class="d-block {{ (float) $task->collected_amount + 0.001 < $due ? 'text-danger fw-bold' : 'text-muted' }}">
                                            {{ __('Collected') }}: {{ moneyFormat($task->collected_amount) }}
                                            @if ((float) $task->collected_amount + 0.001 < $due)
                                                ({{ __('short by') }} {{ moneyFormat($due - (float) $task->collected_amount) }})
                                            @endif
                                        </small>
                                    @endif
                                </td>
                                <td>
                                    @if ($task->driver)
                                        {{ $task->driver->name }}
                                    @else
                                        <span class="badge bg-warning text-dark">{{ __('In the queue') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $task->status->isFinished() ? ($task->status->value === 'failed' ? 'bg-danger' : 'bg-success') : 'bg-info' }}">
                                        {{ __($task->status->label()) }}
                                    </span>
                                    @if ($task->isLate())
                                        <span class="badge bg-warning text-dark">{{ __('Late') }}</span>
                                    @endif
                                    @if ($task->durationMinutes() !== null)
                                        <small class="d-block text-muted">
                                            {{ $task->durationMinutes() }} {{ __('min') }}
                                        </small>
                                    @endif
                                </td>
                                <td>{{ $task->due_at ? humanDate($task->due_at) : '—' }}</td>
                                <td>{{ $task->piece_count ?? '—' }}</td>
                                <td class="text-end">
                                    @if (canDo('order.update') && ! $task->status->isFinished())
                                        @php $eligible = $taskCandidates[$task->id] ?? []; @endphp
                                        @if (! empty($eligible))
                                            <form method="POST" action="{{ route('admin.order.tasks.assign', $task->id) }}"
                                                class="d-flex gap-1 justify-content-end">
                                                @csrf
                                                <select name="driver_id" class="form-select form-select-sm"
                                                    style="max-width: 160px;" required>
                                                    <option value="">{{ __('Choose a driver') }}</option>
                                                    @foreach ($eligible as $candidate)
                                                        <option value="{{ $candidate->id }}"
                                                            @selected($task->driver_id === $candidate->id)>
                                                            {{ $candidate->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                                    {{ __('Assign') }}
                                                </button>
                                            </form>
                                        @else
                                            <small class="text-muted">{{ __('No eligible driver') }}</small>
                                        @endif

                                        @if ($task->driver_id)
                                            <form method="POST" action="{{ route('admin.order.tasks.release', $task->id) }}"
                                                class="mt-1">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-link text-danger p-0">
                                                    {{ __('Return to queue') }}
                                                </button>
                                            </form>
                                        @endif
                                    @endif

                                    @if ($task->signatureUrl())
                                        <a href="{{ $task->signatureUrl() }}" target="_blank"
                                            class="btn btn-sm btn-link p-0 d-block">{{ __('Signature') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
