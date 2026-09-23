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
            {{-- Last in the row on purpose: it is the one control here that
                 cannot be undone, and it should not sit where the eye lands
                 first or next to «Invoice», which is one place along. --}}
            @include('admin.order.shared.controlBut', [
                'row' => $row,
                'blocker' => $deletionBlocker,
                'compact' => true,
            ])
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

                @php $settlement = $row->settlement?->load('lines'); @endphp
                @if ($settlement && canDo('order_settlement.view'))
                    {{-- «التسوية» — who got what. Shown on the order rather than
                         only on its own screen because this is where the question
                         is asked: an operator looking at a disputed order should
                         not have to go and find the settlement that belongs to
                         it. The model is tenant-scoped, so a laundry reading its
                         own order sees its own split and no rate but its own. --}}
                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">{{ __('Settlement') }}</h6>
                            @if ($settlement->status === \App\Modules\Payment\Models\OrderSettlement::SETTLED)
                                <span class="status-pill tone-ok">{{ __('Settled') }}</span>
                            @elseif ($settlement->status === \App\Modules\Payment\Models\OrderSettlement::CANCELLED)
                                <span class="status-pill tone-bad">{{ __('Cancelled') }}</span>
                            @else
                                <span class="status-pill tone-warn">{{ __('Pending') }}</span>
                            @endif
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless mb-0">
                                @if ((float) $settlement->platform_fee_amount > 0)
                                    {{-- Named, because otherwise the card shows a
                                         basis below what the customer paid and
                                         nothing explaining the difference — the
                                         «gap with no name on it» that folding the
                                         fee into the prices exists to avoid. A
                                         laundry owner can open this screen, so
                                         the gap is theirs to ask about. --}}
                                    <tr>
                                        <td class="text-muted">
                                            {{ __('Platform fee') }}
                                            <small>({{ __('paid by the customer, inside the prices') }})</small>
                                        </td>
                                        <td class="text-end text-muted">
                                            {{ moneyFormat($settlement->platform_fee_amount) }}
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <td>{{ __('Basis') }}</td>
                                    <td class="text-end">{{ moneyFormat($settlement->basis) }}</td>
                                </tr>
                                @forelse ($settlement->lines as $line)
                                    {{-- One row per charge. A laundry disputing
                                         its payout is shown the arithmetic, not
                                         a blended figure it cannot reproduce. --}}
                                    <tr>
                                        <td class="ps-3 text-muted">
                                            {{ getLocalizedValueDashboard($line, 'name') }}
                                            <small>({{ $line->explain() }})</small>
                                        </td>
                                        <td class="text-end text-muted">{{ moneyFormat($line->amount) }}</td>
                                    </tr>
                                @empty
                                @endforelse
                                <tr>
                                    <td>
                                        {{ __('Commission') }}
                                        @if ($settlement->lines->count() > 1)
                                            <small class="text-muted">
                                                ({{ rtrim(rtrim(number_format((float) $settlement->commission_rate, 2), '0'), '.') }}%
                                                {{ __('effective') }})
                                            </small>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ moneyFormat($settlement->commission_amount) }}</td>
                                </tr>
                                <tr class="border-top">
                                    <td><strong>{{ __('Laundry share') }}</strong></td>
                                    <td class="text-end">
                                        <strong>{{ moneyFormat($settlement->laundry_amount) }}</strong>
                                    </td>
                                </tr>
                                @if ((float) $settlement->tax_amount > 0)
                                    <tr>
                                        <td class="text-muted">
                                            {{ __('Tax') }}
                                            <small>{{ __('(not divided)') }}</small>
                                        </td>
                                        <td class="text-end text-muted">
                                            {{ moneyFormat($settlement->tax_amount) }}
                                        </td>
                                    </tr>
                                @endif
                            </table>
                        </div>
                    </div>
                @endif

                <div class="card mb-3">
                    <div class="card-header"><h6 class="mb-0">{{ __('Pricing') }}</h6></div>
                    <div class="card-body">
                        {{-- Which price this is, said once and **above** the rows
                             it qualifies, because standing applies to everything
                             under it.

                             It used to be carried by prefixing «Estimated» onto
                             every label and repeating the whole block below as
                             «Final …». That printed the same number twice under
                             two names once the rows became the bill, so the
                             distinction moved here — where it is one line instead
                             of five. --}}
                        @if ($row->hasFinalPrice())
                            <p class="small text-primary mb-2">
                                <strong>{{ __('Final price') }}</strong>
                                <span class="text-muted">{{ __('counted by the laundry') }}</span>
                            </p>
                        @else
                            <p class="small text-muted mb-2">
                                {{ __('Estimated — the final price is set after the pieces are reviewed.') }}
                            </p>
                        @endif

                        <table class="table table-sm table-borderless mb-0">
                            {{-- Rendered from `Order::moneyRows()`, which is
                                 also what the printed invoice renders. The two
                                 used to assemble these rows separately and said
                                 different things about the same order — this
                                 card called it «Estimated subtotal» while the
                                 invoice called it «Subtotal», and only the
                                 invoice carried the before-tax line. --}}
                            @foreach ($row->moneyRows() as $line)
                                <tr @class([
                                    'text-success' => $line['kind'] === 'credit',
                                    'border-top' => in_array($line['kind'], ['subtotal', 'total'], true),
                                ])>
                                    <td>
                                        @if ($line['kind'] === 'total')<strong>@endif
                                        {{ $line['label'] }}
                                        @if ($line['note'])
                                            <small class="text-muted">({{ $line['note'] }})</small>
                                        @endif
                                        @if ($line['kind'] === 'total')</strong>@endif
                                    </td>
                                    <td class="text-end">
                                        @if ($line['kind'] === 'total')<strong>@endif
                                        {{ moneyFormat($line['amount']) }}
                                        @if ($line['kind'] === 'total')</strong>@endif
                                    </td>
                                </tr>
                            @endforeach

                            {{-- What follows is this screen's own job and has no
                                 place on a customer's invoice: the estimate it
                                 started at and how far the review moved it. --}}
                            @if ($row->hasFinalPrice())
                                {{-- Inverted deliberately. The rows above are
                                     now the **bill** — the final figures once the
                                     laundry has counted — so repeating them here
                                     as «Final total» would print the same number
                                     twice under two names. What an operator
                                     still needs is what it *was* and how far the
                                     review moved it, which is the one thing the
                                     bill cannot say about itself. --}}
                                @php $difference = $row->priceDifference(); @endphp
                                <tr class="border-top">
                                    <td class="text-muted">{{ __('Estimated at placement') }}</td>
                                    <td class="text-end text-muted">{{ moneyFormat($row->estimated_total) }}</td>
                                </tr>
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

                {{-- Assignment.
                     Offered only while the order can still be moved, and only
                     to laundries that cover the zone and offer the service.
                     Each candidate is shown with what the assigner measured, so
                     an operator overriding the automatic choice is disagreeing
                     with something they can read rather than with a name. --}}
                {{-- Platform staff only, and `LaundryContext` is the test
                     because it is the project's own definition of «not a
                     tenant»: null means no restriction.

                     `order.update` is not enough. A laundry owner holds it so it
                     can review and price its own orders, and the tenant scope
                     was mistaken for a gate here — it stops an owner seeing
                     other people's orders, never stops them pushing their own
                     onto somebody else. The panel also names every candidate
                     laundry with its distance and how full it is, which is a
                     competitor's book handed to the one party that must not
                     have it. --}}
                @if (canDo('order.update') && \App\Support\LaundryContext::currentId() === null && ! $row->status->isInCustody())
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">{{ __('Assign to laundry') }}</h6>
                            @if ($assignment && $assignment['tolerance_km'] > 0)
                                <span class="badge bg-light text-dark">
                                    {{ __('Balancing within :km km', ['km' => rtrim(rtrim(number_format($assignment['tolerance_km'], 1), '0'), '.')]) }}
                                </span>
                            @endif
                        </div>
                        <div class="card-body">
                            @if (! $assignment || empty($assignment['candidates']))
                                <p class="text-muted small mb-0">
                                    {{ __('No laundry covers this zone and offers this service. Extend a laundry\'s areas or services first.') }}
                                </p>
                            @else
                                @if ($assignment['all_full'])
                                    <div class="alert alert-warning py-2 small">
                                        {{ __('Every laundry here is full for this pickup window.') }}
                                        {{ $assignment['overflow']->label() }}
                                    </div>
                                @endif

                                @php
                                    $panelDriver = $assignment['driver'];
                                    $panelDriverProfile = $panelDriver?->profile;
                                @endphp

                                <p class="text-muted small">
                                    {{ __('Driver for the run to the laundry:') }}
                                    @if ($panelDriver)
                                        <strong>{{ $panelDriver->name ?? __('Unnamed') }}</strong>
                                        @if ($panelDriverProfile?->located_at)
                                            <span>({{ __('last seen :when', ['when' => humanDate($panelDriverProfile->located_at)]) }})</span>
                                        @else
                                            <span>({{ __('never reported a position') }})</span>
                                        @endif
                                    @else
                                        <em>{{ __('not assigned yet') }}</em>
                                    @endif
                                </p>

                                <form method="POST" action="{{ route('admin.order.assign', $row->id) }}">
                                    @csrf
                                    @method('PUT')

                                    @foreach ($assignment['candidates'] as $candidate)
                                        @php
                                            $candLaundry = $candidate['laundry'];
                                            $candLeg = $candidate['leg'];
                                            $candDriverLeg = $assignment['driver_legs'][$candLaundry->id] ?? null;
                                            $candFee = $assignment['fees'][$candLaundry->id] ?? null;
                                        @endphp
                                        <label class="d-block border rounded p-2 mb-2 {{ $candidate['chosen'] ? 'border-primary' : '' }}"
                                            for="laundry-{{ $candLaundry->id }}" style="cursor: pointer">
                                            <div class="d-flex align-items-start gap-2">
                                                <input class="form-check-input mt-1 flex-shrink-0" type="radio"
                                                    name="laundry_id" id="laundry-{{ $candLaundry->id }}"
                                                    value="{{ $candLaundry->id }}"
                                                    @checked($row->laundry_id === $candLaundry->id || (! $row->laundry_id && $candidate['chosen']))>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span class="fw-semibold">
                                                            {{ getLocalizedValueDashboard($candLaundry, 'name') }}
                                                        </span>
                                                        <span>
                                                            @if ($candidate['chosen'])
                                                                <span class="badge bg-primary">{{ __('Recommended') }}</span>
                                                            @endif
                                                            @if ($row->laundry_id === $candLaundry->id)
                                                                <span class="badge bg-success">{{ __('Current') }}</span>
                                                            @endif
                                                            @if ($candidate['full'])
                                                                <span class="badge bg-danger">{{ __('Full') }}</span>
                                                            @endif
                                                        </span>
                                                    </div>

                                                    <div class="small text-muted mt-1">
                                                        {{-- Customer to laundry. --}}
                                                        <div>
                                                            <i class="bi bi-geo-alt"></i>
                                                            @if ($candLeg)
                                                                {{ __(':km km from the customer', ['km' => number_format($candLeg->km, 1)]) }}
                                                                @if ($candLeg->minutes !== null)
                                                                    &middot; {{ __(':n min drive', ['n' => (int) round($candLeg->minutes)]) }}
                                                                @endif
                                                                @if ($candLeg->isEstimate())
                                                                    <span class="badge bg-warning text-dark">{{ __('straight line') }}</span>
                                                                @endif
                                                            @else
                                                                {{ __('Distance unknown - this laundry has no coordinates') }}
                                                            @endif
                                                        </div>

                                                        {{-- Driver to laundry. --}}
                                                        @if ($panelDriver)
                                                            <div>
                                                                <i class="bi bi-truck"></i>
                                                                @if ($candDriverLeg)
                                                                    {{ __(':km km from the driver', ['km' => number_format($candDriverLeg->km, 1)]) }}
                                                                    @if ($candDriverLeg->minutes !== null)
                                                                        &middot; {{ __(':n min drive', ['n' => (int) round($candDriverLeg->minutes)]) }}
                                                                    @endif
                                                                @else
                                                                    {{ __('Driver position unknown') }}
                                                                @endif
                                                            </div>
                                                        @endif

                                                        {{-- Load in the window this customer chose. --}}
                                                        <div>
                                                            <i class="bi bi-speedometer2"></i>
                                                            @if ($candidate['capacity'] === null)
                                                                {{ __(':n booked in this window, no limit set', ['n' => $candidate['booked']]) }}
                                                            @else
                                                                {{ __(':booked of :capacity booked in this window', ['booked' => $candidate['booked'], 'capacity' => $candidate['capacity']]) }}
                                                                &middot; {{ __(':n free', ['n' => $candidate['remaining']]) }}
                                                            @endif
                                                        </div>

                                                        {{-- What this choice would cost. --}}
                                                        @if ($candFee !== null)
                                                            <div>
                                                                <i class="bi bi-cash"></i>
                                                                {{ __('Delivery fee would be :fee', ['fee' => moneyFormat($candFee)]) }}
                                                            </div>
                                                        @endif

                                                        @if ($candidate['reason'])
                                                            <div class="fst-italic">{{ $candidate['reason'] }}</div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </label>
                                    @endforeach

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
