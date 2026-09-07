{{--
    «عروض متميزة» — live offers, from the dashboard.

    `Offer::live()` is the model's own scope, so a seasonal offer outside its
    window is no more published here than in the app.

    **The badge is withheld when the linked coupon looks like a test code.**
    `Offer::badge()` renders the coupon's discount, and the only offer on this
    install links to `SMOKE10` — publishing that unguarded would advertise a
    smoke-test coupon on the front page as a discount a customer could try. The
    offer's own copy is real marketing text an operator wrote and still renders;
    only the number is refused. See `looksLikeTestCode()`.

    The section removes itself when nothing is live, which is the normal state
    between campaigns.
--}}
@if ($offers !== [])
    <x-landing.section
        id="offers"
        tone="raised"
        :eyebrow="webText('landing.offers.eyebrow')"
        :title="webText('landing.offers.title')"
    >
        <ul class="offer-grid">
            @foreach ($offers as $offer)
                <li class="offer-card" data-reveal>
                    @if ($offer['image'])
                        <img
                            class="offer-img"
                            src="{{ $offer['image'] }}"
                            alt=""
                            loading="lazy"
                            decoding="async">
                    @endif

                    <div class="offer-body">
                        @if ($offer['badge'])
                            <span class="offer-badge">{{ $offer['badge'] }}</span>
                        @endif

                        <h3 class="offer-title">{{ $offer['title'] }}</h3>

                        @if ($offer['description'])
                            <p class="offer-desc">{{ $offer['description'] }}</p>
                        @endif

                        @if ($offer['ends_at'])
                            <p class="offer-ends">
                                <x-landing.icon name="clock" :size="15" />
                                {{ webText('landing.offers.ends', ['date' => $offer['ends_at']]) }}
                            </p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </x-landing.section>
@endif
