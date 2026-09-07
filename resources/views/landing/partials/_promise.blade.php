{{--
    «نعدّ. نسعّر. توافق. وبعدها نغسل.» — the four beats, in order.

    This is the section the header's "how it works" link points at, and it is
    the compressed version of the whole page. The numbering is the content: the
    third beat belongs to the customer, and it sits between pricing and washing
    rather than after them.

    The third card carries the accent treatment for that reason — not for
    rhythm. It is the one step the platform does not perform.
--}}
<x-landing.section
    id="how"
    tone="raised"
    :eyebrow="webText('landing.promise.eyebrow')"
    :title="webText('landing.promise.title')"
    :lead="webText('landing.promise.lead')"
    align="center"
>
    @php
        $beats = [
            1 => 'receipt',
            2 => 'tag',
            3 => 'check',
            4 => 'droplet',
        ];
    @endphp

    <ol class="beats">
        @foreach ($beats as $n => $icon)
            <li class="beat @if ($n === 3) beat--yours @endif" data-reveal>
                <span class="beat-num" aria-hidden="true">{{ $n }}</span>
                <span class="beat-icon" aria-hidden="true">
                    <x-landing.icon :name="$icon" :size="22" />
                </span>
                <h3 class="beat-title">{{ webText("landing.promise.beat_{$n}_title") }}</h3>
                <p class="beat-body">{{ webText("landing.promise.beat_{$n}_body") }}</p>
            </li>
        @endforeach
    </ol>
</x-landing.section>
