<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Banner\Models\banner;
use App\Modules\Faq\Models\Faq;
use App\Modules\JourneyStep\Models\JourneyStep;
use App\Modules\Intro\Models\intro;
use App\Modules\Offer\Models\Offer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The content operations writes and the apps read.
 *
 * These endpoints exist because three admin screens were producing content that
 * nothing could fetch: banners, the onboarding slides and the static pages. The
 * dashboard could create them, they sat in the database, and no app had any way
 * to ask for them. Same shape as a column added with no form field — the feature
 * looks complete from either end and is dead in the middle.
 *
 * Public by necessity. Onboarding runs before an account exists, and guest mode
 * browses the home screen, so a token requirement here would hide the content
 * from exactly the people it is aimed at.
 */
class ContentController extends Controller
{
    /**
     * The home screen's promotional strip.
     *
     * `action` is resolved server-side into a kind and a value the app can route
     * on, rather than shipping the raw columns and asking every client to agree
     * on what they mean.
     */
    public function banners(): JsonResponse
    {
        $banners = banner::where('status', 'active')
            // `sort_order` then `id`, for the reason `intros()` states below:
            // two rows sharing a number would otherwise arrive in whatever
            // sequence MySQL felt like, and a carousel that reshuffles between
            // visits looks broken. This was `latest('id')`, so the newest
            // banner was always first and operations could not reorder it.
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (banner $row) {
                $target = $row->target();

                return [
                    'id' => $row->id,
                    'title' => getLocalizedValue($row, 'name'),
                    'description' => getLocalizedValue($row, 'description'),
                    'image' => getImageassetUrl($row->image),
                    // Null rather than a kind of "none", so a client can simply
                    // check for absence before drawing the button.
                    'action' => $target->needsValue() && filled($row->target_value)
                        ? ['type' => $target->value, 'value' => $row->target_value]
                        : null,
                ];
            })
            ->values();

        return successReturnData($banners);
    }

    /**
     * «عروض متميزة» — the second carousel on the home screen.
     *
     * Its own endpoint rather than a `placement` filter on `/banners`, because
     * the home screen asks for two different things: the hero above and these
     * below, with different card shapes. One flat list left the app guessing
     * which was which and left operations unable to move a card between them.
     */
    public function offers(): JsonResponse
    {
        $offers = Offer::live()
            // The badge is read off the coupon, so without this a page of ten
            // offers is ten extra queries.
            ->with('coupon')
            // `sort_order` then `id`, for the reason `intros()` states: two rows
            // sharing a number would otherwise arrive in whatever sequence
            // MySQL felt like, and a carousel that reshuffles between visits
            // looks broken.
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (Offer $row) {
                $target = $row->target();

                return [
                    'id' => $row->id,
                    'title' => getLocalizedValue($row, 'title'),
                    'description' => getLocalizedValue($row, 'description'),
                    'image' => getImageassetUrl($row->image),
                    // «خصم 20%». Derived from the linked coupon and withheld
                    // unless that coupon would actually be accepted, so a card
                    // cannot promise a discount the checkout then refuses.
                    'badge' => $row->badge(),
                    // Null rather than a kind of "none", so a client can simply
                    // check for absence before drawing the button. Same shape
                    // banners already return.
                    'action' => $target->needsValue() && filled($row->target_value)
                        ? ['type' => $target->value, 'value' => $row->target_value]
                        : null,
                    // For «ينتهي بعد …». ISO 8601 rather than a formatted
                    // string: only the app knows how much room it has.
                    'ends_at' => $row->ends_at?->toIso8601String(),
                ];
            })
            ->values();

        return successReturnData($offers);
    }

    /**
     * The onboarding slides, in the order operations set.
     *
     * Ordered by `order` and then `id`: two slides sharing an order number would
     * otherwise arrive in whatever sequence MySQL felt like, and the first-run
     * experience would differ between two installs of the same app.
     */
    public function intros(): JsonResponse
    {
        $intros = intro::where('status', 'active')
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn (intro $row) => [
                'id' => $row->id,
                'title' => getLocalizedValue($row, 'title'),
                'description' => getLocalizedValue($row, 'description'),
                'image' => getImageassetUrl($row->image),
                'order' => (int) $row->order,
            ])
            ->values();

        return successReturnData($intros);
    }

    /**
     * «رحلتك معنا بسيطة» — the three how-it-works cards on the home screen.
     *
     * Separate from `intros()` by decision, though the shape is nearly the
     * same: onboarding is a full-screen sequence swiped once before an account
     * exists, and these are cards on the screen people open every day.
     *
     * The number the design draws beside each card is the position, so it is
     * not in the payload — the client numbers the array it was given. Sending
     * it as a column would let a «3» arrive second.
     */
    public function journeySteps(): JsonResponse
    {
        $steps = JourneyStep::where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (JourneyStep $row) => [
                'id' => $row->id,
                'title' => getLocalizedValue($row, 'title'),
                'description' => getLocalizedValue($row, 'description'),
                'image' => getImageassetUrl($row->image),
            ])
            ->values();

        return successReturnData($steps);
    }

    /**
     * «الأسئلة الشائعة».
     *
     * `audience` is a query parameter rather than inferred from the token, because
     * both apps reach this before anybody logs in — the driver app puts the section
     * in its account screen, but nothing stops a client asking early, and guessing
     * from an absent token would silently serve a customer the driver's answers.
     */
    public function faqs(Request $request): JsonResponse
    {
        $audience = $request->get('audience');

        $faqs = Faq::where('status', 'active')
            ->when(
                in_array($audience, ['customer', 'driver'], true),
                fn ($query) => $query->for((string) $audience)
            )
            // `order` then id: two entries sharing a number would otherwise come
            // back in whatever sequence the database felt like, and a help list
            // that reshuffles between visits looks broken.
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn (Faq $row) => [
                'id' => $row->id,
                'question' => getLocalizedValue($row, 'question'),
                'answer' => getLocalizedValue($row, 'answer'),
            ])
            ->values();

        return successReturnData($faqs);
    }
}
