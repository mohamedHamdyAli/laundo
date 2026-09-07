{{--
    The services, from the `services` table.

    No per-service icons. Services are database rows an operator can add, and an
    icon map keyed on id or name would either go stale or silently fall back to
    a default the moment somebody adds a fifth service — the shape of bug
    `lessons.md` calls "a missing enum case in a branch list". The pricing-mode
    pill and the turnaround are the useful information anyway.

    The Arabic descriptions are the real seeded ones; the English column of that
    JSON is empty on every row, so `getLocalizedValue()`'s fallback chain hands
    an English reader the Arabic rather than a blank card. That is the correct
    behaviour and worth knowing when the English copy looks unexpectedly Arabic.
--}}
@if ($services !== [])
    <x-landing.section
        id="services"
        tone="raised"
        :eyebrow="webText('landing.services.eyebrow')"
        :title="webText('landing.services.title')"
        :lead="webText('landing.services.lead')"
    >
        <ul class="service-grid">
            @foreach ($services as $service)
                <li class="service-card" data-reveal>
                    <h3 class="service-name">{{ $service['name'] }}</h3>

                    @if ($service['description'])
                        <p class="service-desc">{{ $service['description'] }}</p>
                    @endif

                    <dl class="service-meta">
                        @if ($service['duration'])
                            <div class="service-meta-row">
                                <dt class="sr-only">{{ webText('landing.services.turnaround', ['duration' => '']) }}</dt>
                                <dd class="service-turnaround">
                                    <x-landing.icon name="clock" :size="16" />
                                    {{ $service['duration'] }}
                                </dd>
                            </div>
                        @endif
                        <div class="service-meta-row">
                            <dt class="sr-only">{{ webText('landing.prices.eyebrow') }}</dt>
                            <dd>
                                <span class="pill @if ($service['per_item']) pill--ok @else pill--warn @endif">
                                    {{ $service['per_item']
                                        ? webText('landing.services.per_item')
                                        : webText('landing.services.quote') }}
                                </span>
                            </dd>
                        </div>
                    </dl>
                </li>
            @endforeach
        </ul>

        @if (collect($services)->contains(fn (array $s) => ! $s['per_item']))
            <p class="services-quote-note" data-reveal>
                <x-landing.icon name="question" :size="17" />
                <span>{{ webText('landing.services.quote_note') }}</span>
            </p>
        @endif
    </x-landing.section>
@endif
