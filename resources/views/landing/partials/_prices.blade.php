{{--
    The published price list.

    A real `<table>` — this is tabular data, and a grid of `<div>`s would lose
    the row/column relationship every screen reader needs. Each `<td>` carries a
    `data-label`, which the stylesheet uses to reflow the table into labelled
    rows under 768px. Ten items across three services does not scroll usefully
    on a phone, so it stacks instead.

    Prices come from `item_prices` through `moneyFormat()`, so Arabic renders
    Western digits — Arabic-Indic numerals in a price is a bug in this project,
    not a locale preference.

    **Payment says cash and only cash.** Card, e-wallet and InstaPay are cases
    in `PaymentMethod` and appear in the app's design, but the only gateway in
    the codebase is `FakeGateway` — none of them can take money today. Cash on
    delivery is settled by the driver on the fourth leg and is real. The
    "coming" line is its own Web File key so it can be rewritten the day a
    gateway is wired up, without touching this file.
--}}
@if ($priceGrid['categories'] !== [])
    @php $columns = count($priceGrid['services']) + 1; @endphp

    <x-landing.section
        id="prices"
        tone="base"
        :eyebrow="webText('landing.prices.eyebrow')"
        :title="webText('landing.prices.title')"
        :lead="webText('landing.prices.lead')"
    >
        <div class="price-wrap" data-reveal>
            <table class="price-table">
                <caption class="sr-only">
                    {{ webText('landing.prices.table_caption', ['currency' => $currency]) }}
                </caption>

                <thead>
                    <tr>
                        <th scope="col">{{ webText('landing.prices.col_item') }}</th>
                        @foreach ($priceGrid['services'] as $service)
                            <th scope="col">{{ $service['name'] }}</th>
                        @endforeach
                    </tr>
                </thead>

                @foreach ($priceGrid['categories'] as $category)
                    <tbody>
                        <tr class="price-group">
                            <th scope="colgroup" colspan="{{ $columns }}">{{ $category['name'] }}</th>
                        </tr>

                        @foreach ($category['items'] as $item)
                            <tr>
                                <th scope="row" data-label="{{ webText('landing.prices.col_item') }}">
                                    {{ $item['name'] }}
                                </th>

                                @foreach ($priceGrid['services'] as $service)
                                    <td data-label="{{ $service['name'] }}">
                                        @if ($item['prices'][$service['id']] !== null)
                                            {{ $item['prices'][$service['id']] }}
                                        @else
                                            <span class="price-none" aria-label="—">&mdash;</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>

        <div class="price-notes">
            <article class="note-card" data-reveal>
                <h3 class="note-title">
                    <x-landing.icon name="truck" :size="18" />
                    {{ webText('landing.prices.delivery_title') }}
                </h3>
                <p>{{ webText('landing.prices.delivery_body') }}</p>
            </article>

            <article class="note-card" data-reveal>
                <h3 class="note-title">
                    <x-landing.icon name="wallet" :size="18" />
                    {{ webText('landing.prices.payment_title') }}
                </h3>
                <p>{{ webText('landing.prices.payment_body') }}</p>
                <p class="note-aside">{{ webText('landing.prices.payment_soon') }}</p>
            </article>
        </div>
    </x-landing.section>
@endif
