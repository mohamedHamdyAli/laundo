<?php

namespace App\Http\Controllers;

use App\Modules\Report\Services\DashboardSummary;
use App\Services\ResponseService;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
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
     * The dashboard.
     *
     * It used to be a wall of catalogue counts — total customers, total banners,
     * total countries. None of them changed during a working day, and none was
     * anything anybody could act on: "Total Banners: 0" is a row count, not a
     * figure.
     *
     * Now every number is either something happening right now or something
     * waiting for a person, and the two roles get different pages because they do
     * different jobs — a laundry owner does not dispatch drivers, and a platform
     * operator does not count pieces.
     *
     * The numbers are not role-filtered here; `BelongsToLaundry` on the models
     * already does that. What differs is which of them are shown.
     */
    public function index(DashboardSummary $summary): Renderable
    {
        $isLaundry = $summary->isLaundryView();

        $data = [
            'isLaundry' => $isLaundry,
            'today' => $summary->today(),
            'month' => $isLaundry ? $summary->laundryScore() : $summary->thisMonth(),
            'inFlight' => $summary->inFlight(),
        ];

        if ($isLaundry) {
            // The laundry's working day, in the order it actually happens.
            $data['queue'] = $summary->laundryQueue();
        } else {
            $data['queue'] = $summary->needsAPerson();
            // Tasks carry no laundry_id, so this figure is only safe here.
            $data['drivers'] = $summary->drivers();
            $data['attention'] = $summary->attentionOrders();
        }

        return view('admin.home', $data);
    }

    public function changePasswordIndex()
    {
        return view('admin.changePassword.index');
    }

    public function changePasswordUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'old_password' => 'required',
            'new_password' => 'required|min:8',
            'confirm_password' => 'required|same:new_password',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $user = Auth::user();
            if (! Hash::check($request->old_password, Auth::user()->password)) {
                ResponseService::errorResponse('Incorrect old password');
            }
            $user->password = Hash::make($request->confirm_password);
            $user->update();
            ResponseService::successResponse('Password Change Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'HomeController --> changePasswordUpdate');
            ResponseService::errorResponse();
        }
    }
}
