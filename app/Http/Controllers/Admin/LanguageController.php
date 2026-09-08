<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\LanguageHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\LanguageRequest;
use App\Models\Language;
use App\Services\languages\LanguageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Session;

class LanguageController extends Controller
{
    public function __construct(private readonly LanguageService $languageService) {}

    public function index()
    {
        $languages = Language::paginate(10);

        return view('admin.language.index', compact('languages'));
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $searchQuery = $request->get('query');
            $language = Language::search($searchQuery, ['name', 'name_en', 'code', 'country_code'])->paginate(10);
            $table = view('admin.language.partials._language_table_body', ['languages' => $language])->render();

            return response()->json([
                'table' => $table,
                'pagination' => $language->withQueryString()->links()->toHtml(),
            ]);
        }
    }

    public function create()
    {
        $data = $this->languageService->shredData();

        return view('admin.language.create', $data);
    }

    public function store(LanguageRequest $request)
    {
        $this->languageService->createLanguage($request->validated());

        return redirect()->route('admin.language.index')->with('success', __('added_successfully'));
    }

    public function show($id)
    {
        $data = $this->languageService->shredData($id);

        return view('admin.language.show', $data);
    }

    public function edit($id)
    {
        $data = $this->languageService->shredData($id);

        return view('admin.language.edit', $data);
    }

    public function update(LanguageRequest $request, $id)
    {
        $this->languageService->updateRecord($request->validated() + ['id' => $id]);

        return redirect()->route('admin.language.index')->with('success', __('updated_successfully'));
    }

    public function destroy($id)
    {
        $this->languageService->deleteRecord($id);

        return redirect()->route('admin.language.index')->with('success', __('deleted_successfully'));
    }

    public function setLanguage($languageCode)
    {
        $language = Language::where('code', $languageCode)->first();
        if ($language) {
            Session::put('language', $language);
            app()->setLocale($language->code);
        }

        return redirect()->back();
    }

    public function showPanel($id)
    {
        $language = Language::findOrFail($id);
        $filePath = lang_path("{$language->code}.json");

        if (! File::exists($filePath)) {
            $translations = [];
        } else {
            $jsonContent = File::get($filePath);
            $translations = json_decode($jsonContent, true);
        }

        return view('admin.language.panel', compact('language', 'translations'));
    }

    public function updatePanel(Request $request, $id)
    {
        $language = Language::findOrFail($id);
        $filePath = lang_path("{$language->code}.json");

        $translations = $request->input('translations', []);
        $oldKeys = $request->input('old_keys', []);
        $newKeys = $request->input('keys', []);
        $newKeysAdded = $request->input('new_key', []);
        $newValuesAdded = $request->input('new_value', []);

        $finalTranslations = [];

        foreach ($oldKeys as $index => $oldKey) {
            $newKey = $newKeys[$index] ?? $oldKey;
            $value = $translations[$oldKey] ?? null;

            if (! empty($newKey) && $value !== null) {
                $finalTranslations[trim((string) $newKey)] = trim($value);
            }
        }

        if (! empty($newKeysAdded)) {
            foreach ($newKeysAdded as $index => $key) {
                if (! empty($key) && isset($newValuesAdded[$index])) {
                    $finalTranslations[trim((string) $key)] = trim($newValuesAdded[$index]);
                }
            }
        }

        ksort($finalTranslations);
        $jsonContent = json_encode($finalTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        File::put($filePath, $jsonContent);

        return redirect()->back()->with('success', 'Updated Successfully');
    }

    public function showMobile($id)
    {
        $language = Language::findOrFail($id);

        $filePath = lang_path("{$language->code}_mobile.json");

        if (! File::exists($filePath)) {
            $translations = [];
        } else {
            $jsonContent = File::get($filePath);
            $translations = json_decode($jsonContent, true);
        }

        return view('admin.language.mobile', compact('language', 'translations'));
    }

    public function updateMobile(Request $request, $id)
    {
        $language = Language::findOrFail($id);

        $filePath = lang_path("{$language->code}_mobile.json");

        $translations = $request->input('translations', []);
        $newKeys = $request->input('new_key', []);
        $newValues = $request->input('new_value', []);

        if (! empty($newKeys)) {
            foreach ($newKeys as $index => $key) {
                if (! empty($key) && isset($newValues[$index])) {
                    $translations[trim((string) $key)] = trim($newValues[$index]);
                }
            }
        }

        $filteredTranslations = array_filter($translations);
        ksort($filteredTranslations);
        $jsonContent = json_encode($filteredTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        File::put($filePath, $jsonContent);

        return redirect()->back()->with('success', 'Mobile Language File Updated Successfully!');
    }

    public function showWeb($id)
    {
        $language = Language::findOrFail($id);

        $filePath = lang_path("{$language->code}_web.json");

        if (! File::exists($filePath)) {
            $translations = [];
        } else {
            $jsonContent = File::get($filePath);
            $translations = json_decode($jsonContent, true);
        }

        return view('admin.language.web', compact('language', 'translations'));
    }

    public function updateWeb(Request $request, $id)
    {
        $language = Language::findOrFail($id);

        $filePath = lang_path("{$language->code}_web.json");

        $translations = $request->input('translations', []);
        $newKeys = $request->input('new_key', []);
        $newValues = $request->input('new_value', []);

        if (! empty($newKeys)) {
            foreach ($newKeys as $index => $key) {
                if (! empty($key) && isset($newValues[$index])) {
                    $translations[trim((string) $key)] = trim($newValues[$index]);
                }
            }
        }

        $filteredTranslations = array_filter($translations);
        ksort($filteredTranslations);
        $jsonContent = json_encode($filteredTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        File::put($filePath, $jsonContent);

        return redirect()->back()->with('success', 'web Language File Updated Successfully!');
    }

    /**
     * The landing page's copy, grouped and labelled.
     *
     * `showWeb()` above already edits this same file, and it is the right tool
     * for what it was built for: a flat key/value list of whatever an app
     * happens to override. It is the wrong tool for marketing copy — 176 rows
     * of `landing.review.item_2_body` in source order, with the key editable
     * beside the value so a typo in the key silently orphans the string.
     *
     * This is the same file, the same store and the same fallback chain, shown
     * the way somebody writing copy needs it: grouped by section, in page
     * order, with readable headings, the shipped English default as the
     * placeholder, and the key itself as a quiet hint rather than an input.
     *
     * Deliberately **not** a new content system. The Web File stays the single
     * source, so `webText()`, `laundo:sync-web-lang` and
     * `GET /api/v1/translations/web` all keep working unchanged.
     */
    public function showLanding($id)
    {
        $language = Language::findOrFail($id);

        return view('admin.language.landing', [
            'language' => $language,
            'groups' => $this->landingGroups($language->code),
        ]);
    }

    public function updateLanding(Request $request, $id)
    {
        $language = Language::findOrFail($id);
        $path = lang_path("{$language->code}_web.json");

        $existing = [];
        if (File::exists($path)) {
            $decoded = json_decode(File::get($path), true);
            $existing = is_array($decoded) ? $decoded : [];
        }

        /** @var array<string, string> $submitted */
        $submitted = $request->input('landing', []);

        foreach ($submitted as $key => $value) {
            // Only ever the landing namespace. A form cannot be made to write
            // outside it, so the ten legacy keys the apps read are safe from
            // this screen however the request is shaped.
            if (! str_starts_with($key, 'landing.')) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                // Blank means "use the shipped default", which is what
                // `webText()` does with a missing key. Same behaviour as the
                // existing editor's array_filter(), stated on screen so it
                // reads as a reset rather than as data loss.
                unset($existing[$key]);

                continue;
            }

            $existing[$key] = $value;
        }

        ksort($existing);

        File::put(
            $path,
            json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n"
        );

        // The reader caches for ever, and the assembled page for an hour.
        cache()->forget("lang_file_{$language->code}_web");
        cache()->forget("landing_page_{$language->code}");
        clearLanguageCache($language->code);

        return redirect()
            ->route('admin.language.landing', $language->id)
            ->with('success', __('Landing page content updated'));
    }

    /**
     * The `landing.*` keys, grouped by section in the order the page renders.
     *
     * The order is hardcoded rather than derived, because the file is sorted
     * alphabetically and alphabetical is not the order anybody reads the page
     * in — `coverage` before `hero` before `problem` is exactly the confusion
     * this screen exists to remove. A group that gains keys later still shows
     * them; a group nobody has defined is skipped.
     *
     * @return list<array{key: string, label: string, rows: list<array<string, mixed>>}>
     */
    private function landingGroups(string $code): array
    {
        $stored = getTranslationFile('web', $code);
        $defaults = webTemplateDefaults();

        // Section => heading. In page order.
        $sections = [
            'seo' => __('Search engines and sharing'),
            'nav' => __('Header and navigation'),
            'hero' => __('Hero'),
            'facts' => __('Facts strip'),
            'problem' => __('The problem'),
            'promise' => __('How it works'),
            'review' => __('The price review'),
            'journey' => __('The journey'),
            'services' => __('Services'),
            'offers' => __('Offers'),
            'prices' => __('Prices'),
            'coverage' => __('Coverage'),
            'features' => __('Also useful'),
            'faq' => __('Questions'),
            'cta' => __('Call to action'),
            'partner' => __('Partners and drivers'),
            'footer' => __('Footer'),
            'legal' => __('Legal pages'),
        ];

        $groups = [];

        foreach ($sections as $section => $label) {
            $prefix = "landing.{$section}.";
            $rows = [];

            // Walked from the template, not the stored file, so a key nobody has
            // translated yet still gets a box to type into.
            foreach ($defaults as $key => $default) {
                if (! str_starts_with($key, $prefix)) {
                    continue;
                }

                $value = $stored[$key] ?? null;

                $rows[] = [
                    'key' => $key,
                    // The bit after the section, as a hint: `item_2_body`.
                    'name' => str_replace($prefix, '', $key),
                    'value' => is_string($value) ? $value : '',
                    'default' => (string) $default,
                    // Long values get a textarea. The threshold is a guess at
                    // "is this a sentence or a label", and being wrong about it
                    // costs nothing but a slightly tall box.
                    'long' => mb_strlen((string) $default) > 60,
                ];
            }

            if ($rows !== []) {
                $groups[] = ['key' => $section, 'label' => $label, 'rows' => $rows];
            }
        }

        return $groups;
    }

    public function downloadJson($type, $code)
    {
        $filePath = LanguageHelper::filePath($code, $type);

        if ($filePath === null) {
            abort(404, 'Invalid language type');
        }

        if (! file_exists($filePath)) {
            abort(404, "Language file not found: {$filePath}");
        }

        return response()->download($filePath, "{$code}_{$type}.json");
    }
}
