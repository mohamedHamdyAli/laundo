{{--
    The price-review card — the one visual the page is built around.

    A **stylised diagram, not a screenshot.** No phone chrome, no status bar, no
    invented app UI: the actual app has no exportable screens yet (the seven
    images in the database are still Figma placeholders — all three journey-step
    uploads are literally the same 1,132-byte file), and dressing a mock up as a
    real screen would be a claim about software nobody outside the team has seen.
    So it reads as a document: the count, the price, and the two things you can
    do about it.

    **Every number is computed.** `LandingContentService::reviewExample()` prices
    a three-item basket off real `item_prices` rows and takes the delivery floor
    from the cheapest active zone, so the arithmetic is genuinely correct and
    moves when somebody edits a price in the dashboard. The laundry finds one
    extra piece and the total goes *up* — a demonstration where the price only
    ever falls is not one anybody believes.

    The two actions are `<span>`s, not `<button>`s. They illustrate a decision
    made in the app; putting non-functional controls in the keyboard tab order
    would promise something this page cannot do.
--}}
@if ($review !== null)
    <figure class="review-card" data-review-card>

        <figcaption class="review-head">
            <span class="review-head-main">
                <span class="review-title">{{ webText('landing.hero.card_title') }}</span>
                <span class="review-service">{{ $review['service'] }}</span>
            </span>
            <span class="review-qr" title="{{ webText('landing.hero.card_qr_label') }}">
                <x-landing.icon name="qr" :size="18" />
            </span>
        </figcaption>

        <div class="review-compare">

            <div class="review-line review-line--estimate">
                <span class="review-line-label">{{ webText('landing.hero.card_estimate_label') }}</span>
                <span class="review-line-pieces">
                    {{ webText('landing.hero.card_pieces', ['count' => $review['estimate']['pieces']]) }}
                </span>
                <span class="review-line-total">{{ $review['estimate']['total_label'] }}</span>
            </div>

            {{--
                `data-from` holds the estimate figures. The markup already
                contains the *final* values, so a reduced-motion visitor — or
                anybody with JS off — sees the finished card. The script only
                winds it back and counts up when motion is allowed.
            --}}
            <div class="review-line review-line--actual"
                 data-count-from="{{ $review['estimate']['pieces'] }}"
                 data-count-to="{{ $review['actual']['pieces'] }}">
                <span class="review-line-label">{{ webText('landing.hero.card_actual_label') }}</span>
                <span class="review-line-pieces" data-review-pieces>
                    {{ webText('landing.hero.card_pieces', ['count' => $review['actual']['pieces']]) }}
                </span>
                <span class="review-line-total">{{ $review['actual']['total_label'] }}</span>
            </div>

            <p class="review-delta" data-review-delta>
                <span class="review-delta-label">{{ webText('landing.hero.card_difference') }}</span>
                <span class="review-delta-value">+&nbsp;{{ $review['difference_label'] }}</span>
            </p>

        </div>

        <details class="review-breakdown">
            <summary>
                <span>{{ webText('landing.hero.card_breakdown_toggle') }}</span>
                <x-landing.icon name="chevron" :size="16" class="ic review-breakdown-chevron" />
            </summary>

            <ul class="review-items">
                @foreach ($review['lines'] as $index => $line)
                    <li>
                        <span class="review-item-name">{{ $line['name'] }}</span>
                        <span class="review-item-qty">&times;{{ $index === 0 ? $line['quantity'] + 1 : $line['quantity'] }}</span>
                        <span class="review-item-total">
                            {{ $index === 0
                                ? moneyFormat($line['unit'] * ($line['quantity'] + 1))
                                : $line['total_label'] }}
                        </span>
                    </li>
                @endforeach

                @if ($review['delivery_label'] !== null)
                    <li class="review-item--delivery">
                        <span class="review-item-name">{{ webText('landing.hero.card_delivery') }}</span>
                        <span class="review-item-qty"></span>
                        <span class="review-item-total">{{ $review['delivery_label'] }}</span>
                    </li>
                @endif

                <li class="review-item--total">
                    <span class="review-item-name">{{ webText('landing.hero.card_total') }}</span>
                    <span class="review-item-qty"></span>
                    <span class="review-item-total">{{ $review['actual']['total_label'] }}</span>
                </li>
            </ul>
        </details>

        {{-- Illustrative, deliberately not focusable. --}}
        <div class="review-actions" role="presentation">
            <span class="review-btn review-btn--confirm">
                <x-landing.icon name="check" :size="17" />
                {{ webText('landing.hero.card_confirm') }}
            </span>
            <span class="review-btn review-btn--dispute">
                <x-landing.icon name="recount" :size="17" />
                {{ webText('landing.hero.card_dispute') }}
            </span>
        </div>

        <p class="review-note">{{ webText('landing.hero.card_note') }}</p>
        <p class="review-example-note">{{ webText('landing.hero.card_example_note') }}</p>

    </figure>
@endif
