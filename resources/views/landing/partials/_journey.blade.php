{{--
    The customer's tracking timeline — the one graft taken from Concept 2.

    Six steps, straight from `OrderStatus::trackingSteps()`, so the marketing
    page and the app cannot describe two different journeys. The labels go
    through `__()` and are already translated in `ar.json`: these are product
    vocabulary, not marketing copy, so they belong in the panel's own
    translation file rather than the Web File.

    It earns its place by carrying the page's argument rather than decorating
    it. Step 2 is flagged as the point the order **stops** — it is waiting on
    nobody but the customer — and the recount detour hangs off that flag.
    `trackingSteps()` deliberately omits `ReviewDisputed` as a milestone, since
    drawing it inline would make the line grow when something goes wrong; it is
    a note beneath instead.
--}}
<x-landing.section
    id="journey"
    tone="base"
    :eyebrow="webText('landing.journey.eyebrow')"
    :title="webText('landing.journey.title')"
    :lead="webText('landing.journey.lead')"
>
    {{--
        «رحلتك معنا بسيطة» — the customer's three steps, from the
        `journey_steps` table. Three real Arabic rows with a full CRUD screen
        behind them that nothing on the web was reading; the same "a screen
        producing content nothing can fetch" shape banners, intros and the
        static pages all had before `ContentController` existed.

        Above the timeline because the order reads correctly that way: these are
        what *you* do, and the line below is what the order does next.
    --}}
    @if ($orderSteps !== [])
        <ol class="order-steps">
            @foreach ($orderSteps as $step)
                <li class="order-step" data-reveal>
                    <span class="order-step-num" aria-hidden="true">{{ $loop->iteration }}</span>

                    @if ($step['image'])
                        <img
                            class="order-step-img"
                            src="{{ $step['image'] }}"
                            alt=""
                            width="64" height="64"
                            loading="lazy"
                            decoding="async">
                    @endif

                    <h3 class="order-step-title">{{ $step['title'] }}</h3>

                    @if ($step['description'])
                        <p class="order-step-body">{{ $step['description'] }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    <ol class="timeline" data-timeline>
        @foreach ($journey as $index => $step)
            <li class="timeline-step @if ($step['waits']) timeline-step--waits @endif @if ($step['gate']) timeline-step--gate @endif">
                <span class="timeline-marker" aria-hidden="true">
                    @if ($step['gate'])
                        <x-landing.icon name="check" :size="14" />
                    @else
                        {{ $index + 1 }}
                    @endif
                </span>

                <span class="timeline-label">{{ $step['label'] }}</span>

                @if ($step['waits'])
                    <span class="timeline-flag">{{ webText('landing.journey.gate_label') }}</span>
                @endif
            </li>
        @endforeach
    </ol>

    <div class="timeline-notes">
        <p class="timeline-note timeline-note--gate" data-reveal>
            <x-landing.icon name="check" :size="17" />
            <span>{{ webText('landing.journey.gate_note') }}</span>
        </p>
        <p class="timeline-note timeline-note--branch" data-reveal>
            <x-landing.icon name="recount" :size="17" />
            <span>{{ webText('landing.journey.branch_note') }}</span>
        </p>
    </div>

    @if ($legs !== [])
        <div class="legs" data-reveal>
            <h3 class="legs-title">
                <x-landing.icon name="truck" :size="19" />
                {{ webText('landing.journey.legs_title') }}
            </h3>
            <ol class="legs-list">
                @foreach ($legs as $leg)
                    <li><span class="legs-num" aria-hidden="true">{{ $loop->iteration }}</span>{{ $leg }}</li>
                @endforeach
            </ol>
            <p class="legs-body">{{ webText('landing.journey.legs_body') }}</p>
        </div>
    @endif
</x-landing.section>
