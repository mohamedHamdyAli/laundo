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

        {{-- Dispatch re-offers a queued leg every ten minutes, which is right
             for a background sweep and wrong for somebody who has just given a
             driver the zone or raised a cap and wants to know whether it worked.
             Without this they either wait, or assign every leg by hand having
             already done the work that would have let dispatch do it. --}}
        @php
            $waiting = $row->tasks->filter(fn ($t) => $t->driver_id === null && ! $t->status->isFinished());
        @endphp
        @if ($waiting->isNotEmpty() && canDo('order.update'))
            <form method="POST" action="{{ route('admin.order.tasks.dispatch', $row->id) }}">
                @csrf
                <button type="submit" class="btn-quiet">
                    <i class="bi bi-arrow-repeat"></i>{{ __('Try the queue again') }}
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
                                        {{-- The Status column already names this state («Awaiting a driver»).
                                             This column is about the driver, so it says what is true
                                             of the driver rather than repeating the state under a
                                             second name in the same row. --}}
                                        <span class="badge bg-warning text-dark">{{ __('Nobody yet') }}</span>
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
                                        @php $remainingLegs = $row->tasks->reject(fn ($t) => $t->status->isFinished())->count(); @endphp
                                        @php $eligible = $taskCandidates[$task->id] ?? []; @endphp
                                        @if (! empty($eligible))
                                            <form method="POST" action="{{ route('admin.order.tasks.assign', $task->id) }}"
                                                class="d-flex flex-column align-items-end gap-1">
                                                @csrf
                                                <div class="d-flex gap-1 justify-content-end">
                                                    {{-- 13rem, not 15: the panel sits in a 749px column
                                                         and a wider control pushed the whole table into
                                                         horizontal scroll, putting Assign off-screen. The
                                                         load label is trimmed to suit rather than the
                                                         column being sacrificed for it. --}}
                                                    <select name="driver_id" class="form-select form-select-sm"
                                                        style="max-width: 13rem;" required>
                                                        <option value="">{{ __('Choose a driver') }}</option>
                                                        @foreach ($eligible as $candidate)
                                                            @php $load = $driverLoads[$candidate->id] ?? null; @endphp
                                                            {{-- The list arrives sorted least-loaded-first, which was
                                                                 a real decision the dispatcher makes and the operator
                                                                 could not see: two names looked interchangeable when
                                                                 one was a single order off their limit. --}}
                                                            <option value="{{ $candidate->id }}"
                                                                @selected($task->driver_id === $candidate->id)>
                                                                {{ $candidate->name }}@if ($load) —
                                                                    @if ($load['cap'])
                                                                        {{ __(':held of :cap', ['held' => $load['load'], 'cap' => $load['cap']]) }}
                                                                    @else
                                                                        {{ __(':held, no limit', ['held' => $load['load']]) }}
                                                                    @endif
                                                                @endif
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">
                                                        {{ __('Assign') }}
                                                    </button>
                                                </div>

                                                {{-- One driver taking the whole chain is the normal case, and it
                                                     cost four separate submissions. It is also what the capacity
                                                     rule assumes: the cap counts distinct *orders*, so four legs of
                                                     one order are one job in a driver's day. Each remaining leg is
                                                     still checked on its own — the delivery leg can be in a
                                                     different zone — and the message says what was refused. --}}
                                                @if ($remainingLegs > 1)
                                                    <label class="form-check form-check-sm small text-muted mb-0">
                                                        <input class="form-check-input" type="checkbox"
                                                            name="rest_of_order" value="1">
                                                        {{ __('and the other :count legs of this order', ['count' => $remainingLegs - 1]) }}
                                                    </label>
                                                @endif
                                            </form>
                                        @else
                                            {{-- «No eligible driver» on its own covers five unrelated
                                                 situations — nobody serves the area, they all switched
                                                 themselves off, the city does not match, they are all at
                                                 their order limit, or the address never got an area — and
                                                 each has a different remedy. The operator cannot see which
                                                 from an empty dropdown, so the reason is spelled out. --}}
                                            @php $blocker = $taskBlockers[$task->id] ?? null; @endphp
                                            <small class="d-block text-muted">{{ __('No eligible driver') }}</small>
                                            @if ($blocker)
                                                <small class="d-block text-attention" style="max-width: 22rem">
                                                    {{ __($blocker['reason'], $blocker['params']) }}
                                                </small>
                                            @endif
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
