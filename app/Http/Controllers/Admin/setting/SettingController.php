<?php

namespace App\Http\Controllers\Admin\setting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\setting\GeneralSettingRequest;
use App\Services\setting\settingCrudService;
use App\Services\ResponseService;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    private $settingCrudService;
    public function __construct(settingCrudService $settingCrudService)
    {
        $this->settingCrudService = $settingCrudService;
    }
    public function viewGeneralSetting()
    {
        ResponseService::noPermissionThenSendJson('setting.list');
        return view('admin.setting.generalSetting.index');
    }
    public function updateGeneralSetting(GeneralSettingRequest $request)
    {
        ResponseService::noPermissionThenRedirect('setting.update');
        $this->settingCrudService->updateRecord($request->validated());
        return redirect()->route('admin.generalSetting.viewGeneralSetting')->with('success', __('Added Successfully'));
    }

    public function viewPrivacyAndTerms()
    {
        ResponseService::noPermissionThenSendJson('setting.list');
        return view('admin.setting.PrivacyAndTerms.index');
    }
    public function updatePrivacyAndTerms(GeneralSettingRequest $request)
    {
        ResponseService::noPermissionThenRedirect('setting.update');
        $this->settingCrudService->updateRecord($request->validated());
        return redirect()->route('admin.generalSetting.viewPrivacyAndTerms')->with('success', __('Added Successfully'));
    }
}
