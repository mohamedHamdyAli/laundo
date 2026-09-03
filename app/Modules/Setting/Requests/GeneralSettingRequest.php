<?php

namespace App\Modules\Setting\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [];
        if (Route::is('admin.generalSetting.updateGeneralSetting')) {
            $rules = [
                'App_Name' => 'nullable|max:191',
                'About' => 'nullable|max:5000',
                'App_Logo' => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                // A second file, because a single logo cannot serve both surfaces:
                // the brand navy is 1.08:1 on the navy sidebar, which is invisible
                // rather than merely faint.
                'App_Logo_Light' => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'Login_Cover' => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'Whats_App' => 'nullable|url|max:191',
                'Facebook_Url' => 'nullable|url|max:191',
                'Twitter_Url' => 'nullable|url|max:191',
                'Instagram_Url' => 'nullable|url|max:191',
                'Linkedin_Url' => 'nullable|url|max:191',
                'Youtube_Url' => 'nullable|url|max:191',
                'Snapchat_Url' => 'nullable|url|max:191',
                'Gmail_Url' => 'nullable|url|max:191',
                'Hotline' => 'nullable|string|max:20',
                'Call' => 'nullable|string|max:20',
                'Email' => 'nullable|string|max:191',
                'Tax' => 'nullable|numeric|min:0',
                // A closed list, not free text: an unrecognised code reaches
                // NumberFormatter and renders as the literal string on every
                // price in the panel and both apps.
                'Currency' => 'nullable|in:EGP,SAR,AED,KWD,QAR,USD',
                // The driver's share of the delivery fee, as a percentage —
                // which is how anybody setting it thinks about it.
                'Driver_Earning_Rate' => 'nullable|numeric|min:0|max:100',
                // «قد يتم تطبيق رسوم إضافية» is permissive, so the default is
                // none and this is what turns it on.
                'Cash_Surcharge' => 'nullable|numeric|min:0|max:1000',
                // «ادعُ أصدقاءك». Off until somebody sets a value — the size of
                // a discount is a business decision and not a default.
                'Referral_Reward_Type' => 'nullable|in:percentage,fixed',
                'Referral_Reward_Value' => 'nullable|numeric|min:0|max:1000',
                'Country_Id' => 'nullable|exists:countries,id',
            ];
        } elseif (Route::is('admin.generalSetting.updatePrivacyAndTerms')) {
            $rules = [
                'Privacy_Policy' => 'nullable|max:5000',
                'Terms' => 'nullable|max:5000',
            ];
        }

        return $rules;
    }
}
