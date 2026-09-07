<?php

namespace App\Services\Landing;

use App\Models\Language;
use App\Modules\Faq\Models\Faq;
use App\Modules\Item\Models\Item;
use App\Modules\ItemCategory\Models\ItemCategory;
use App\Modules\JourneyStep\Models\JourneyStep;
use App\Modules\Offer\Models\Offer;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Service\Models\Service;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\Zone\Models\Zone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Everything the landing page states about the business, read from the business.
 *
 * The page's copy lives in the Web File and is an operator's to edit. This class
 * supplies the other half: the counts, prices, turnarounds, service names,
 * coverage and windows. The split matters — a figure typed into a translation
 * file is a figure that goes stale silently, and `tasks/lessons.md` has three
 * separate entries about exactly that shape of bug.
 *
 * ## Two rules it exists to enforce
 *
 * **Nothing test-shaped reaches a public page.** The live database holds
 * `Laundry A`, coupon `SMOKE10`, three `PWTEST…` coupons, `App_Name = BaseCode`,
 * lorem ipsum in `About` and social URLs pointing at facebook.com's front page.
 * All of it renders perfectly happily. So laundries are never listed, offers are
 * not rendered at all (the only one links to `SMOKE10`, and `Offer::badge()`
 * publishes the linked coupon's discount), and every settings read goes through
 * `realSetting()`.
 *
 * **An empty table is an empty section, not a broken one.** `faqs`, `intros`,
 * `banners` and `order_ratings` all have zero rows today. Each reader below
 * either falls back to Web File copy or reports absence so the template can drop
 * the section. `lessons.md`: "check the table has rows before reporting a
 * feature as working".
 *
 * Not named `shredData()` — that is the CRUD-service contract for a list plus a
 * `row`, and this is neither.
 */
class LandingContentService
{
    /**
     * Keyed per locale because the money and the translated names are baked in.
     *
     * One hour, matching `CachingService`. Tests call `Cache::flush()` in
     * `setUp`, which is the project's convention and the reason a cache here
     * does not make the suite lie.
     */
    private const CACHE_KEY = 'landing_page';

    private const CACHE_TTL = 3600;

    /**
     * A cache key that changes when *this file* does.
     *
     * Found the hard way: adding `orderSteps` and `offers` to the payload left
     * the previous hour's cached array in place, and the view died on
     * `Undefined variable $orderSteps`. On a local machine that is one
     * `cache:clear`. On the live site it is a **500 on the front page for up to
     * an hour after every deploy that adds a key** — and it would look like a
     * bad deploy rather than a stale cache.
     *
     * `filemtime()` on the assembler is enough, because the payload's shape is
     * defined here: change the shape and the key moves with it. Same reasoning
     * as `landingAssetVersion()`, and it needs nobody to remember anything.
     */
    private function cacheKey(string $locale): string
    {
        $stamp = @filemtime(__FILE__) ?: 0;

        return self::CACHE_KEY.'_'.$locale.'_'.$stamp;
    }

    /**
     * The whole page, in one array.
     *
     * Follows the panel's habit of handing the view a single assembled payload
     * rather than making the controller pick fields out of it — see the
     * `TimeSlotController@index` note in `lessons.md`, where picking one key out
     * of a service's return meant the next key added arrived undefined.
     *
     * @return array<string, mixed>
     */
    public function pageData(): array
    {
        $locale = (string) app()->getLocale();

        return Cache::remember(
            $this->cacheKey($locale),
            self::CACHE_TTL,
            fn (): array => [
                'services' => $services = $this->services(),
                'priceGrid' => $this->priceGrid(),
                'review' => $this->reviewExample(),
                'journey' => $this->journey(),
                'legs' => $this->legs(),
                'orderSteps' => $this->orderSteps(),
                'offers' => $this->offers(),
                'coverage' => $coverage = $this->coverage(),
                'slots' => $this->timeSlots(),
                'faqs' => $this->faqs(),
                'facts' => $this->facts($services, $coverage),
                'currency' => appCurrency(),
            ]
        );
    }

    /**
     * Values that must not be cached, because they answer "who is reading this".
     *
     * The locale list and the CTA are resolved per request: the first depends on
     * which URL is being served and the second on settings an operator may have
     * changed a moment ago. Cheap either way.
     *
     * @return array<string, mixed>
     */
    public function requestData(): array
    {
        return [
            'locales' => $this->locales(),
            'cta' => landingCtaTarget(),
            'contact' => $this->contact(),
            'social' => $this->social(),
        ];
    }

    /**
     * Active services in display order.
     *
     * `description` is Arabic-only on every seeded row (`{"ar": "…", "en": ""}`),
     * which is why it goes through `getLocalizedValue()` rather than being read
     * off the column — the fallback chain hands an English visitor the Arabic
     * rather than an empty card.
     *
     * @return list<array<string, mixed>>
     */
    private function services(): array
    {
        return Service::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => getLocalizedValue($service, 'name'),
                'description' => filled($service->description)
                    ? getLocalizedValue($service, 'description')
                    : null,
                'per_item' => $service->isPerItem(),
                'duration' => $this->durationText($service),
            ])
            ->all();
    }

    /**
     * "24–48 hours", in the reader's language.
     *
     * The unit words come from the Web File rather than `__()` because Arabic
     * takes the singular after a number — «٢٤–٤٨ ساعة», not «ساعات» — and that
     * is a translator's call, not a `trans_choice` rule worth inventing.
     */
    private function durationText(Service $service): ?string
    {
        $range = $service->durationLabel();

        if ($range === null) {
            return null;
        }

        $unit = $service->duration_unit === 'day'
            ? webText('landing.services.unit_day')
            : webText('landing.services.unit_hour');

        return webText('landing.services.turnaround', ['duration' => "{$range} {$unit}"]);
    }

    /**
     * The published price list: category -> item -> price per per-item service.
     *
     * Quote-priced services are deliberately absent as columns — they have no
     * `item_prices` rows at all, so a column for them would be a column of
     * dashes. The one such service is described in its own card instead.
     *
     * Items with no price anywhere are dropped rather than shown empty: an item
     * exists in the catalogue before anybody prices it, and a row of blanks on a
     * price list reads as "free" or as broken.
     *
     * @return array{services: list<array<string, mixed>>, categories: list<array<string, mixed>>}
     */
    private function priceGrid(): array
    {
        $services = Service::query()
            ->where('status', 'active')
            ->where('pricing_mode', 'per_item')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($services->isEmpty()) {
            return ['services' => [], 'categories' => []];
        }

        // One query for the whole grid. Keyed item->service so a lookup below is
        // an array read rather than a query per cell.
        $prices = ItemPrice::query()
            ->whereIn('service_id', $services->pluck('id'))
            ->get()
            ->groupBy('item_id')
            ->map(fn ($rows) => $rows->pluck('price', 'service_id'));

        $categories = ItemCategory::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $items = Item::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('item_category_id');

        $grid = [];

        foreach ($categories as $category) {
            $rows = [];

            foreach ($items->get($category->id, collect()) as $item) {
                $itemPrices = $prices->get($item->id);

                if ($itemPrices === null || $itemPrices->isEmpty()) {
                    continue;
                }

                $rows[] = [
                    'name' => getLocalizedValue($item, 'name'),
                    'prices' => $services
                        ->mapWithKeys(fn (Service $service) => [
                            $service->id => isset($itemPrices[$service->id])
                                ? moneyFormat($itemPrices[$service->id])
                                : null,
                        ])
                        ->all(),
                ];
            }

            if ($rows !== []) {
                $grid[] = [
                    'name' => getLocalizedValue($category, 'name'),
                    'items' => $rows,
                ];
            }
        }

        return [
            'services' => $services
                ->map(fn (Service $service) => [
                    'id' => $service->id,
                    'name' => getLocalizedValue($service, 'name'),
                ])
                ->all(),
            'categories' => $grid,
        ];
    }

    /**
     * The hero's price-review example, priced from the real list.
     *
     * The whole page argues that the count is checkable, so the numbers on it
     * had better be. Every figure here is computed: the line prices come from
     * `item_prices`, the delivery floor from the cheapest active zone, and the
     * difference is the arithmetic rather than a plausible-looking constant. Put
     * a shirt up from 17 to 19 in the dashboard and this card moves with it.
     *
     * The basket is three items from three different categories so it reads like
     * a real bag rather than three variations on a shirt, and the laundry finds
     * **one more** of the first item — the difference goes *up*, because a page
     * that only ever shows the price falling is not credible.
     *
     * Returns null when nothing is priced yet, and the hero drops the card.
     *
     * @return array<string, mixed>|null
     */
    private function reviewExample(): ?array
    {
        $service = Service::query()
            ->where('status', 'active')
            ->where('pricing_mode', 'per_item')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($service === null) {
            return null;
        }

        $pricesByItem = ItemPrice::query()
            ->where('service_id', $service->id)
            ->pluck('price', 'item_id');

        if ($pricesByItem->isEmpty()) {
            return null;
        }

        /*
         * Queried off `Item` rather than walked through `ItemPrice::item()`.
         *
         * That relation is declared as a bare `BelongsTo`, so its type is
         * `Model` — and sorting through `$row->item->sort_order` is exactly the
         * "missing column degrades to null and a boolean method returns a
         * confident wrong answer" shape `lessons.md` warns about, with PHPStan
         * saying so at level 5. `Item` carries real `@property` docblocks, so
         * reading it directly is both checkable and clearer about intent.
         *
         * One item per category, in catalogue order, so the example basket
         * looks like a bag of laundry rather than three variations on a shirt.
         */
        $items = Item::query()
            ->where('status', 'active')
            ->whereIn('id', $pricesByItem->keys())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->unique('item_category_id')
            ->take(3)
            ->values();

        if ($items->isEmpty()) {
            return null;
        }

        $quantities = [3, 2, 1];
        $lines = [];

        foreach ($items as $index => $item) {
            $quantity = $quantities[$index] ?? 1;
            $unit = (float) $pricesByItem[$item->id];

            $lines[] = [
                'name' => getLocalizedValue($item, 'name'),
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_label' => moneyFormat($unit),
                'total_label' => moneyFormat($unit * $quantity),
            ];
        }

        $estimatedPieces = array_sum(array_column($lines, 'quantity'));
        $estimatedSubtotal = array_sum(array_map(
            fn (array $line): float => $line['unit'] * $line['quantity'],
            $lines
        ));

        // The laundry finds one more of the first item.
        $extraUnit = (float) $lines[0]['unit'];
        $actualPieces = $estimatedPieces + 1;
        $actualSubtotal = $estimatedSubtotal + $extraUnit;

        // The real floor an area charges, not a round number.
        $delivery = Zone::query()
            ->where('status', 'active')
            ->whereNotNull('min_delivery_fee')
            ->min('min_delivery_fee');

        $delivery = $delivery === null ? null : (float) $delivery;

        return [
            'service' => getLocalizedValue($service, 'name'),
            'lines' => $lines,
            'extra_line' => [
                'name' => $lines[0]['name'],
                'quantity' => 1,
                'total_label' => moneyFormat($extraUnit),
            ],
            'estimate' => [
                'pieces' => $estimatedPieces,
                'subtotal_label' => moneyFormat($estimatedSubtotal),
                'total' => $estimatedSubtotal + (float) $delivery,
                'total_label' => moneyFormat($estimatedSubtotal + (float) $delivery),
            ],
            'actual' => [
                'pieces' => $actualPieces,
                'subtotal_label' => moneyFormat($actualSubtotal),
                'total' => $actualSubtotal + (float) $delivery,
                'total_label' => moneyFormat($actualSubtotal + (float) $delivery),
            ],
            'delivery_label' => $delivery === null ? null : moneyFormat($delivery),
            'difference_label' => moneyFormat($extraUnit),
        ];
    }

    /**
     * The six points of the customer's tracking timeline.
     *
     * Straight off `OrderStatus::trackingSteps()`, so the marketing page and the
     * app cannot describe two different journeys — and the labels go through
     * `__()`, which already has all six translated in `ar.json`. These are
     * product vocabulary, not marketing copy, so they belong in the panel's
     * translation file rather than the Web File.
     *
     * Two flags carry the story. `waits` marks «تمت مراجعة القطع» — where the
     * order stops and the customer is the only thing it is waiting for. `gate`
     * marks «تم تأكيد السعر», which is their action. The dispute detour hangs
     * off the first of those; `trackingSteps()` deliberately omits
     * `ReviewDisputed` as a milestone, and drawing it inline would make the line
     * grow when things go wrong.
     *
     * @return list<array<string, mixed>>
     */
    private function journey(): array
    {
        return array_values(array_map(
            fn (OrderStatus $status): array => [
                'value' => $status->value,
                'label' => __($status->label()),
                'tone' => $status->tone(),
                'waits' => $status === OrderStatus::Reviewed,
                'gate' => $status === OrderStatus::Confirmed,
            ],
            OrderStatus::trackingSteps()
        ));
    }

    /**
     * The four physical journeys one order is made of.
     *
     * @return list<string>
     */
    private function legs(): array
    {
        return array_map(
            fn (TaskType $type): string => __($type->label()),
            TaskType::cases()
        );
    }

    /**
     * «رحلتك معنا بسيطة» — the three steps to placing an order.
     *
     * Straight off the `journey_steps` table, which has a full CRUD screen and
     * three real Arabic rows that nothing on the web was reading. That is the
     * "a screen that produces content nothing can fetch" shape this project has
     * hit repeatedly — banners, intros and the static pages all had it before
     * `ContentController` was written.
     *
     * Distinct from `journey()` despite the shared name in the schema: these are
     * the **customer's** actions before the bag leaves, and the timeline is what
     * the order does afterwards. The QA guide is explicit that this table is
     * «محتوى تسويقي بحت» with no relationship to order status.
     *
     * The image is rendered when the row has one. All three currently hold the
     * same uploaded placeholder — a navy circle on pale blue, which reads as
     * deliberate rather than broken — and replacing them is a dashboard upload.
     *
     * @return list<array<string, mixed>>
     */
    private function orderSteps(): array
    {
        return JourneyStep::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (JourneyStep $step): array => [
                'title' => getLocalizedValue($step, 'title'),
                'description' => filled($step->description)
                    ? getLocalizedValue($step, 'description')
                    : null,
                'image' => filled($step->image) ? getImageassetUrl($step->image) : null,
            ])
            ->all();
    }

    /**
     * «عروض متميزة» — live offers from the dashboard.
     *
     * `live()` is the model's own scope, so a seasonal offer outside its window
     * is not published here any more than it is in the app.
     *
     * **The badge is withheld when the linked coupon looks like a test code.**
     * `Offer::badge()` renders the coupon's discount, and the only offer on this
     * install links to `SMOKE10` — so an unguarded render would advertise a
     * smoke-test coupon, on the front page, as a discount a customer could try.
     * The offer's own copy is real marketing text and still renders; only the
     * number is refused. See `looksLikeTestCode()`.
     *
     * @return list<array<string, mixed>>
     */
    private function offers(): array
    {
        return Offer::query()
            ->live()
            ->with('coupon')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Offer $offer): array => [
                'title' => getLocalizedValue($offer, 'title'),
                'description' => filled($offer->description)
                    ? getLocalizedValue($offer, 'description')
                    : null,
                'image' => filled($offer->image) ? getImageassetUrl($offer->image) : null,
                'badge' => looksLikeTestCode($offer->coupon->code ?? null)
                    ? null
                    : $offer->badge(),
                'ends_at' => $offer->ends_at === null
                    ? null
                    : humanDate($offer->ends_at, 'j F Y'),
            ])
            ->all();
    }

    /**
     * Serviced areas, grouped by the city they sit in.
     *
     * These are the zones an operator has declared, which is what decides
     * whether an address can be ordered from and what its delivery costs — real
     * configuration, not a claim. Note what is *not* here: any count of partner
     * laundries. Two rows exist and both are fixtures.
     *
     * A city with no active zone is dropped rather than listed empty; 27
     * governorates are seeded and only two of them have any coverage.
     *
     * @return list<array<string, mixed>>
     */
    private function coverage(): array
    {
        $zones = Zone::query()
            ->where('status', 'active')
            ->with(['city' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (Zone $zone) => $zone->city !== null)
            ->groupBy('city_id');

        $grouped = [];

        foreach ($zones as $cityZones) {
            /** @var Zone $first */
            $first = $cityZones->first();

            $grouped[] = [
                'city' => getLocalizedValue($first->city, 'name'),
                'zones' => $cityZones
                    ->map(fn (Zone $zone) => getLocalizedValue($zone, 'name'))
                    ->values()
                    ->all(),
            ];
        }

        return $grouped;
    }

    /**
     * The windows a customer chooses between.
     *
     * Formatted here rather than in Blade so the hour format follows the locale
     * — Arabic wants «٩:٠٠ ص» shaped output, and `translatedFormat()` is what
     * knows that. `H:i` in the template would have printed 21:00 to a reader who
     * writes 9 PM.
     *
     * @return list<string>
     */
    private function timeSlots(): array
    {
        return TimeSlot::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (TimeSlot $slot): string {
                $start = Carbon::parse((string) $slot->start_time)->locale(app()->getLocale());
                $end = Carbon::parse((string) $slot->end_time)->locale(app()->getLocale());

                return $start->translatedFormat('g:i A').' – '.$end->translatedFormat('g:i A');
            })
            ->all();
    }

    /**
     * Customer questions, from the dashboard when there are any.
     *
     * The table is empty today, so the page falls back to the seven questions in
     * the Web File — which are the ones this service actually raises, and are an
     * operator's to edit either way. When somebody fills the FAQ screen in, the
     * real rows take over with no code change.
     *
     * `?audience=customer` semantics, via the model's own scope: a driver asking
     * when they get paid and a customer asking when they get their clothes
     * should not read each other's list.
     *
     * @return list<array{question: string, answer: string}>
     */
    private function faqs(): array
    {
        $rows = Faq::query()
            ->where('status', 'active')
            ->for('customer')
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn (Faq $faq): array => [
                'question' => getLocalizedValue($faq, 'question'),
                'answer' => getLocalizedValue($faq, 'answer'),
            ])
            ->all();

        if ($rows !== []) {
            return $rows;
        }

        $fallback = [];

        for ($index = 1; $index <= 7; $index++) {
            $question = webText("landing.faq.q{$index}");

            // A key with no value resolves to itself; that is the signal that
            // somebody trimmed the list rather than a question reading
            // "landing.faq.q7".
            if ($question === "landing.faq.q{$index}") {
                continue;
            }

            $fallback[] = [
                'question' => $question,
                'answer' => webText("landing.faq.a{$index}"),
            ];
        }

        return $fallback;
    }

    /**
     * The figures in the strip under the hero. Counted, never asserted.
     *
     * @param  list<array<string, mixed>>  $services
     * @param  list<array<string, mixed>>  $coverage
     * @return array<string, int>
     */
    private function facts(array $services, array $coverage): array
    {
        return [
            'services' => count($services),
            'areas' => array_sum(array_map(
                static fn (array $city): int => count($city['zones']),
                $coverage
            )),
        ];
    }

    /**
     * Contact routes that are actually configured.
     *
     * Everything through `realSetting()`, so the seeded `nahrPhpTeam@` address
     * and the null hotline simply do not render. A footer offering a dead
     * address is worse than a footer offering none.
     *
     * @return array<string, string>
     */
    private function contact(): array
    {
        return array_filter([
            'email' => realSetting('Email'),
            'phone' => realSetting('Hotline') ?? realSetting('Call'),
            'whatsapp' => realSetting('Whats_App'),
        ]);
    }

    /**
     * Social profiles that are actually configured.
     *
     * Every one of these is seeded as the network's own front page, so on this
     * install the list comes back empty — which is the correct answer and the
     * reason the footer's social block is conditional.
     *
     * @return array<string, string>
     */
    private function social(): array
    {
        $keys = [
            'facebook' => 'Facebook_Url',
            'instagram' => 'Instagram_Url',
            'twitter' => 'Twitter_Url',
            'youtube' => 'Youtube_Url',
            'linkedin' => 'Linkedin_Url',
            'snapchat' => 'Snapchat_Url',
        ];

        return array_filter(array_map(
            static fn (string $key): ?string => realSetting($key),
            $keys
        ));
    }

    /**
     * The languages this page is published in, for the switcher and `hreflang`.
     *
     * Each carries its own URL because `hreflang` needs one address per
     * language: `/ar` and `/en` exist precisely so a search engine can index
     * both, which a session-based switch on a single `/` cannot express.
     *
     * @return list<array<string, mixed>>
     */
    private function locales(): array
    {
        return Language::getAllLanguages()
            ->map(fn (Language $language): array => [
                'code' => $language->code,
                'name' => $language->name,
                'is_rtl' => $language->is_rtl === 'true',
                'is_current' => $language->code === app()->getLocale(),
                'url' => url('/'.$language->code),
            ])
            ->values()
            ->all();
    }
}
