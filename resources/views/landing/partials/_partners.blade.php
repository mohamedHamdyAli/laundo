{{--
    Partner laundries and drivers — the graft from Concept 3, kept small.

    A marketplace with no supply has nothing to sell, and this platform has two
    laundry rows (both fixtures) and drivers who cannot register themselves. So
    the recruitment ask is real and worth a place on the page. What it is not
    worth is the hero: splitting the top of the page three ways would cost the
    customer CTA, and the customer is the primary audience.

    Two cards, after the main call to action, clearly subordinate — one third
    the vertical weight of the section above them.

    Both claims are checkable. A laundry owner's panel really is those four
    screens and really cannot set prices (`LaundryService` is "the whole of a
    laundry's control over the catalogue: it chooses what it offers, never what
    it costs"). A driver really does pick zones and a shift, and
    `driver_earnings` really does store `basis` and `rate` alongside the amount
    so the arithmetic can be shown.

    The links go to whatever contact route is configured. When none is, the
    cards still render — the ask is worth stating even before there is a form
    behind it — but no dead button is drawn.
--}}
@php
    $partnerHref = match (true) {
        isset($contact['email']) => 'mailto:'.$contact['email'],
        isset($contact['whatsapp']) => $contact['whatsapp'],
        isset($contact['phone']) => 'tel:'.preg_replace('/[^\d+]/', '', $contact['phone']),
        default => null,
    };
@endphp

<x-landing.section
    id="partners"
    tone="base"
    :eyebrow="webText('landing.partner.eyebrow')"
    class="partners-band"
>
    <div class="partner-grid">

        <article class="partner-card" data-reveal>
            <span class="partner-icon" aria-hidden="true">
                <x-landing.icon name="building" :size="20" />
            </span>
            <h3 class="partner-title">{{ webText('landing.partner.laundry_title') }}</h3>
            <p class="partner-body">{{ webText('landing.partner.laundry_body') }}</p>

            @if ($partnerHref !== null)
                <x-landing.cta :href="$partnerHref" variant="quiet" icon="arrow">
                    {{ webText('landing.partner.laundry_cta') }}
                </x-landing.cta>
            @endif
        </article>

        <article class="partner-card" data-reveal>
            <span class="partner-icon" aria-hidden="true">
                <x-landing.icon name="truck" :size="20" />
            </span>
            <h3 class="partner-title">{{ webText('landing.partner.driver_title') }}</h3>
            <p class="partner-body">{{ webText('landing.partner.driver_body') }}</p>

            @if ($partnerHref !== null)
                <x-landing.cta :href="$partnerHref" variant="quiet" icon="arrow">
                    {{ webText('landing.partner.driver_cta') }}
                </x-landing.cta>
            @endif
        </article>

    </div>
</x-landing.section>
