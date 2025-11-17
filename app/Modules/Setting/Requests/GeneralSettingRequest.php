<?php

namespace App\Modules\Setting\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;

class GeneralSettingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [];
        if (Route::is('admin.generalSetting.updateGeneralSetting')) {
            $rules = [
                'App_Name'          => 'nullable|max:191',
                'About'             => 'nullable|max:5000',
                'App_Logo'          => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'Login_Cover'       => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'Whats_App'         => 'nullable|url|max:191',
                'Facebook_Url'      => 'nullable|url|max:191',
                'Twitter_Url'       => 'nullable|url|max:191',
                'Instagram_Url'     => 'nullable|url|max:191',
                'Linkedin_Url'      => 'nullable|url|max:191',
                'Youtube_Url'       => 'nullable|url|max:191',
                'Snapchat_Url'      => 'nullable|url|max:191',
                'Gmail_Url'         => 'nullable|url|max:191',
                'Hotline'           => 'nullable|string|max:20',
                'Call'              => 'nullable|string|max:20',
                'Email'             => 'nullable|string|max:191',
                'Tax'               => 'nullable|numeric|min:0',
            ];
        } elseif (Route::is('admin.generalSetting.updatePrivacyAndTerms')) {
            $rules = [
                'Privacy_Policy'    => 'nullable|max:5000',
                'Terms'             => 'nullable|max:5000',
            ];
        }
        return $rules;
    }
}
