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
                // An array keyed by language code, so the rule belongs on the
                // members. `'About' => 'max:5000'` was counting *keys* — it
                // would have passed a two-language payload of any size and
                // failed only an install with 5,001 languages.
                'About' => ['nullable', 'array'],
                'About.*' => ['nullable', 'string', 'max:20000'],
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
                // The state's tax, as a percentage added on the order total.
                // Capped, because it was uncapped and a fat-fingered 1000 would
                // have multiplied every invoice in the country by eleven.
                'Tax' => 'nullable|numeric|min:0|max:100',

                /*
                 * Who the invoice is from.
                 *
                 * `App_Name` is the product's name and is read by the apps, the
                 * login screen and the push payloads; a registered business is
                 * often called something else, and the invoice is the one place
                 * that has to use the registered name. Kept apart rather than
                 * overloading `App_Name`, which would rename the product
                 * everywhere to fix a document.
                 *
                 * All three are nullable and the invoice prints only what is
                 * filled — a blank line is better than a guess, and an invoice
                 * carrying a tax line with no registration number is one an
                 * accountant cannot file.
                 */
                'Invoice_Legal_Name' => 'nullable|string|max:191',
                'Invoice_Address' => 'nullable|string|max:500',
                'Invoice_Tax_Number' => 'nullable|string|max:60',
                // What the platform takes from a laundry on each order. The
                // general rate; a laundry that negotiated its own overrides it
                // on its own row.
                'Commission_Rate' => 'nullable|numeric|min:0|max:100',
                // A closed list, not free text: an unrecognised code reaches
                // NumberFormatter and renders as the literal string on every
                // price in the panel and both apps.
                'Currency' => 'nullable|in:EGP,SAR,AED,KWD,QAR,USD',
                /*
                 * `Driver_Earning_Rate` used to be validated here and was never
                 * reachable: it had no input on this form and no seeder row, so
                 * the rule guarded nothing while EarningService quietly paid
                 * every driver a hardcoded 20% of every delivery fee.
                 *
                 * A driver's bonus is now per-driver — `DriverBonusRule`, set
                 * from «قواعد البونس» and assigned on the driver's own row — so
                 * there is no platform-wide driver rate left to validate. The
                 * rule is gone rather than left as decoration.
                 */
                // «قد يتم تطبيق رسوم إضافية» is permissive, so the default is
                // none and this is what turns it on.
                'Cash_Surcharge' => 'nullable|numeric|min:0|max:1000',
                // «ادعُ أصدقاءك». Off until somebody sets a value — the size of
                // a discount is a business decision and not a default.
                'Referral_Reward_Type' => 'nullable|in:percentage,fixed',
                'Referral_Reward_Value' => 'nullable|numeric|min:0|max:1000',
                'Country_Id' => 'nullable|exists:countries,id',
                // Read by `landingCtaTarget()`, which is what the public
                // page's main button points at. Unset until the apps are
                // published, and the button falls back to the price list
                // rather than promising a download that does not exist.
                'App_Store_Url' => 'nullable|url|max:191',
                'Play_Store_Url' => 'nullable|url|max:191',
            ];
        } elseif (Route::is('admin.generalSetting.updatePrivacyAndTerms')) {
            $rules = [
                /*
                 * Same shape as About, and the ceiling matters here.
                 *
                 * These hold documents: the shipped terms are 7,477 characters
                 * of English and 10,106 of Arabic, and the privacy policy 5,800
                 * and 7,865. A `max:5000` on each language — the obvious
                 * "tidy-up" of the rule that was here — would have refused to
                 * save the copy the application already ships, and the operator
                 * would have seen a validation error on text they had not
                 * touched. 20,000 leaves room to extend them; the column is
                 * `longtext`, so the database is not the limit.
                 */
                'Privacy_Policy' => ['nullable', 'array'],
                'Privacy_Policy.*' => ['nullable', 'string', 'max:20000'],
                'Terms' => ['nullable', 'array'],
                'Terms.*' => ['nullable', 'string', 'max:20000'],
            ];
        }

        return $rules;
    }
}
