{{--
    Serviced areas and collection windows.

    The zone list is real configuration — 25 areas an operator has declared
    across Cairo and Giza, each with its own delivery rate — and it is what
    decides whether an address can order at all. Rendered as text rather than a
    map on purpose: Leaflet is vendored locally but its tiles are not, so a map
    would add third-party requests to the one page whose paint time matters, and
    a list of district names is indexable while a canvas is not.

    What is deliberately **not** here: any count of partner laundries. There are
    two rows in `laundries` and both are fixtures.

    Cities with no active zone are dropped upstream — 27 governorates are seeded
    and only two of them have coverage, so a full list would promise 25 areas
    the platform cannot serve.
--}}
@if ($coverage !== [])
    @php
        // Resolved once, here, rather than as a ternary inside an attribute.
        // Same order of preference the primary CTA uses.
        $contactHref = match (true) {
            isset($contact['whatsapp']) => $contact['whatsapp'],
            isset($contact['phone']) => 'tel:'.preg_replace('/[^\d+]/', '', $contact['phone']),
            isset($contact['email']) => 'mailto:'.$contact['email'],
            default => null,
        };
    @endphp

    <x-landing.section
        id="coverage"
        tone="raised"
        :eyebrow="webText('landing.coverage.eyebrow')"
        :title="webText('landing.coverage.title')"
        :lead="webText('landing.coverage.lead')"
    >
        <div class="coverage-layout">

            <div class="coverage-cities">
                @foreach ($coverage as $city)
                    <section class="coverage-city" data-reveal>
                        <h3 class="coverage-city-name">
                            <x-landing.icon name="pin" :size="17" />
                            {{ $city['city'] }}
                            <span class="coverage-count">{{ count($city['zones']) }}</span>
                        </h3>
                        <ul class="coverage-zones">
                            @foreach ($city['zones'] as $zone)
                                <li>{{ $zone }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>

            <aside class="coverage-aside">
                @if ($slots !== [])
                    <div class="note-card" data-reveal>
                        <h3 class="note-title">
                            <x-landing.icon name="clock" :size="18" />
                            {{ webText('landing.coverage.windows_title') }}
                        </h3>
                        <ul class="slot-list">
                            @foreach ($slots as $slot)
                                <li>{{ $slot }}</li>
                            @endforeach
                        </ul>
                        <p class="note-aside">{{ webText('landing.coverage.windows_body') }}</p>
                    </div>
                @endif

                <div class="note-card" data-reveal>
                    <h3 class="note-title">
                        <x-landing.icon name="question" :size="18" />
                        {{ webText('landing.coverage.outside_title') }}
                    </h3>
                    <p>{{ webText('landing.coverage.outside_body') }}</p>

                    @if ($contactHref !== null)
                        <x-landing.cta :href="$contactHref" variant="quiet" icon="arrow">
                            {{ webText('landing.footer.contact_title') }}
                        </x-landing.cta>
                    @endif
                </div>
            </aside>

        </div>
    </x-landing.section>
@endif
