<?php

namespace App\Http\Controllers;

use App\Modules\Banner\Models\banner;
use App\Modules\Category\Models\Category;
use App\Modules\Country\Models\Country;
use App\Modules\User\Models\User;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Throwable;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        $dashboardStats = [
            [
                'title' => 'Total Customers',
                'route' => 'admin.user.index',
                'icon' => 'fa fa-users',
                'count' => User::availableUsers()->count(),
            ],
            [
                'title' => 'Total Categories',
                'route' => 'admin.category.index',
                'icon' => 'fa fa-layer-group',
                'count' => Category::count(),
            ],
            [
                'title' => 'Total Banners',
                'route' => 'admin.banner.index',
                'icon' => 'fa fa-image',
                'count' => banner::count(),
            ],
            [
                'title' => 'Total Countries',
                'route' => 'admin.country.index',
                'icon' => 'fa fa-globe',
                'count' => Country::count(),
            ],
        ];

        return view('admin.home', compact('dashboardStats'));
    }

    public function changePasswordIndex()
    {
        return view('admin.changePassword.index');
    }


    public function changePasswordUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'old_password'     => 'required',
            'new_password'     => 'required|min:8',
            'confirm_password' => 'required|same:new_password',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $user = Auth::user();
            if (!Hash::check($request->old_password, Auth::user()->password)) {
                ResponseService::errorResponse("Incorrect old password");
            }
            $user->password = Hash::make($request->confirm_password);
            $user->update();
            ResponseService::successResponse('Password Change Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "HomeController --> changePasswordUpdate");
            ResponseService::errorResponse();
        }
    }

}
