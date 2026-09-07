{{--
    The price review, in full — the page's centre of gravity.

    One of only two navy bands on the whole page (the other is the final call to
    action). The colour is doing work here: this is the section the product is
    differentiated by, and it should read as the moment the page slows down.

    The three outcomes are the real ones, straight out of
    `OrderStatus::allowedNext()`: `Reviewed → Confirmed`, `Reviewed →
    ReviewDisputed`, and asking a question — which is a separate endpoint that
    deliberately leaves the order where it is. `Returned` is not listed: it is
    operator-only and terminal, and it is not something a customer chooses.
--}}
<x-landing.section
    id="review"
    tone="navy"
    :eyebrow="webText('landing.review.eyebrow')"
    :title="webText('landing.review.title')"
    :lead="webText('landing.review.lead')"
>
    <div class="review-split">

        <ul class="review-points">
            @foreach ([1 => 'receipt', 2 => 'scales', 3 => 'check'] as $n => $icon)
                <li class="review-point" data-reveal>
                    <span class="review-point-icon" aria-hidden="true">
                        <x-landing.icon :name="$icon" :size="20" />
                    </span>
                    <div>
                        <h3 class="review-point-title">{{ webText("landing.review.item_{$n}_title") }}</h3>
                        <p class="review-point-body">{{ webText("landing.review.item_{$n}_body") }}</p>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="outcomes">
            <article class="outcome outcome--confirm" data-reveal>
                <h3 class="outcome-title">
                    <x-landing.icon name="check" :size="18" />
                    {{ webText('landing.review.confirm_title') }}
                </h3>
                <p>{{ webText('landing.review.confirm_body') }}</p>
            </article>

            <article class="outcome outcome--dispute" data-reveal>
                <h3 class="outcome-title">
                    <x-landing.icon name="recount" :size="18" />
                    {{ webText('landing.review.dispute_title') }}
                </h3>
                <p>{{ webText('landing.review.dispute_body') }}</p>
            </article>

            <article class="outcome outcome--question" data-reveal>
                <h3 class="outcome-title">
                    <x-landing.icon name="question" :size="18" />
                    {{ webText('landing.review.question_title') }}
                </h3>
                <p>{{ webText('landing.review.question_body') }}</p>
            </article>
        </div>

    </div>

    <p class="review-cancel-note" data-reveal>{{ webText('landing.review.cancel_note') }}</p>
</x-landing.section>
