<?php

namespace App\Http\Requests\Admin\setting;

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
                'App_Name'          => 'required|max:191',
                'About'             => 'required|max:5000',
                'App_Logo'          => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'Whats_App'         => 'required|url|max:191',
                'Facebook_Url'      => 'required|url|max:191',
                'Twitter_Url'       => 'required|url|max:191',
                'Instagram_Url'     => 'required|url|max:191',
                'Linkedin_Url'      => 'required|url|max:191',
                'Youtube_Url'       => 'required|url|max:191',
                'Snapchat_Url'      => 'required|url|max:191',
                'Gmail_Url'         => 'required|url|max:191',
                'Hotline'           => 'required|string|max:20',
                'Call'              => 'required|string|max:20',
                'Email'             => 'required|string|max:191',
                'Tax'               => 'required|numeric|min:0',
            ];
        } elseif (Route::is('admin.generalSetting.updatePrivacyAndTerms')) {
            $rules = [
                'Privacy_Policy'    => 'required|max:5000',
                'Terms'             => 'required|max:5000',
            ];
        }
        return $rules;
    }
}
