<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Banner\Models\banner;
use App\Modules\Faq\Models\Faq;
use App\Modules\Intro\Models\intro;
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
            ->latest('id')
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
