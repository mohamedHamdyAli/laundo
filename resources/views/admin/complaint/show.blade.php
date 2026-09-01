@extends('layouts.main')

@section('content')
    @php $state = $row->statusCase(); @endphp

    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            {{ __('Complaint') }} {{ $row->reference }}
        </h5>
        <a href="{{ route('admin.complaint.index') }}" class="btn btn-sm btn-outline-secondary">
            {{ __('Back') }}
        </a>
    </div>

    <section class="section">
        <div class="row mb-3">
            <div class="col-md-8">
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">{{ __($row->categoryCase()->label()) }}</h6>
                        <span class="badge {{ $state->isOpen() ? 'bg-danger' : 'bg-success' }}">
                            {{ __($state->label()) }}
                        </span>
                    </div>
                    <div class="card-body">
                        <p class="mb-0" style="white-space: pre-wrap;">{{ $row->body }}</p>

                        @if ($row->attachments->isNotEmpty())
                            {{-- «المرفقات». For most of these complaints the
                                 photograph is the evidence, and a stain described
                                 in words is a phone call. --}}
                            <hr>
                            <h6 class="text-muted mb-2">
                                {{ __('Attachments') }} ({{ $row->attachments->count() }})
                            </h6>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach ($row->attachments as $attachment)
                                    <a href="{{ $attachment->url() }}" target="_blank" rel="noopener">
                                        <img src="{{ $attachment->url() }}" alt=""
                                            class="rounded border"
                                            style="width: 110px; height: 110px; object-fit: cover;">
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h6 class="mb-0">{{ __('Who and what') }}</h6></div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless mb-0">
                            <tbody>
                                <tr>
                                    <td class="text-muted" style="width: 35%;">{{ __('From') }}</td>
                                    <td>
                                        {{ $row->complainant?->name ?? '—' }}
                                        <small class="text-muted d-block">
                                            {{ $row->complainant?->phone }}
                                            @if ($row->complainant?->email)
                                                · {{ $row->complainant->email }}
                                            @endif
                                        </small>
                                        {{-- The phone number is the point: operations
                                             answers by calling, so it is the first
                                             thing this screen has to hand over. --}}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Order') }}</td>
                                    <td>
                                        @if ($row->order)
                                            <a href="{{ route('admin.order.show', $row->order->id) }}">
                                                {{ $row->order->code }}
                                            </a>
                                        @else
                                            <span class="text-muted">{{ __('Not about a specific order') }}</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Laundry') }}</td>
                                    <td>
                                        @if ($row->laundry)
                                            {{ getLocalizedValueDashboard($row->laundry, 'name') }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Received') }}</td>
                                    <td>
                                        {{ humanDate($row->created_at, 'Y-m-d H:i') }}
                                        @if ($row->waitingHours() !== null)
                                            <small class="d-block {{ $row->waitingHours() > 24 ? 'text-danger' : 'text-muted' }}">
                                                {{ __('Waiting') }} {{ $row->waitingHours() }} {{ __('h') }}
                                            </small>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Handled by') }}</td>
                                    <td>
                                        @if ($row->handler)
                                            {{ $row->handler->name }}
                                            @if ($row->handled_at)
                                                <small class="text-muted d-block">
                                                    {{ humanDate($row->handled_at, 'Y-m-d H:i') }}
                                                </small>
                                            @endif
                                        @else
                                            <span class="text-muted">{{ __('Nobody yet') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                @if (canDo('complaint.update'))
                    <div class="card mb-3">
                        <div class="card-header"><h6 class="mb-0">{{ __('Move it along') }}</h6></div>
                        <div class="card-body">
                            @if (count($nextStatuses) === 0)
                                <span class="text-muted small">{{ __('Nothing further to do.') }}</span>
                            @else
                                <form method="POST" action="{{ route('admin.complaint.transition', $row->id) }}">
                                    @csrf
                                    <div class="mb-2">
                                        <label class="form-label small">{{ __('New status') }}</label>
                                        {{-- Only the moves this status allows, so the
                                             screen cannot offer one the service
                                             will refuse. --}}
                                        <select name="status" class="form-select form-select-sm" required>
                                            @foreach ($nextStatuses as $next)
                                                <option value="{{ $next->value }}">{{ __($next->label()) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small">{{ __('Internal note') }}</label>
                                        <textarea name="note" class="form-control form-control-sm" rows="3"
                                            placeholder="{{ __('What was said on the call...') }}"></textarea>
                                    </div>
                                    <button class="btn btn-primary btn-sm w-100">{{ __('Save') }}</button>
                                </form>
                            @endif
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h6 class="mb-0">{{ __('Add a note only') }}</h6></div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.complaint.note', $row->id) }}">
                                @csrf
                                <textarea name="note" class="form-control form-control-sm mb-2" rows="3"
                                    placeholder="{{ __('What was said on the call...') }}" required></textarea>
                                <button class="btn btn-outline-secondary btn-sm w-100">{{ __('Add note') }}</button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">{{ __('Internal notes') }}</h6>
                {{-- Never sent to the complainant. This is where what was actually
                     said on the phone gets written down, and two people working the
                     same complaint over a week both need to read it. --}}
                <small class="text-muted">{{ __('The customer never sees these') }}</small>
            </div>
            <div class="card-body">
                @if (filled($row->internal_note))
                    <p class="mb-0" style="white-space: pre-wrap;">{{ $row->internal_note }}</p>
                @else
                    <span class="text-muted">{{ __('Nothing written yet.') }}</span>
                @endif
            </div>
        </div>
    </section>
@endsection
