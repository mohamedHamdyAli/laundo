{{--
    The primary call to action.

    The second and last navy band. The colour has been held back all page for
    exactly two moments: the section that explains the price review, and this
    one, which asks.

    The button goes wherever `landingCtaTarget()` resolves to — a store listing,
    a real WhatsApp number, a real phone number, or the price table. Never a
    placeholder: the seeded `http://whatsapp.com/` is refused by
    `isPlaceholderSetting()`, so on this install the button currently jumps to
    the prices rather than promising an app nobody can download yet.
--}}
<section class="band band--navy cta-band" aria-labelledby="cta-title">
    <div class="container cta-inner">
        <h2 class="cta-title" id="cta-title">{{ webText('landing.cta.title') }}</h2>
        <p class="cta-lead">{{ webText('landing.cta.lead') }}</p>

        <div class="cta-actions">
            <x-landing.cta :href="$cta['href']" variant="light" icon="arrow">
                {{ webText('landing.cta.button') }}
            </x-landing.cta>
        </div>

        <p class="cta-note">{{ webText('landing.cta.note') }}</p>
    </div>
</section>
