@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            {{ __('Order') }} #{{ $row->code }}
            <span class="status-pill tone-{{ $row->status->tone() }} ms-2">
                {{ __($row->status->label()) }}
            </span>
        </h5>
        <div class="d-flex gap-2">
            {{-- Both were badges wearing button classes: a badge is sized for a
                 count, which is why two of the page's controls were the smallest
                 things on it. --}}
            <a href="{{ route('admin.order.invoice', $row->id) }}" target="_blank" class="btn-quiet">
                <i class="fa fa-file-invoice"></i>{{ __('Invoice') }}
            </a>
            <a href="{{ route('admin.order.index') }}" class="btn-quiet">
                <i class="fa fa-arrow-left"></i>{{ __('Back') }}
            </a>
        </div>
    </div>

    <section class="section">
        <div class="row">
            {{-- Left: what was ordered --}}
            <div class="col-md-8">
                {{-- The laundry's core screen, offered only while the pieces are
                     actually here and waiting to be counted. --}}
                @if (canDo('order.update') && $row->status->isReviewable())
                    @include('admin.order.partials._review_form', ['row' => $row, 'reviewItems' => $reviewItems ?? []])
                @endif

                @if ($row->status->isAwaitingCustomer())
                    <div class="alert alert-info">
                        <i class="fa fa-hourglass-half"></i>
                        <strong>{{ __('Waiting for the customer to confirm the final price.') }}</strong>
                        <div class="small mt-1">
                            {{ __('Cleaning starts once they agree. Nothing is charged at this point.') }}
                        </div>
                    </div>
                @endif

                @include('admin.order.partials._price_queries', ['row' => $row])

                @include('admin.order.partials._tasks', [
                    'row' => $row,
                    'taskCandidates' => $taskCandidates ?? [],
                ])

                <div class="card mb-3">
                    <div class="card-header"><h6 class="mb-0">{{ __('Pieces') }}</h6></div>
                    <div class="card-body">
                        @if ($row->items->isEmpty())
                            <p class="text-muted mb-0">
                                {{ __('No pieces listed — this service is priced after inspection.') }}
                            </p>
                        @else
                            <table class="table table-sm table-borderless">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Item') }}</th>
                                        <th>{{ __('Phase') }}</th>
                                        <th>{{ __('Qty') }}</th>
                                        <th>{{ __('Unit Price') }}</th>
                                        <th>{{ __('Total') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($row->items as $line)
                                        <tr>
                                            <td>{{ $line->item ? getLocalizedValueDashboard($line->item, 'name') : '-' }}</td>
                                            <td>
                                                <span class="badge {{ $line->phase === 'final' ? 'bg-success' : 'bg-light text-dark' }}">
                                                    {{ $line->phase === 'final' ? __('Reviewed') : __('Customer estimate') }}
                                                </span>
                                            </td>
                                            <td>{{ $line->qty }}</td>
                                            <td>{{ moneyFormat($line->unit_price) }}</td>
                                            <td>{{ moneyFormat($line->line_total) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <p class="text-muted small mb-0">
                                {{ __('Prices shown are those agreed when the order was placed, not current prices.') }}
                            </p>
                        @endif
                    </div>
                </div>

                {{-- The audit trail: how the order got where it is --}}
                <div class="card mb-3">
                    <div class="card-header"><h6 class="mb-0">{{ __('History') }}</h6></div>
                    <div class="card-body">
                        @forelse ($row->statusLogs as $log)
                            <div class="d-flex justify-content-between border-bottom py-2">
                                <div>
                                    <strong>{{ __(\App\Modules\Order\Enums\OrderStatus::from($log->to_status)->label()) }}</strong>
                                    @if ($log->from_status === $log->to_status && $log->note)
                                        <span class="text-muted">— {{ $log->note }}</span>
                                    @elseif ($log->note)
                                        <small class="text-muted d-block">{{ $log->note }}</small>
                                    @endif
                                    <small class="text-muted d-block">
                                        {{ __(ucfirst($log->actor_type)) }}{{ $log->actor ? ': '.$log->actor->name : '' }}
                                    </small>
                                </div>
                                <small class="text-muted">{{ humanDate($log->created_at) }}</small>
                            </div>
                        @empty
                            <p class="text-muted mb-0">{{ __('No history yet') }}</p>
                        @endforelse
                    </div>
                </div>

                @if ($row->media->isNotEmpty())
                    <div class="card mb-3">
                        <div class="card-header"><h6 class="mb-0">{{ __('Photos') }}</h6></div>
                        <div class="card-body d-flex flex-wrap gap-2">
                            @foreach ($row->media as $medium)
                                <a href="{{ $medium->url() }}" target="_blank">
                                    <img src="{{ $medium->url() }}" alt="{{ $medium->type }}"
                                        class="rounded border" style="width: 110px; height: 110px; object-fit: cover;">
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Right: who, where, and how much --}}
            <div class="col-md-4">
                <div class="card mb-3">
                    <div class="card-header"><h6 class="mb-0">{{ __('Summary') }}</h6></div>
                    <div class="card-body">
                        <dl class="mb-0">
                            <dt>{{ __('Customer') }}</dt>
                            <dd>{{ $row->customer?->name }}<br><small class="text-muted">{{ $row->customer?->phone }}</small></dd>

                            <dt>{{ __('Service') }}</dt>
                            <dd>{{ $row->service ? getLocalizedValueDashboard($row->service, 'name') : '-' }}</dd>

                            <dt>{{ __('Laundry') }}</dt>
                            <dd>
                                @if ($row->laundry)
                                    {{ getLocalizedValueDashboard($row->laundry, 'name') }}
                                @else
                                    <span class="badge bg-warning text-dark">{{ __('Unassigned') }}</span>
                                @endif
                            </dd>

                            <dt>{{ __('Pickup') }}</dt>
                            <dd>
                                {{ $row->pickup_date ? humanDate($row->pickup_date, 'Y-m-d') : '-' }}
                                <small class="text-muted d-block">{{ $row->pickupSlot?->label() }}</small>
                                <small class="text-muted d-block">{{ $row->pickupAddress?->street }}</small>
                            </dd>

                            <dt>{{ __('Delivery') }}</dt>
                            <dd>
                                {{ $row->delivery_date ? humanDate($row->delivery_date, 'Y-m-d') : '-' }}
                                <small class="text-muted d-block">{{ $row->deliverySlot?->label() }}</small>
                                @unless ($row->isRoundTrip())
                                    <small class="text-warning d-block">
                                        {{ __('Different address') }} — {{ $row->deliveryAddress?->street }}
                                    </small>
                                @endunless
                            </dd>

                            {{-- Both legs. Whoever is dispatching needs to know
                                 the customer wants the bag taken in person and
                                 the clean clothes left at the door — that is two
                                 different instructions to two drivers. --}}
                            <dt>{{ __('Handover') }}</dt>
                            <dd>
                                {{ __('Collection') }}:
                                {{ $row->pickup_method === 'leave' ? __('Leave at the door') : __('Hand to the customer') }}
                                <small class="text-muted d-block">
                                    {{ __('Return') }}:
                                    {{ $row->delivery_method === 'leave' ? __('Leave at the door') : __('Hand to the customer') }}
                                </small>
                            </dd>

                            {{-- The address's own standing instruction, which
                                 travels with every order to it, shown before the
                                 note about this one order so a dispatcher reads
                                 them in that order. --}}
                            @if ($row->pickupAddress?->driver_note)
                                <dt>{{ __('Note on the address') }}</dt>
                                <dd>{{ $row->pickupAddress->driver_note }}</dd>
                            @endif

                            @if ($row->driver_note)
                                <dt>{{ __('Note to driver') }}</dt>
                                <dd>{{ $row->driver_note }}</dd>
                            @endif

                            {{-- Which «عروض متميزة» card won this order. The
                                 whole point of recording it is that somebody can
                                 ask whether a card ever sold anything. --}}
                            @if ($row->offer)
                                <dt>{{ __('Came from offer') }}</dt>
                                <dd>
                                    @if (canDo('offer.view'))
                                        <a href="{{ route('admin.offer.show', $row->offer->id) }}">
                                            {{ getLocalizedValueDashboard($row->offer, 'title') ?: '—' }}
                                        </a>
                                    @else
                                        {{ getLocalizedValueDashboard($row->offer, 'title') ?: '—' }}
                                    @endif
                                </dd>
                            @endif

                            @if ($row->special_instructions)
                                <dt>{{ __('Special instructions') }}</dt>
                                <dd>{{ $row->special_instructions }}</dd>
                            @endif
                        </dl>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h6 class="mb-0">{{ __('Pricing') }}</h6></div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td>{{ __('Estimated subtotal') }}</td>
                                <td class="text-end">{{ moneyFormat($row->estimated_subtotal) }}</td>
                            </tr>
                            <tr>
                                <td>{{ __('Delivery fee') }}</td>
                                <td class="text-end">{{ moneyFormat($row->delivery_fee) }}</td>
                            </tr>
                            @if ((float) $row->discount_total > 0)
                                <tr class="text-success">
                                    <td>{{ __('Discount') }}</td>
                                    <td class="text-end">- {{ moneyFormat($row->discount_total) }}</td>
                                </tr>
                            @endif
                            <tr class="border-top">
                                <td><strong>{{ __('Estimated total') }}</strong></td>
                                <td class="text-end"><strong>{{ moneyFormat($row->estimated_total) }}</strong></td>
                            </tr>
                            @if ($row->hasFinalPrice())
                                <tr class="border-top text-primary">
                                    <td><strong>{{ __('Final total') }}</strong></td>
                                    <td class="text-end"><strong>{{ moneyFormat($row->final_total) }}</strong></td>
                                </tr>
                                @php $difference = $row->priceDifference(); @endphp
                                <tr>
                                    <td class="text-muted">{{ __('Difference') }}</td>
                                    <td class="text-end {{ $difference > 0 ? 'text-danger' : ($difference < 0 ? 'text-success' : 'text-muted') }}">
                                        {{ $difference > 0 ? '+' : '' }}{{ moneyFormat($difference) }}
                                    </td>
                                </tr>
                            @endif
                            @if ($row->confirmed_at)
                                <tr>
                                    <td class="text-muted">{{ __('Confirmed by customer') }}</td>
                                    <td class="text-end text-muted">{{ humanDate($row->confirmed_at) }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td class="text-muted">{{ __('Payment') }}</td>
                                <td class="text-end">
                                    <span class="badge {{ $row->payment_status === 'paid' ? 'bg-success' : 'bg-secondary' }}">
                                        {{ __(ucfirst($row->payment_status)) }}
                                    </span>
                                    @if ($row->payment_method)
                                        <small class="d-block text-muted">
                                            {{ __(ucfirst($row->payment_method)) }}
                                        </small>
                                    @endif
                                </td>
                            </tr>
                            @foreach ($row->payments as $payment)
                                <tr>
                                    <td class="text-muted small">
                                        {{ __($payment->method->label()) }}
                                        @if ($payment->provider_reference)
                                            <small class="d-block">{{ $payment->provider_reference }}</small>
                                        @endif
                                        @if ($payment->failure_reason)
                                            <small class="d-block text-danger">{{ $payment->failure_reason }}</small>
                                        @endif
                                    </td>
                                    <td class="text-end small">
                                        <span class="badge {{ $payment->status->isSettled() ? 'bg-success' : ($payment->status->isOpen() ? 'bg-info' : 'bg-secondary') }}">
                                            {{ __($payment->status->label()) }}
                                        </span>
                                        <small class="d-block text-muted">{{ moneyFormat($payment->amount) }}</small>
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                </div>

                {{-- Assignment, offered only while the order is still assignable and
                     only to laundries that actually cover it. --}}
                @if (canDo('order.update') && ! $row->status->isInCustody())
                    <div class="card">
                        <div class="card-header"><h6 class="mb-0">{{ __('Assign to laundry') }}</h6></div>
                        <div class="card-body">
                            @if (empty($assignable))
                                <p class="text-muted small mb-0">
                                    {{ __('No laundry covers this zone and offers this service. Extend a laundry\'s areas or services first.') }}
                                </p>
                            @else
                                <form method="POST" action="{{ route('admin.order.assign', $row->id) }}">
                                    @csrf
                                    @method('PUT')
                                    <select name="laundry_id" class="form-select mb-2" required>
                                        <option value="">{{ __('Choose a laundry') }}</option>
                                        @foreach ($assignable as $candidate)
                                            <option value="{{ $candidate->id }}"
                                                @selected($row->laundry_id === $candidate->id)>
                                                {{ getLocalizedValueDashboard($candidate, 'name') }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                        {{ __('Assign') }}
                                    </button>
                                    <small class="text-muted d-block mt-2">
                                        {{ __('Assigning recalculates the delivery fee from the laundry\'s location.') }}
                                    </small>
                                </form>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
