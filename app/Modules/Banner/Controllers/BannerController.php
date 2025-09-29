<?php

namespace App\Modules\Banner\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banner\Requests\BannerRequest;
use App\Modules\Banner\Services\bannerCrudService;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    private bannerCrudService $bannerService;

    public function __construct(bannerCrudService $bannerService)
    {
        $this->bannerService = $bannerService;
    }

    public function index(Request $request)
    {
        $banners = $this->bannerService->getAllBanners();
        $view = view('admin.banner.index', compact('banners'));
        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $banners = $this->bannerService->searchBanners($request->get('query'));
            $table = view('admin.banner.partials._banner_table_body', compact('banners'))->render();
            return response()->json([
                'table' => $table,
                'pagination' => (string) $banners->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        return view('admin.banner.create');
    }

    public function store(BannerRequest $request)
    {
        $this->bannerService->addBanner($request->validated());
        return redirect()->route('admin.banner.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        $banner = $this->bannerService->getBanner($id);
        return view('admin.banner.show', compact('banner'));
    }

    public function edit($id)
    {
        $banner = $this->bannerService->getBanner($id);
        return view('admin.banner.edit', compact('banner'));
    }

    public function update(BannerRequest $request, $id)
    {
        $this->bannerService->updateBanner($request->validated() + ['id' => $id]);
        return redirect()->route('admin.banner.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->bannerService->deleteBanner($id);
        return redirect()->route('admin.banner.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $banner = $this->bannerService->toggleStatus($id, $request->status);
        return response()->json([
            'success' => true,
            'status' => $banner->status,
        ]);
    }
}
