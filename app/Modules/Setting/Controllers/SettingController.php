<?php

namespace App\Modules\Setting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setting\Requests\GeneralSettingRequest;
use App\Modules\Setting\Services\settingCrudService;
use App\Services\ResponseService;

class SettingController extends Controller
{
    public function __construct(private readonly settingCrudService $settingService) {}

    public function viewGeneralSetting()
    {
        ResponseService::noPermissionThenSendJson('setting.list');

        return view('admin.setting.generalSetting.index');
    }

    public function updateGeneralSetting(GeneralSettingRequest $request)
    {
        ResponseService::noPermissionThenRedirect('setting.update');
        $this->settingService->updateSettings($request->validated());

        return redirect()->route('admin.generalSetting.viewGeneralSetting')
            ->with('success', __('Added Successfully'));
    }

    public function viewPrivacyAndTerms()
    {
        ResponseService::noPermissionThenSendJson('setting.list');

        return view('admin.setting.PrivacyAndTerms.index');
    }

    public function updatePrivacyAndTerms(GeneralSettingRequest $request)
    {
        ResponseService::noPermissionThenRedirect('setting.update');
        $this->settingService->updateSettings($request->validated());

        return redirect()->route('admin.generalSetting.viewPrivacyAndTerms')
            ->with('success', __('Added Successfully'));
    }
}
