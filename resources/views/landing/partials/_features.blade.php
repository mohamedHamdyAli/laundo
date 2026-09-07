{{--
    The smaller conveniences.

    Deliberately the quietest section on the page. Six items of equal weight
    would compete with the price-review band two sections up, which is the one
    thing a visitor has to leave remembering — so these are compact rows with a
    small glyph, no cards, no shadows, no hover lift.

    Every one describes something that exists: chosen collection windows,
    independent door/leave choices for collection and return, live tracking off
    the driver's location feed, a wallet whose balance is the sum of its
    transactions, recurrences that prompt rather than order, and referral
    coupons issued after the invited friend's first paid order.

    The referral reward is currently unconfigured (`Referral_Reward_Value` has no
    value), so the copy says what the feature does without naming an amount.
--}}
<x-landing.section
    id="features"
    tone="base"
    :eyebrow="webText('landing.features.eyebrow')"
    :title="webText('landing.features.title')"
    align="center"
>
    <ul class="feature-list">
        @foreach ([1 => 'clock', 2 => 'door', 3 => 'pin', 4 => 'wallet', 5 => 'repeat', 6 => 'gift'] as $n => $icon)
            <li class="feature" data-reveal>
                <span class="feature-icon" aria-hidden="true">
                    <x-landing.icon :name="$icon" :size="19" />
                </span>
                <div class="feature-text">
                    <h3 class="feature-title">{{ webText("landing.features.f{$n}_title") }}</h3>
                    <p class="feature-body">{{ webText("landing.features.f{$n}_body") }}</p>
                </div>
            </li>
        @endforeach
    </ul>
</x-landing.section>
