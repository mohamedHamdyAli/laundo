<?php

namespace App\Http\Controllers\Admin\banner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\banner\BannerRequest;
use App\Models\banner;
use App\Services\banner\bannerCrudService;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    private $bannerCrudService;
    public function __construct(bannerCrudService $bannerCrudService)
    {
        $this->bannerCrudService = $bannerCrudService;
    }
    public function index(Request $request)
    {
        $banners = banner::paginate(10);
        $view = view('admin.banner.index', compact('banners'));

        return $request->ajax() ? response($view) : $view;
    }
    public function search(Request $request)
    {
        if ($request->ajax()) {
            $searchQuery = $request->get('query');
            $banners = banner::search($searchQuery, ['name', 'description'])->paginate(10);
            $table = view('admin.banner.partials._banner_table_body', compact('banners'))->render();
            return response()->json([
                'table' => $table,
                'pagination' => (string) $banners->withQueryString()->links(),
            ]);

        }
    }

    public function create()
    {
        $data = $this->bannerCrudService->shredData();
        return view('admin.banner.create', $data);
    }

    // /**
    //  * Store a newly created resource in storage.
    //  */
    public function store(BannerRequest $request)
    {
        $this->bannerCrudService->addNew($request->validated());
        return redirect()->route('admin.banner.index')->with('success', __('Added Successfully'));
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $data = $this->bannerCrudService->shredData($id);
        return view('admin.banner.show', $data);
    }

    // /**
    //  * Show the form for editing the specified resource.
    //  */
    public function edit(string $id)
    {
        $data = $this->bannerCrudService->shredData($id);
        return view('admin.banner.edit', $data);
    }

    // /**
    //  * Update the specified resource in storage.
    //  */
    public function update(BannerRequest $request, $id)
    {
        $this->bannerCrudService->updateRecord($request->validated() + ['id' => $id]);
        return redirect()->route('admin.banner.index')->with('success', __('Updated Successfully'));
    }

    // /**
    //  * Remove the specified resource from storage.
    //  */
    public function destroy($id)
    {
        $this->bannerCrudService->deleteRecord($id);
        return redirect()->route('admin.banner.index')->with('success', __('Deleted Successfully'));
    }
    public function toggleStatus(Request $request, $id)
    {
        $banner = $this->bannerCrudService->toggleStatus($id, $request->status);

        return response()->json([
            'success' => true,
            'status' => $banner->status,
        ]);
    }
}
