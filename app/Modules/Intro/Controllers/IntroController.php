<?php

namespace App\Modules\Intro\Controllers;

use App\Http\Controllers\Controller;
use App\Models\intro;
use App\Modules\Intro\Requests\IntroRequest;
use App\Modules\Intro\Services\introCrudService;
use Illuminate\Http\Request;

class IntroController extends Controller
{
    private introCrudService $introService;

    public function __construct(introCrudService $introService)
    {
        $this->introService = $introService;
    }

    public function index()
    {
        $intros = $this->introService->getAllIntros();
        return view('admin.intro.index', compact('intros'));
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $intros = $this->introService->searchIntros($request->get('query'));
            $table = view('admin.intro.partials._intro_table_body', compact('intros'))->render();
            return response()->json([
                'table' => $table,
                'pagination' => (string) $intros->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        return view('admin.intro.create');
    }

    public function store(IntroRequest $request)
    {
        $this->introService->addIntro($request->validated());
        return redirect()->route('admin.intro.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        $intro = $this->introService->getIntro($id);
        return view('admin.intro.show', compact('intro'));
    }

    public function edit($id)
    {
        $intro = $this->introService->getIntro($id);
        return view('admin.intro.edit', compact('intro'));
    }

    public function update(IntroRequest $request, $id)
    {
        $this->introService->updateIntro($request->validated() + ['id' => $id]);
        return redirect()->route('admin.intro.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->introService->deleteIntro($id);
        return redirect()->route('admin.intro.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $intro = $this->introService->toggleStatus($id, $request->status);
        return response()->json([
            'success' => true,
            'status' => $intro->status,
        ]);
    }
}
