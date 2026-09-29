@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Prices') }}</h5>
        <span class="text-muted small">
            {{ __('One price per item and service. Prices are global — laundries never set them.') }}
            @if ($platformFeeRate > 0)
                <br>
                {{ __('The figure under each box is the price after the platform fee of') }}
                {{ rtrim(rtrim(number_format($platformFeeRate, 2), '0'), '.') }}%
                — {{ __('the box itself is what the laundry is owed.') }}
            @endif
        </span>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">

                        @include('layouts.validateMessage.errorMessage')

                        {{-- Excel, one price per row. Outside the grid's form for
                             the same reason as the rise below: the import modal is
                             a form of its own, and a form nested in the grid's
                             would break the grid's save. No search box — the
                             grid's filter is client-side and fetches nothing. --}}
                        @if (canDo('item_price.view'))
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                                <x-spreadsheet-actions sheet="item_price" />
                            </div>
                        @endif

                        {{-- «زيادة الأسعار». Its own forms, deliberately outside the
                             grid's: the grid is one bulk form that posts every cell,
                             and a button nested inside it would submit the whole
                             price list along with the rise. --}}
                        @if (canDo('item_price.update'))
                            <div class="border rounded p-3 mb-3">
                                <h6 class="mb-1">{{ __('Price increase') }}</h6>
                                <p class="text-muted small mb-2">
                                    {{ __('Raises every piece price by a percentage. For a period, the prices below stay as they are and the rise is added on top until you take it off or it reaches its end date. Make it permanent and the rise is written into the prices themselves and this box goes back to zero.') }}
                                    {{ __('Orders already placed keep the prices they were placed at.') }}
                                </p>

                                @if ($priceIncreaseRate > 0)
                                    <div class="alert alert-info small py-2 mb-2">
                                        {{ __('In force now: +:rate% on every piece price.', ['rate' => rtrim(rtrim(number_format($priceIncreaseRate, 2), '0'), '.')]) }}
                                        @if ($priceIncreaseEndsAt)
                                            {{ __('Ends :date.', ['date' => humanDate($priceIncreaseEndsAt, 'Y-m-d H:i')]) }}
                                        @else
                                            {{ __('No end date — it stays until you take it off.') }}
                                        @endif
                                    </div>
                                @endif

                                <form method="POST" action="{{ route('admin.pricing.increase') }}" class="row g-2 align-items-end" id="price-increase-form">
                                    @csrf
                                    <div class="col-sm-3">
                                        <label class="form-label small" for="price-increase-rate">{{ __('Increase') }}</label>
                                        <div class="input-group">
                                            <input type="number" step="0.01" min="0" max="100" name="rate" id="price-increase-rate"
                                                class="form-control" value="{{ $priceIncreaseRate > 0 ? $priceIncreaseRate : '' }}" placeholder="10">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    <div class="col-sm-4">
                                        <label class="form-label small" for="price-increase-ends">{{ __('Ends (optional, period only)') }}</label>
                                        <input type="datetime-local" name="ends_at" id="price-increase-ends" class="form-control"
                                            value="{{ $priceIncreaseEndsAt ? $priceIncreaseEndsAt->copy()->setTimezone(displayTimezone())->format('Y-m-d\TH:i') : '' }}">
                                    </div>
                                    <div class="col-sm-5 d-flex gap-2 flex-wrap">
                                        <button type="submit" name="mode" value="period" class="btn btn-outline-primary">
                                            {{ __('Apply for a period') }}
                                        </button>
                                        {{-- Cannot be undone from any screen, so it asks first. --}}
                                        <button type="submit" name="mode" value="permanent" class="btn btn-primary"
                                            onclick="return confirm(@json(__('Write this increase into every price for good? This cannot be undone from the panel.')))">
                                            {{ __('Make permanent') }}
                                        </button>
                                        @if ($priceIncreaseRate > 0)
                                            <button type="submit" name="mode" value="remove" class="btn btn-outline-danger">
                                                {{ __('Take it off') }}
                                            </button>
                                        @endif
                                    </div>
                                </form>

                                {{-- «الهيستوري». Every rise, newest first. A permanent one can be
                                     undone — the latest standing one only, and only for prices
                                     nobody has corrected by hand since. --}}
                                @if ($priceHistory->isNotEmpty())
                                    <div class="mt-3">
                                        <h6 class="mb-2">{{ __('History') }}</h6>
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>{{ __('Date') }}</th>
                                                        <th>{{ __('Increase') }}</th>
                                                        <th>{{ __('Type') }}</th>
                                                        <th>{{ __('By') }}</th>
                                                        <th>{{ __('Status') }}</th>
                                                        <th class="text-end">{{ __('Action') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($priceHistory as $change)
                                                        <tr>
                                                            <td>{{ humanDate($change->created_at, 'Y-m-d H:i') }}</td>
                                                            <td class="fw-semibold">+{{ rtrim(rtrim(number_format((float) $change->rate, 2), '0'), '.') }}%</td>
                                                            <td>
                                                                @if ($change->isPermanent())
                                                                    {{ __('Permanent') }}
                                                                    <small class="text-muted d-block">{{ trans_choice(':count price|:count prices', $change->prices_count, ['count' => $change->prices_count]) }}</small>
                                                                @else
                                                                    {{ __('For a period') }}
                                                                    @if ($change->ends_at)
                                                                        <small class="text-muted d-block">{{ __('until :date', ['date' => humanDate($change->ends_at, 'Y-m-d H:i')]) }}</small>
                                                                    @endif
                                                                @endif
                                                            </td>
                                                            <td>{{ $change->appliedBy?->name ?? '—' }}</td>
                                                            <td>
                                                                @if ($change->isUndone())
                                                                    <span class="status-pill tone-bad">{{ __('Undone') }}</span>
                                                                    <small class="text-muted d-block">
                                                                        {{ humanDate($change->undone_at, 'Y-m-d H:i') }}
                                                                        · {{ trans_choice(':count price put back|:count prices put back', $change->restored_count, ['count' => $change->restored_count]) }}
                                                                    </small>
                                                                @elseif ($change->isPermanent())
                                                                    <span class="status-pill tone-ok">{{ __('In the prices') }}</span>
                                                                @elseif ($change->isRunning())
                                                                    <span class="status-pill tone-warn">{{ __('In force') }}</span>
                                                                @else
                                                                    <span class="status-pill">{{ __('Ended') }}</span>
                                                                @endif
                                                            </td>
                                                            <td class="text-end">
                                                                @if ($undoableIncrease && $undoableIncrease->id === $change->id)
                                                                    <form method="POST" action="{{ route('admin.pricing.increase.undo', $change->id) }}" class="d-inline"
                                                                        onsubmit="return confirm(@json(__('Put every price this increase raised back to what it was? Prices changed by hand since are left as they are.')))">
                                                                        @csrf
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Undo') }}</button>
                                                                    </form>
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if ($services->isEmpty())
                            {{-- No per-item service means no columns, so there is no grid to draw. --}}
                            <div class="alert alert-warning mb-0">
                                {{ __('No per-item services yet.') }}
                                @if (canDo('service.create'))
                                    <a href="{{ route('admin.service.create') }}">{{ __('Add a service') }}</a>
                                @endif
                            </div>
                        @elseif ($itemCategories->isEmpty())
                            <div class="alert alert-warning mb-0">
                                {{ __('No items yet.') }}
                                @if (canDo('item.create'))
                                    <a href="{{ route('admin.item.create') }}">{{ __('Add an item') }}</a>
                                @endif
                            </div>
                        @else
                            <form action="{{ route('admin.pricing.update') }}" method="POST">
                                @csrf
                                @method('PUT')

                                <div class="list-toolbar">
                                    <input type="text" id="priceFilterInput" class="form-control list-toolbar-search"
                                        placeholder="{{ __('Filter by item or category...') }}" autocomplete="off">
                                    <span class="text-muted small align-self-center" id="priceFilterInput-count"></span>
                                </div>
                                <div id="priceFilterInput-empty" class="stack-empty" style="display: none">{{ __('No data found') }}</div>

                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle mb-0" id="price-grid">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="min-width: 220px;">{{ __('Item') }}</th>
                                                @foreach ($services as $service)
                                                    <th class="text-center" style="min-width: 130px;">
                                                        {{ getLocalizedValueDashboard($service, 'name') }}
                                                        @php $d = $service->durationLabel(); @endphp
                                                        @if ($d)
                                                            <div class="fw-normal text-muted small">
                                                                {{ $d }}
                                                                {{ __($service->duration_unit === 'day' ? 'days' : 'hours') }}
                                                            </div>
                                                        @endif
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($itemCategories as $category)
                                                @continue($category->items->isEmpty())

                                                <tr class="table-secondary">
                                                    <td colspan="{{ $services->count() + 1 }}" class="fw-semibold">
                                                        {{ getLocalizedValueDashboard($category, 'name') }}
                                                    </td>
                                                </tr>

                                                @foreach ($category->items as $item)
                                                    <tr>
                                                        <td>{{ getLocalizedValueDashboard($item, 'name') }}</td>
                                                        @foreach ($services as $service)
                                                            @php
                                                                $cell = $prices[$item->id . '-' . $service->id] ?? '';
                                                            @endphp
                                                            <td>
                                                                <input type="number" step="0.01" min="0"
                                                                    class="form-control form-control-sm text-center price-cell"
                                                                    name="prices[{{ $item->id }}][{{ $service->id }}]"
                                                                    value="{{ $cell }}"
                                                                    placeholder="—"
                                                                    {{ canDo('item_price.update') ? '' : 'readonly' }}>

                                                                {{-- What the customer is actually charged, under the
                                                                     figure the laundry is owed. The grid edits the base
                                                                     price and the platform's fee is folded in on top, so
                                                                     without this the person setting prices is working in
                                                                     a different currency from the one on the invoice and
                                                                     doing the arithmetic in their head.

                                                                     Drawn only when a fee is set: at zero the two numbers
                                                                     are the same and a second line saying so is noise. --}}
                                                                @if ($platformFeeRate > 0 || $priceIncreaseRate > 0)
                                                                    {{-- Named, not a bare figure. A second number under a
                                                                         price box with nothing saying what it is gets read
                                                                         as an old price, a minimum, or a mistake — and the
                                                                         one person who must not guess is the person setting
                                                                         the price.

                                                                         **The platform's fee only.** The state's tax is
                                                                         deliberately not in here: it is charged on the whole
                                                                         order — delivery and the cash handling fee included
                                                                         — so a per-piece share of it is a figure that
                                                                         appears on no invoice line and would make this
                                                                         number impossible to check against one. What this
                                                                         says is the piece price the customer is quoted. --}}
                                                                    <div class="form-text text-center small price-with-fee"
                                                                        @if ($cell === '') style="visibility: hidden" @endif>
                                                                        <span class="text-muted">{{ $priceIncreaseRate > 0 ? __('the customer pays now') : __('after the platform fee') }}</span>
                                                                        <span class="fw-semibold price-with-fee-amount">{{ $cell === '' ? '' : moneyFormat($platformFee->onUnit($priceIncrease->onBase((float) $cell))) }}</span>
                                                                    </div>
                                                                @endif
                                                            </td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                <div class="form-text mt-2">
                                    {{ __('Leave a cell empty to mean this service is not offered for that item. An empty cell is not a price of zero.') }}
                                </div>

                                @if ($quotedServices->isNotEmpty())
                                    <div class="alert alert-info mt-3 mb-0">
                                        <strong>{{ __('Quoted services are not shown here:') }}</strong>
                                        {{ $quotedServices->map(fn ($s) => getLocalizedValueDashboard($s, 'name'))->implode('، ') }}
                                        — {{ __('they are priced after the pieces are inspected.') }}
                                    </div>
                                @endif

                                @if (canDo('item_price.update'))
                                    <button type="submit" class="btn btn-primary mt-3">{{ __('Save Prices') }}</button>
                                @endif
                            </form>
                        @endif

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        {{-- Filters what is already rendered. These screens post the whole
             grid as one form, so a server-side re-render would blank the
             cells it did not draw. --}}
        setupClientFilter({
            inputSelector: '#priceFilterInput',
            itemSelector: '#price-grid tbody tr:not(.table-secondary)',
            siblingHeadingSelector: '#price-grid tbody tr.table-secondary',
            emptySelector: '#priceFilterInput-empty',
            countSelector: '#priceFilterInput-count',
        });

        @if ($platformFeeRate > 0 || $priceIncreaseRate > 0)
            {{-- Keeps the customer figure true while somebody is typing. A
                 label that only tells the truth after a save is a label an
                 operator stops trusting, and the whole point of it is to be
                 read *before* deciding on a number.

                 Same arithmetic as `PlatformFee::onUnit()`, rounded the same
                 way and to the same two places, because a preview that
                 disagrees with the saved price by a piastre is worse than no
                 preview. --}}
            (function () {
                const rate = {{ $platformFeeRate }};
                // A period rise sits under the fee: it raises the laundry's
                // price first, rounded, and the fee goes on the result — the
                // order `PriceIncrease::onBase()` then `PlatformFee::onUnit()`.
                const increase = {{ $priceIncreaseRate }};

                // `moneyFormat()` decides where the currency sits — «EGP 18.70»
                // here, the other way round in another locale — so the shape is
                // taken from it rather than guessed. Without this the label
                // renders one way from the server and flips the other way on the
                // first keystroke, which reads as a bug in the number.
                const sample = @json(moneyFormat(1));
                const parts = sample.split('1.00');
                const before = parts[0] ?? '';
                const after = parts[1] ?? '';

                function render(input) {
                    const label = input.parentElement.querySelector('.price-with-fee');
                    const amount = label ? label.querySelector('.price-with-fee-amount') : null;

                    if (!label) return;

                    const base = parseFloat(input.value);

                    if (!amount) return;

                    if (input.value === '' || isNaN(base)) {
                        // An empty cell means the service is not offered, which
                        // is not a price of zero — so there is nothing to
                        // preview. Hidden rather than removed so the rows do
                        // not change height as somebody types.
                        label.style.visibility = 'hidden';
                        amount.textContent = '';
                        return;
                    }

                    // Rounded once, exactly as `PlatformFee::onUnit()` rounds
                    // it. A preview that arrives at a different piastre from the
                    // saved price is worse than no preview at all.
                    const raised = Math.round(base * (1 + increase / 100) * 100) / 100;
                    const withFee = Math.round(raised * (1 + rate / 100) * 100) / 100;

                    amount.textContent = before + withFee.toFixed(2) + after;
                    label.style.visibility = 'visible';
                }

                document.querySelectorAll('.price-cell').forEach(function (input) {
                    input.addEventListener('input', function () { render(input); });
                });
            })();
        @endif
    </script>
@endpush
