<?php

namespace App\Http\Controllers\Admin\intro;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\intro\IntroRequest;
use App\Models\intro;
use App\Services\intro\introCrudService;
use Illuminate\Http\Request;

class IntroController extends Controller
{
    private $introCrudService;
    public function __construct(introCrudService $introCrudService)
    {
        $this->introCrudService = $introCrudService;
    }
    public function index()
    {
        $intros = intro::paginate(10);
        return view('admin.intro.index', compact('intros'));
    }
    public function search(Request $request)
    {
        if ($request->ajax()) {
            $searchQuery = $request->get('query');
            $intros = Intro::search($searchQuery, ['title', 'description'])->paginate(10);
            $table = view('admin.intro.partials._intro_table_body', compact('intros'))->render();
            return response()->json([
                'table' => $table,
                'pagination' => (string) $intros->withQueryString()->links(),
            ]);

        }
    }
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $data = $this->introCrudService->shredData();
        return view('admin.intro.create', $data);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(IntroRequest $request)
    {
        $this->introCrudService->addNew($request->validated());
        return redirect()->route('admin.intro.index')->with('success', __('Added Successfully'));
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $data = $this->introCrudService->shredData($id);
        return view('admin.intro.show', $data);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $data = $this->introCrudService->shredData($id);
        return view('admin.intro.edit', $data);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(IntroRequest $request, $id)
    {
        $this->introCrudService->updateRecord($request->validated() + ['id' => $id]);
        return redirect()->route('admin.intro.index')->with('success', __('Updated Successfully'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $this->introCrudService->deleteRecord($id);
        return redirect()->route('admin.intro.index')->with('success', __('Deleted Successfully'));
    }
    public function toggleStatus(Request $request, $id)
    {
        $intro = $this->introCrudService->toggleStatus($id, $request->status);

        return response()->json([
            'success' => true,
            'status' => $intro->status,
        ]);
    }
}
