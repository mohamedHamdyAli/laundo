{{--
    Why the usual way is frustrating.

    Three plain statements and no numbers. The temptation in a section like this
    is a statistic — "73% of customers report…" — and there is no survey behind
    this product, so there is no figure to quote. The observations stand on their
    own; a reader in Cairo recognises them or does not.
--}}
<x-landing.section
    id="problem"
    tone="base"
    :eyebrow="webText('landing.problem.eyebrow')"
    :title="webText('landing.problem.title')"
    :lead="webText('landing.problem.lead')"
    narrow
>
    <ul class="problem-list">
        @foreach ([1, 2, 3] as $n)
            <li class="problem-item" data-reveal>
                <span class="problem-index" aria-hidden="true">{{ $n }}</span>
                <div>
                    <h3 class="problem-title">{{ webText("landing.problem.point_{$n}_title") }}</h3>
                    <p class="problem-body">{{ webText("landing.problem.point_{$n}_body") }}</p>
                </div>
            </li>
        @endforeach
    </ul>
</x-landing.section>
