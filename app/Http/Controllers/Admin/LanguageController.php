<?php

namespace App\Http\Controllers\Admin;

use App\Models\Language;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\LanguageRequest;
use App\Services\languages\LanguageService;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\File;

class LanguageController extends Controller
{
    private LanguageService $languageService;
    public function __construct(LanguageService $languageService)
    {
        $this->languageService = $languageService;
    }
    public function index()
    {
        $languages = Language::paginate(10);
        return view('admin.language.index', compact('languages'));
    }
    public function search(Request $request)
    {
        if ($request->ajax()) {
            $searchQuery = $request->get('query');
            $language = Language::search($searchQuery, ['name', 'name_en', 'code'])->paginate(10);
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

        if (!File::exists($filePath)) {
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

            if (!empty($newKey) && $value !== null) {
                $finalTranslations[trim($newKey)] = trim($value);
            }
        }

        if (!empty($newKeysAdded)) {
            foreach ($newKeysAdded as $index => $key) {
                if (!empty($key) && isset($newValuesAdded[$index])) {
                    $finalTranslations[trim($key)] = trim($newValuesAdded[$index]);
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

        if (!File::exists($filePath)) {
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

        if (!empty($newKeys)) {
            foreach ($newKeys as $index => $key) {
                if (!empty($key) && isset($newValues[$index])) {
                    $translations[trim($key)] = trim($newValues[$index]);
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

        if (!File::exists($filePath)) {
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

        if (!empty($newKeys)) {
            foreach ($newKeys as $index => $key) {
                if (!empty($key) && isset($newValues[$index])) {
                    $translations[trim($key)] = trim($newValues[$index]);
                }
            }
        }

        $filteredTranslations = array_filter($translations);
        ksort($filteredTranslations);
        $jsonContent = json_encode($filteredTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        File::put($filePath, $jsonContent);

        return redirect()->back()->with('success', 'web Language File Updated Successfully!');
    }
    public function downloadJson($type, $code)
    {
        $langPath = resource_path("lang");

        switch ($type) {
            case 'main':
            case 'panel':
                $filePath = "{$langPath}/{$code}.json";
                break;

            case 'mobile':
                $filePath = "{$langPath}/{$code}_mobile.json";
                break;

            case 'web':
                $filePath = "{$langPath}/{$code}_web.json";
                break;

            default:
                abort(404, "Invalid language type");
        }

        if (!file_exists($filePath)) {
            abort(404, "Language file not found: {$filePath}");
        }

        return response()->download($filePath, "{$code}_{$type}.json");
    }
}
