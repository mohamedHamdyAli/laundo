{{--
    Questions.

    Native `<details>` / `<summary>`, so this costs **no JavaScript**: keyboard
    operation, the open/closed state and screen-reader announcement are all the
    browser's. An accordion built from divs and click handlers would have to
    reimplement all three, and usually reimplements two.

    The content comes from the `faqs` table when it has rows and from the Web
    File when it does not — which is the case today, the table being empty. Both
    paths are an operator's to edit; filling in the FAQ screen takes over with
    no code change.

    The first item is open on load. One open panel shows a visitor that the rest
    expand, without a page of open text.
--}}
@if ($faqs !== [])
    <x-landing.section
        id="faq"
        tone="raised"
        :eyebrow="webText('landing.faq.eyebrow')"
        :title="webText('landing.faq.title')"
        align="center"
        narrow
    >
        <div class="faq-list">
            @foreach ($faqs as $index => $faq)
                <details class="faq-item" @if ($index === 0) open @endif>
                    <summary class="faq-question">
                        <h3>{{ $faq['question'] }}</h3>
                        <x-landing.icon name="chevron" :size="18" class="ic faq-chevron" />
                    </summary>
                    <div class="faq-answer">
                        <p>{{ $faq['answer'] }}</p>
                    </div>
                </details>
            @endforeach
        </div>
    </x-landing.section>
@endif
