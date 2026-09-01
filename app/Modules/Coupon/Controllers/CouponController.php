<?php

namespace App\Modules\Coupon\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coupon\Requests\CouponRequest;
use App\Modules\Coupon\Services\couponCrudService;
use Illuminate\Http\Request;
use RuntimeException;

class CouponController extends Controller
{
    public function __construct(private readonly couponCrudService $couponCrudService) {}

    public function index(Request $request)
    {
        $coupons = $this->couponCrudService->shredData()['coupons'];
        $view = view('admin.coupon.index', compact('coupons'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $coupons = $this->couponCrudService->search($request->get('query'));

            return response()->json([
                'table' => view('admin.coupon.partials._coupon_table_body', compact('coupons'))->render(),
                'pagination' => (string) $coupons->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        return view('admin.coupon.create', $this->couponCrudService->shredData());
    }

    public function store(CouponRequest $request)
    {
        $this->couponCrudService->addNew($request->validated());

        return redirect()->route('admin.coupon.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        return view('admin.coupon.show', $this->couponCrudService->shredData($id));
    }

    public function edit($id)
    {
        return view('admin.coupon.edit', $this->couponCrudService->shredData($id));
    }

    public function update(CouponRequest $request, $id)
    {
        $this->couponCrudService->updateRecord($request->validated() + ['id' => $id]);

        return redirect()->route('admin.coupon.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        try {
            $this->couponCrudService->deleteRecord($id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage() === 'coupon_has_been_used'
                ? __('This code has been used and is part of order history. Deactivate it instead.')
                : __('Could not delete this code.'));
        }

        return redirect()->route('admin.coupon.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $coupon = $this->couponCrudService->toggleStatus($id, $request->status);

        return response()->json(['success' => true, 'status' => $coupon->status]);
    }
}
