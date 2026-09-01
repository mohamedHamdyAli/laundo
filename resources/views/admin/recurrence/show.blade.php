@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Repeat Schedule') }} #{{ $row->id }}</h5>
        <a href="{{ route('admin.recurrence.index') }}" class="btn btn-sm btn-outline-secondary">
            {{ __('Back') }}
        </a>
    </div>

    <section class="section">
        <div class="row mb-3">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">{{ __('The schedule') }}</h6></div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless mb-0">
                            <tbody>
                                <tr>
                                    <td class="text-muted" style="width: 40%;">{{ __('Customer') }}</td>
                                    <td>
                                        {{ $row->customer?->name ?? '—' }}
                                        <small class="text-muted d-block">{{ $row->customer?->phone }}</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Service') }}</td>
                                    <td>{{ $row->service ? getLocalizedValueDashboard($row->service, 'name') : '—' }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Frequency') }}</td>
                                    <td>{{ __(ucfirst($row->frequency)) }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Pickup address') }}</td>
                                    <td>{{ $row->pickupAddress?->address ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Time slot') }}</td>
                                    <td>
                                        @if ($row->timeSlot)
                                            {{ $row->timeSlot->from }} – {{ $row->timeSlot->to }}
                                        @else
                                            <span class="text-muted">{{ __('Any') }}</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Next prompt') }}</td>
                                    <td>
                                        @if ($row->status === 'active' && $row->next_prompt_on)
                                            {{ $row->next_prompt_on->translatedFormat('d M Y') }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Items') }}</td>
                                    <td>
                                        {{-- The saved basket. Without it, "why did this order cost that"
                                             has no answer once the order exists. --}}
                                        {{ collect($row->items ?? [])->sum('qty') }} {{ __('pieces') }}
                                        <small class="text-muted d-block">
                                            {{ count($row->items ?? []) }} {{ __('line(s)') }}
                                        </small>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card mb-3">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Answer rate') }}</h6>
                        @if ($answerRate === null)
                            {{-- Never asked and 0% are different claims. --}}
                            <h3 class="mb-0 text-muted">{{ __('Not asked yet') }}</h3>
                        @else
                            <h3 class="mb-0 {{ $answerRate < 50 ? 'text-attention' : '' }}">{{ $answerRate }}%</h3>
                            <small class="text-muted">{{ __('of prompts got an answer') }}</small>
                        @endif
                    </div>
                </div>

                <div class="card">
                    <div class="card-body d-grid gap-2">
                        @if (canDo('order_recurrence.update') && $row->status === 'active')
                            <form method="POST" action="{{ route('admin.recurrence.pause', $row->id) }}">
                                @csrf
                                <button class="btn btn-warning w-100">{{ __('Pause prompts') }}</button>
                            </form>
                        @endif

                        @if (canDo('order_recurrence.update') && $row->status === 'paused')
                            <form method="POST" action="{{ route('admin.recurrence.resume', $row->id) }}">
                                @csrf
                                <button class="btn btn-success w-100">{{ __('Resume prompts') }}</button>
                            </form>
                        @endif

                        @if (canDo('order_recurrence.delete') && $row->status !== 'cancelled')
                            {{-- Final: the customer would have to set it up again, so it asks. --}}
                            <form method="POST" action="{{ route('admin.recurrence.cancel', $row->id) }}"
                                onsubmit="return confirm(@js(__('Cancel this schedule for good? The customer would have to set it up again.')));">
                                @csrf
                                <button class="btn btn-outline-danger w-100">{{ __('Cancel schedule') }}</button>
                            </form>
                        @endif

                        @if ($row->status === 'cancelled')
                            <span class="text-muted small">{{ __('This schedule has ended.') }}</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h6 class="mb-0">{{ __('Every time it has asked') }}</h6></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-borderless table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('For') }}</th>
                                <th>{{ __('Asked') }}</th>
                                <th>{{ __('Answer') }}</th>
                                <th>{{ __('Answered') }}</th>
                                <th>{{ __('Order') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($prompts as $prompt)
                                <tr>
                                    <td>{{ $prompt->prompted_for?->translatedFormat('d M Y') }}</td>
                                    <td>
                                        @if ($prompt->prompted_at)
                                            {{ $prompt->prompted_at->diffForHumans() }}
                                        @else
                                            {{-- Created but never delivered: the prompt exists
                                                 and the customer never saw it. --}}
                                            <span class="badge bg-warning">{{ __('Never sent') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($prompt->answer === 'confirmed')
                                            <span class="badge bg-success">{{ __('Confirmed') }}</span>
                                        @elseif ($prompt->answer === 'declined')
                                            <span class="badge bg-secondary">{{ __('Declined') }}</span>
                                        @else
                                            <span class="badge bg-light">{{ __('No answer') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($prompt->answered_at)
                                            {{ $prompt->answered_at->diffForHumans() }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($prompt->order)
                                            <a href="{{ route('admin.order.show', $prompt->order->id) }}">
                                                {{ $prompt->order->code }}
                                            </a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center">
                                        {{ __('This schedule has never been asked.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">{{ $prompts->links() }}</div>
            </div>
        </div>
    </section>
@endsection
