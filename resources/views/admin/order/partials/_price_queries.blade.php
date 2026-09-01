{{--
    «لدي استفسار عن السعر».

    A question does not move the order, which is exactly why it is easy to lose —
    so it gets its own panel rather than a line in the history nobody filters.
--}}
@if ($row->priceQueries->isNotEmpty())
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">{{ __('Questions about the price') }}</h6>
            @php $open = $row->priceQueries->whereNull('answered_at')->count(); @endphp
            @if ($open > 0)
                <span class="badge bg-danger">{{ $open }} {{ __('unanswered') }}</span>
            @endif
        </div>
        <div class="card-body">
            @foreach ($row->priceQueries as $query)
                <div class="border-bottom pb-3 mb-3">
                    <div class="d-flex justify-content-between">
                        <strong>{{ $query->customer?->name ?? __('Customer') }}</strong>
                        <small class="text-muted">{{ humanDate($query->created_at) }}</small>
                    </div>
                    <p class="mb-2">{{ $query->message }}</p>

                    @if ($query->isAnswered())
                        <div class="alert alert-success py-2 mb-0">
                            <small class="d-block text-muted">
                                {{ __('Answered') }} — {{ humanDate($query->answered_at) }}
                                {{ $query->responder ? '· '.$query->responder->name : '' }}
                            </small>
                            {{ $query->answer }}
                        </div>
                    @elseif (canDo('order.update'))
                        <form method="POST" action="{{ route('admin.order.query.answer', $row->id) }}">
                            @csrf
                            <input type="hidden" name="query_id" value="{{ $query->id }}">
                            <div class="input-group input-group-sm">
                                <input type="text" name="answer" class="form-control"
                                    placeholder="{{ __('Answer the customer...') }}" required>
                                <button type="submit" class="btn btn-outline-primary">{{ __('Send') }}</button>
                            </div>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif
