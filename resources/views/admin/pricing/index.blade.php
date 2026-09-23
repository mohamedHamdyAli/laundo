@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Prices') }}</h5>
        <span class="text-muted small">
            {{ __('One price per item and service. Prices are global — laundries never set them.') }}
            @if ($platformFeeRate > 0)
                <br>
                {{ __('The figure under each box is what the customer pays, with the platform fee of') }}
                {{ rtrim(rtrim(number_format($platformFeeRate, 2), '0'), '.') }}% {{ __('already in it.') }}
            @endif
        </span>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">

                        @include('layouts.validateMessage.errorMessage')

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
                                                                @if ($platformFeeRate > 0)
                                                                    {{-- Named, not a bare figure. A second number under a
                                                                         price box with nothing saying what it is gets read
                                                                         as an old price, a minimum, or a mistake — and the
                                                                         one person who must not guess is the person setting
                                                                         the price. --}}
                                                                    <div class="form-text text-center small price-with-fee"
                                                                        @if ($cell === '') style="visibility: hidden" @endif>
                                                                        <span class="text-muted">{{ __('customer pays') }}</span>
                                                                        <span class="fw-semibold price-with-fee-amount">{{ $cell === '' ? '' : moneyFormat($platformFee->onUnit((float) $cell)) }}</span>
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

        @if ($platformFeeRate > 0)
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

                    const withFee = Math.round(base * (1 + rate / 100) * 100) / 100;

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
