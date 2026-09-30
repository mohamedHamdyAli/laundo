@php

    $aboutJson = getSettingValue('About');

    $aboutTranslations = !empty($aboutJson) ? json_decode($aboutJson, true) : [];

@endphp

{{-- Four tabs of related settings, on one form: one save still posts every
     field, so a tab left unopened is never blanked. A field refused on save
     opens its own tab first (see the script at the foot of the page). --}}
<ul class="nav nav-tabs settings-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="settings-tab-general" data-bs-toggle="tab" data-bs-target="#settings-general"
            type="button" role="tab" aria-controls="settings-general" aria-selected="true">
            <i class="bi bi-gear me-1" aria-hidden="true"></i>{{ __('General') }}
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="settings-tab-contact" data-bs-toggle="tab" data-bs-target="#settings-contact"
            type="button" role="tab" aria-controls="settings-contact" aria-selected="false">
            <i class="bi bi-telephone me-1" aria-hidden="true"></i>{{ __('Contact') }}
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="settings-tab-money" data-bs-toggle="tab" data-bs-target="#settings-money"
            type="button" role="tab" aria-controls="settings-money" aria-selected="false">
            <i class="bi bi-cash-stack me-1" aria-hidden="true"></i>{{ __('Money') }}
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="settings-tab-operations" data-bs-toggle="tab" data-bs-target="#settings-operations"
            type="button" role="tab" aria-controls="settings-operations" aria-selected="false">
            <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>{{ __('Operations') }}
        </button>
    </li>
</ul>

<div class="tab-content settings-tab-content">

<div class="tab-pane fade show active" id="settings-general" role="tabpanel" aria-labelledby="settings-tab-general" tabindex="0">

{{-- General Setting --}}

<div class="row g-3 border rounded p-3 mb-3">

    <h5 class="mb-3">{{ __('General Setting') }}</h5>

    <div class="col-md-4">

        <div class="form-group">

            <label class="form-label">{{ __('APP Name') }}</label>

            <div class="controls">

                <input type="text" name="App_Name" class="form-control" placeholder="{{ __('APP Name') }}"

                    value="{{ getSettingValue('App_Name') }}" {{ Route::is('*.create') ? 'required' : '' }}>

            </div>

        </div>

    </div>

    <div class="col-md-4">

        <div class="form-group">

            <label class="form-label">{{ __('App Logo') }}</label>

            <div class="controls">

                <input type="file" name="App_Logo" class="form-control"

                    {{ Route::is('*.create') ? 'required' : '' }}>

                {{--

                    brandLogo(), not the raw setting: it shows what is actually

                    being used. This install carried App_Logo = 'logo1.png' from

                    the template with no such file, so the preview here was a

                    broken image while every screen fell back to the default.

                --}}

                <a href='{{ brandLogo('dark') }}'>

                    <img class="rounded mt-2" style="height: 80px; width: 80px; object-fit: contain;"

                        src="{{ brandLogo('dark') }}" alt="{{ __('App Logo') }}">

                </a>

            </div>

        </div>

    </div>

    {{--

        The light logo.



        Two files rather than one, because the brand mark is navy: on the navy

        sidebar it measures 1.08:1, which is not "hard to read", it is gone. This

        is the same artwork in white. Optional — a bundled default covers it —

        but a replaced App_Logo needs its pair replaced too, or the sidebar goes

        blank again and nothing says why.

    --}}

    <div class="col-md-4">

        <div class="form-group">

            <label class="form-label">{{ __('App Logo (light, for dark backgrounds)') }}</label>

            <div class="controls">

                <input type="file" name="App_Logo_Light" class="form-control">

                <a href='{{ brandLogo('light') }}'>

                    {{-- On a dark tile: a white logo previewed on white is a white square. --}}

                    <img class="rounded mt-2" style="height: 80px; width: 80px; background: #0f2d52; object-fit: contain; padding: 6px;"

                        src="{{ brandLogo('light') }}" alt="{{ __('App Logo (light, for dark backgrounds)') }}">

                </a>

            </div>

        </div>

    </div>



    <div class="col-md-4">

        <div class="form-group">

            <label class="form-label">{{ __('Image Login Background') }}</label>

            <div class="controls">

                <input type="file" name="Login_Cover" class="form-control"

                    {{ Route::is('*.create') ? 'required' : '' }}>

                @if (getSettingValue('Login_Cover'))

                    <a href='{{ getImageassetUrl(getSettingValue('Login_Cover')) }}'>

                        <img class="rounded" style="height: 80px; width:80px;"

                            src="{{ getImageassetUrl(getSettingValue('Login_Cover')) }}" alt="flag Image">

                    </a>

                @endif

            </div>

        </div>

    </div>

    {{-- The same legend the CRUD forms use, so «Translation» reads as a section

         heading here too rather than a bold line floating between fields. --}}

    <div class="col-12 form-divider">

        <div class="form-section-legend">{{ __('Translation') }}</div>

    </div>

    {{-- `data-rich-text` hands these to `setupRichText()` in

         `layouts/footer_script`. About is published on the public page as

         HTML — its own view prints it with `{!! !!}` — so the person writing

         it needs headings and a list, not a three-row box in which the only

         way to get a heading is to type `<h2>`.



         Full width rather than `col-md-6`: an editor with a toolbar in half a

         column wraps the toolbar before it wraps the prose. --}}

    <div class="row">

        <div class="col-12">

            <div class="form-group">

                <label for="setting-About" class="form-label">{{ __('App About') }}</label>

                <div class="controls">

                    <textarea name="About[{{ getDefaultLanguage('code') }}]" class="form-control" rows="8"

                        {{ Route::is('*.show') ? 'disabled' : '' }} id="setting-About"

                        data-rich-text data-rich-height="260"

                        dir="{{ getDefaultLanguage('is_rtl') === 'true' ? 'rtl' : 'ltr' }}"

                        placeholder="{{ __('Enter App About') }}"

                        {{ Route::is('*.create') ? 'required' : '' }}>{{ $aboutTranslations[getDefaultLanguage('code')] ?? '' }}</textarea>

                </div>

            </div>

        </div>



        @foreach (getAllLanguageWithoutDefault() as $language)

            <div class="col-12">

                <div class="form-group">

                    <label for="setting-About-{{ $language->code }}" class="form-label">

                        {{ __('App About') }} ({{ $language->name }})

                    </label>

                    <textarea name="About[{{ $language->code }}]" class="form-control" id="setting-About-{{ $language->code }}"

                        data-rich-text data-rich-height="260"

                        dir="{{ $language->is_rtl === 'true' ? 'rtl' : 'ltr' }}"

                        placeholder="{{ __('Enter App About') }} ({{ $language->name }})" {{ Route::is('*.show') ? 'disabled' : '' }}

                        rows="8">{{ $aboutTranslations[$language->code] ?? '' }}</textarea>

                </div>

            </div>

        @endforeach

    </div>

</div>

{{-- Region / Timezone --}}

<div class="row g-3 border rounded p-3 mb-3">

    <h5 class="mb-3">{{ __('Region') }}</h5>

    <div class="col-md-6">

        <div class="form-group">

            <label class="form-label">{{ __('Country') }}</label>

            <div class="controls">

                <select name="Country_Id" class="form-select">

                    <option value="">{{ __('Select Country') }}</option>

                    @foreach (\App\Modules\Country\Models\Country::where('status', 'active')->get() as $country)

                        <option value="{{ $country->id }}"

                            {{ getSettingValue('Country_Id') == $country->id ? 'selected' : '' }}>

                            {{ getLocalizedValueDashboard($country, 'name') }}

                            @if ($country->timezone)

                                ({{ $country->timezone }})

                            @endif

                        </option>

                    @endforeach

                </select>

                <div class="form-text mt-2">{{ __('The app uses this country\'s timezone for displaying dates and times.') }}</div>

            </div>

        </div>

    </div>

</div>

</div>

<div class="tab-pane fade" id="settings-contact" role="tabpanel" aria-labelledby="settings-tab-contact" tabindex="0">

{{-- Contact US --}}

<div class="row g-3 border rounded p-3 mb-3">

    <h5 class="mb-3">{{ __('Contact Us') }}</h5>

    <div class="col-md-4">

        <div class="form-group">

            <label class="form-label">{{ __('Hotline') }}</label>

            <div class="controls">

                <input type="text" name="Hotline" class="form-control" placeholder="{{ __('Enter Hotline') }}"

                    value="{{ getSettingValue('Hotline') }}" {{ Route::is('*.create') ? 'required' : '' }}>

            </div>

        </div>

    </div>

    <div class="col-md-4">

        <div class="form-group">

            <label class="form-label">{{ __('Call') }}</label>

            <div class="controls">

                <input type="text" name="Call" class="form-control" placeholder="{{ __('Enter Call') }}"

                    value="{{ getSettingValue('Call') }}" {{ Route::is('*.create') ? 'required' : '' }}>

            </div>

        </div>

    </div>

    <div class="col-md-4">

        <div class="form-group">

            <label class="form-label">{{ __('Email') }}</label>

            <div class="controls">

                <input type="Email" name="Email" class="form-control" placeholder="{{ __('Enter Email') }}"

                    value="{{ getSettingValue('Email') }}" {{ Route::is('*.create') ? 'required' : '' }}>

            </div>

        </div>

    </div>

</div>

{{-- Driver support --}}
<div class="row g-3 border rounded p-3 mb-3">
    <h5 class="mb-1">{{ __('Driver Support') }}</h5>
    <small class="text-muted mb-2">
        {{ __('Leave any of these blank and the driver app falls back to the numbers above. A courier stranded at a doorstep has to reach somebody, so blank means «no separate line», never «no line».') }}
    </small>

    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Driver Hotline') }}</label>
            <div class="controls">
                <input type="text" name="Driver_Hotline" class="form-control"
                    placeholder="{{ __('Same as above when blank') }}"
                    value="{{ getSettingValue('Driver_Hotline') }}">
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Driver Call') }}</label>
            <div class="controls">
                <input type="text" name="Driver_Call" class="form-control"
                    placeholder="{{ __('Same as above when blank') }}"
                    value="{{ getSettingValue('Driver_Call') }}">
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Driver WhatsApp') }}</label>
            <div class="controls">
                <input type="text" name="Driver_Whats_App" class="form-control"
                    placeholder="{{ __('Same as above when blank') }}"
                    value="{{ getSettingValue('Driver_Whats_App') }}">
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Driver Email') }}</label>
            <div class="controls">
                <input type="email" name="Driver_Email" class="form-control"
                    placeholder="{{ __('Same as above when blank') }}"
                    value="{{ getSettingValue('Driver_Email') }}">
            </div>
        </div>
    </div>
</div>

{{-- Social Setting --}}

<div class="row g-3 border rounded p-3 mb-3">

    <h5 class="mb-3">{{ __('Social Setting') }}</h5>

    <div class="col-md-3">

        <div class="form-group">

            <label class="form-label">{{ __('App Whats App') }}</label>

            <div class="controls">

                <input type="text" name="Whats_App" class="form-control" placeholder="{{ __('App Whats App') }}"

                    value="{{ getSettingValue('Whats_App') }}" {{ Route::is('*.create') ? 'required' : '' }}>

            </div>

        </div>

    </div>

    <div class="col-md-3">

        <div class="form-group">

            <label class="form-label">{{ __('App Facebook Url') }}</label>

            <div class="controls">

                <input type="text" name="Facebook_Url" class="form-control"

                    placeholder="{{ __('App Facebook Url') }}" value="{{ getSettingValue('Facebook_Url') }}"

                    {{ Route::is('*.create') ? 'required' : '' }}>

            </div>

        </div>

    </div>

    <div class="col-md-3">

        <div class="form-group">

            <label class="form-label">{{ __('App Twitter Url') }}</label>

            <div class="controls">

                <input type="text" name="Twitter_Url" class="form-control" placeholder="{{ __('App Twitter Url') }}"

                    value="{{ getSettingValue('Twitter_Url') }}" {{ Route::is('*.create') ? 'required' : '' }}>

            </div>

        </div>

    </div>

    <div class="col-md-3">

        <div class="form-group">

            <label class="form-label">{{ __('App Instagram Url') }}</label>

            <div class="controls">

                <input type="text" name="Instagram_Url" class="form-control"

                    placeholder="{{ __('App Instagram Url') }}" value="{{ getSettingValue('Instagram_Url') }}"

                    {{ Route::is('*.create') ? 'required' : '' }}>

            </div>

        </div>

    </div>



    <div class="col-md-3">

        <div class="form-group">

            <label class="form-label">{{ __('App Linkedin Url') }}</label>

            <div class="controls">

                <input type="text" name="Linkedin_Url" class="form-control"

                    placeholder="{{ __('App Linkedin Url') }}" value="{{ getSettingValue('Linkedin_Url') }}"

                    {{ Route::is('*.create') ? 'required' : '' }}>

            </div>

        </div>

    </div>

    <div class="col-md-3">

        <div class="form-group">

            <label class="form-label">{{ __('App Youtube Url') }}</label>

            <div class="controls">

                <input type="text" name="Youtube_Url" class="form-control"

                    placeholder="{{ __('App Youtube Url') }}" value="{{ getSettingValue('Youtube_Url') }}"

                    {{ Route::is('*.create') ? 'required' : '' }}>

            </div>

        </div>

    </div>

    <div class="col-md-3">

        <div class="form-group">

            <label class="form-label">{{ __('App Snapchat Url') }}</label>

            <div class="controls">

                <input type="text" name="Snapchat_Url" class="form-control"

                    placeholder="{{ __('App Snapchat Url') }}" value="{{ getSettingValue('Snapchat_Url') }}"

                    {{ Route::is('*.create') ? 'required' : '' }}>

            </div>

        </div>

    </div>

    <div class="col-md-3">

        <div class="form-group">

            <label class="form-label">{{ __('App Gmail Url') }}</label>

            <div class="controls">

                <input type="text" name="Gmail_Url" class="form-control" placeholder="{{ __('App Gmail Url') }}"

                    value="{{ getSettingValue('Gmail_Url') }}" {{ Route::is('*.create') ? 'required' : '' }}>

            </div>

        </div>

    </div>

</div>

{{-- App listings --}}

<div class="row g-3 border rounded p-3 mb-3">

    <h5 class="mb-3">{{ __('App listings') }}</h5>

    <p class="text-muted small mb-2">

        {{ __('Where the main button on the landing page sends people. Leave empty until the apps are published — the button falls back to the price list rather than promising a download that does not exist.') }}

    </p>

    <div class="col-md-6">

        <div class="form-group">

            <label class="form-label">{{ __('App Store URL') }}</label>

            <div class="controls">

                <input type="url" name="App_Store_Url" class="form-control"

                    placeholder="https://apps.apple.com/..." value="{{ getSettingValue('App_Store_Url') }}">

            </div>

        </div>

    </div>

    <div class="col-md-6">

        <div class="form-group">

            <label class="form-label">{{ __('Google Play URL') }}</label>

            <div class="controls">

                <input type="url" name="Play_Store_Url" class="form-control"

                    placeholder="https://play.google.com/..." value="{{ getSettingValue('Play_Store_Url') }}">

            </div>

        </div>

    </div>

</div>

</div>

<div class="tab-pane fade" id="settings-money" role="tabpanel" aria-labelledby="settings-tab-money" tabindex="0">

{{-- Money --}}

<div class="row g-3 border rounded p-3 mb-3">

    <h5 class="mb-3">{{ __('Money') }}</h5>



    <div class="col-md-6">

        <div class="form-group">

            <label for="setting-currency" class="form-label">{{ __('Currency') }}</label>

            <div class="controls">

                {{-- A setting rather than a constant, because the market is not

                     settled. Everything on every screen is formatted through

                     `moneyFormat()`, which reads this — so it is one field, not

                     a search-and-replace across 27 files. --}}

                <select name="Currency" id="setting-currency" class="form-select">

                    @php

                        $currentCurrency = appCurrency();

                        // The ones this platform could plausibly charge in. A

                        // closed list, so a typo cannot reach NumberFormatter

                        // and render as literal text on every price.

                        $currencies = [

                            'EGP' => __('Egyptian Pound'),

                            'SAR' => __('Saudi Riyal'),

                            'AED' => __('UAE Dirham'),

                            'KWD' => __('Kuwaiti Dinar'),

                            'QAR' => __('Qatari Riyal'),

                            'USD' => __('US Dollar'),

                        ];

                    @endphp

                    @foreach ($currencies as $code => $label)

                        <option value="{{ $code }}" {{ $currentCurrency === $code ? 'selected' : '' }}>

                            {{ $label }} ({{ $code }})

                        </option>

                    @endforeach

                </select>

                <div class="form-text">

                    {{ __('Every price in the panel, the apps and the invoices is shown in this currency.') }}

                    {{ __('It changes how amounts are displayed, not the numbers already stored.') }}

                </div>

            </div>

        </div>

    </div>



    <div class="col-md-6">

        <div class="form-group">

            <label for="setting-tax" class="form-label">{{ __('App Tax') }}</label>

            <div class="input-group">

                <input type="number" step="0.01" min="0" max="100" name="Tax" id="setting-tax"

                    class="form-control" placeholder="{{ __('Enter App Tax') }}"

                    value="{{ getSettingValue('Tax') }}">

                <span class="input-group-text">%</span>

            </div>

            <div class="form-text">

                {{ __('Added on the order total and shown as its own line on the invoice.') }}

                {{ __('An order keeps the rate it was placed under, so changing this never restates an invoice already issued.') }}

            </div>

        </div>

    </div>


    {{-- Validated and read at checkout for the life of the panel, and never
         drawn — so it could only be set in the database. --}}
    <div class="col-md-6">
        <div class="form-group">
            <label for="setting-cash-surcharge" class="form-label">{{ __('Cash handling fee') }}</label>
            <div class="input-group">
                <input type="number" step="0.01" min="0" max="1000" name="Cash_Surcharge" id="setting-cash-surcharge"
                    class="form-control" placeholder="0"
                    value="{{ getSettingValue('Cash_Surcharge') }}">
                <span class="input-group-text">{{ appCurrency() }}</span>
            </div>
            <div class="form-text">
                {{ __('A fixed amount added to an order paid in cash, as its own line on the invoice. Blank or zero adds nothing.') }}
            </div>
        </div>
    </div>

</div>

{{-- «بيانات الفاتورة» — who the invoice is from.



     Separate from App_Name on purpose: that is the product's name and the apps,

     the login screen and every push payload read it. A registered business is

     often called something else, and the invoice is the one document that has to

     use the registered name. Overloading App_Name would rename the product

     everywhere in order to fix a piece of paper. --}}

<div class="row g-3 border rounded p-3 mb-3">

    <h5 class="mb-3">{{ __('Invoice issuer') }}</h5>

    <p class="text-muted small">

        {{ __('Printed at the top of every invoice. Anything left blank is simply left off — the invoice never prints a placeholder.') }}

        {{ __('An invoice that charges tax and carries no registration number is one an accountant cannot file.') }}

    </p>



    <div class="col-md-6">

        <div class="form-group">

            <label for="setting-invoice-legal-name" class="form-label">{{ __('Registered business name') }}</label>

            <input type="text" name="Invoice_Legal_Name" id="setting-invoice-legal-name"

                class="form-control" placeholder="{{ __('As it appears on the commercial register') }}"

                value="{{ getSettingValue('Invoice_Legal_Name') }}">

            <div class="form-text">{{ __('Falls back to the app name when empty.') }}</div>

        </div>

    </div>



    <div class="col-md-6">

        <div class="form-group">

            <label for="setting-invoice-tax-number" class="form-label">{{ __('Tax registration number') }}</label>

            <input type="text" name="Invoice_Tax_Number" id="setting-invoice-tax-number"

                class="form-control" placeholder="{{ __('Enter the tax registration number') }}"

                value="{{ getSettingValue('Invoice_Tax_Number') }}">

        </div>

    </div>



    <div class="col-md-12">

        <div class="form-group">

            <label for="setting-invoice-address" class="form-label">{{ __('Business address') }}</label>

            <textarea name="Invoice_Address" id="setting-invoice-address" rows="2"

                class="form-control" placeholder="{{ __('Street, district, city') }}">{{ getSettingValue('Invoice_Address') }}</textarea>

        </div>

    </div>

</div>

{{-- «عمولة المنصة» --}}

<div class="row g-3 border rounded p-3 mb-3">

    <h5 class="mb-3">{{ __('Customer platform fee') }}</h5>

    <p class="text-muted small">

        {{ __('Added on top of every piece price and paid by the customer, as a percentage. It is folded into the price shown in the app, so the customer sees one figure and no separate fee line — the laundry is still owed its own price in full.') }}

        {{ __('This is not the laundry share. That is set below and per laundry, and the two are paid by different people.') }}

    </p>

    <div class="col-md-6">

        <div class="form-group">

            <label for="setting-commission" class="form-label">{{ __('Customer platform fee') }}</label>

            <div class="input-group">

                <input type="number" step="0.01" min="0" max="100" name="Commission_Rate" id="setting-commission"

                    class="form-control" placeholder="{{ __('e.g. 15') }}"

                    value="{{ getSettingValue('Commission_Rate') }}">

                <span class="input-group-text">%</span>

            </div>

            <div class="form-text">

                {{ __('Leave empty or zero to add nothing. Changing it does not restate an order already placed — the rate is copied onto each order when it is made.') }}

            </div>

        </div>

    </div>

</div>

{{-- «نسبة المغسلة» — the general share, for a laundry nobody set one for. --}}
<div class="row g-3 border rounded p-3 mb-3">
    <h5 class="mb-3">{{ __('General laundry share') }}</h5>
    <p class="text-muted small">
        {{ __('The percentage of the washing a laundry receives when it has no share of its own. The rest stays with the platform, and the delivery fee, the cash fee and the customer platform fee are never divided.') }}
        {{ __('A share set on a laundry overrides this one.') }}
    </p>

    <div class="col-md-6">
        <div class="form-group">
            <label for="setting-laundry-share" class="form-label">{{ __('General laundry share') }}</label>
            <div class="input-group">
                <input type="number" step="0.01" min="0" max="100" name="Laundry_Share_Rate" id="setting-laundry-share"
                    class="form-control" placeholder="{{ __('e.g. 10') }}"
                    value="{{ getSettingValue('Laundry_Share_Rate') }}">
                <span class="input-group-text">%</span>
            </div>
            <div class="form-text">
                {{ __('Leave empty and a laundry with no share of its own is not paid automatically: its settlements wait until a share is set. Changing it does not restate a settlement already paid.') }}
            </div>
        </div>
    </div>

</div>

{{-- «مين يشيل خصم الكوبونات». Its own card: it is not part of the laundry's share
     but a rule about discounts, and sitting inside the share card it read as a
     second share. The worked example is on the screen because the number alone
     did not say what it does. --}}
<div class="row g-3 border rounded p-3 mb-3">
    <h5 class="mb-1">{{ __('Who pays for coupon discounts') }}</h5>
    <p class="text-muted small mb-2">
        {{ __('The laundry is paid its share on its own prices before any coupon. This decides how much of the coupon comes out of the laundry share; the super admin pays the rest. It applies to every coupon that does not set its own choice on the coupon screen, including referral rewards.') }}
    </p>

    <div class="col-md-6">
        <div class="form-group">
            <label for="setting-coupon-laundry-share" class="form-label">{{ __('Laundry part of a coupon discount') }}</label>
            <div class="input-group">
                <input type="number" step="0.01" min="0" max="100" name="Coupon_Laundry_Share" id="setting-coupon-laundry-share"
                    class="form-control" placeholder="0"
                    value="{{ getSettingValue('Coupon_Laundry_Share') }}">
                <span class="input-group-text">%</span>
            </div>
            <div class="form-text">
                {{ __('0 or empty: the super admin pays the whole discount. 100: the laundry pays it, but is never paid below zero. A discount on the delivery fee is always the super admin\'s. Copied onto each order when it is placed.') }}
            </div>
        </div>
    </div>

    <div class="col-md-6">
        {{-- The example the owner asked for, in the numbers of this install. --}}
        <div class="border rounded p-2 small bg-light-subtle">
            <strong>{{ __('Example') }}</strong>
            <div class="text-muted">
                {{ __('Laundry prices 100, coupon 20, laundry share 10%. At 10 here the laundry pays 2 of the coupon and receives 8; the super admin pays 18 and keeps 72. At 0 the laundry receives 10 and the super admin 70.') }}
            </div>
        </div>
    </div>
</div>

{{-- «ادعُ أصدقاءك» --}}

<div class="row g-3 border rounded p-3 mb-3">

    <h5 class="mb-3">{{ __('Referrals') }}</h5>

    <p class="text-muted small">

        {{ __('Both the inviter and the friend get this discount, once the friend has paid for their first order. Leave the value empty to run no referral programme.') }}

    </p>

    <div class="col-md-6">

        <div class="form-group">

            <label class="form-label">{{ __('Reward type') }}</label>

            <div class="controls">

                <select name="Referral_Reward_Type" class="form-select">

                    <option value="percentage" {{ getSettingValue('Referral_Reward_Type') !== 'fixed' ? 'selected' : '' }}>

                        {{ __('Percentage') }}

                    </option>

                    <option value="fixed" {{ getSettingValue('Referral_Reward_Type') === 'fixed' ? 'selected' : '' }}>

                        {{ __('Fixed amount') }}

                    </option>

                </select>

            </div>

        </div>

    </div>

    <div class="col-md-6">

        <div class="form-group">

            <label class="form-label">{{ __('Reward value') }}</label>

            <div class="controls">

                <input type="number" step="0.01" min="0" name="Referral_Reward_Value" class="form-control"

                    placeholder="0" value="{{ getSettingValue('Referral_Reward_Value') }}">

            </div>

        </div>

    </div>

</div>

</div>

<div class="tab-pane fade" id="settings-operations" role="tabpanel" aria-labelledby="settings-tab-operations" tabindex="0">

{{-- «التعيين التلقائي» — whether the platform hands out work by itself. On by
     default, which is how it has always run. The hidden zero before each box is
     what saves «off»: an unticked checkbox posts nothing, and a setting that is
     never posted is never saved. See App\Modules\Order\Services\AutoAssign. --}}
<div class="row g-3 border rounded p-3 mb-3">
    <h5 class="mb-1">{{ __('Automatic assignment') }}</h5>
    <p class="text-muted small mb-2">
        {{ __('Switched off, new work waits for somebody to assign it by hand, and the people who can are told in the bell.') }}
    </p>

    @php($autoAssign = app(\App\Modules\Order\Services\AutoAssign::class))

    <div class="col-md-6">
        <input type="hidden" name="Auto_Assign_Laundry" value="0">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="setting-auto-laundry"
                name="Auto_Assign_Laundry" value="1" @checked($autoAssign->laundries())>
            <label class="form-check-label fw-semibold" for="setting-auto-laundry">{{ __('Assign laundries automatically') }}</label>
        </div>
        <div class="form-text">
            {{ __('On: each new order goes to the nearest laundry that covers its area and offers its service. Off: the order is placed without a laundry and you choose one from the order.') }}
        </div>
    </div>

    <div class="col-md-6">
        <input type="hidden" name="Auto_Assign_Driver" value="0">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="setting-auto-driver"
                name="Auto_Assign_Driver" value="1" @checked($autoAssign->drivers())>
            <label class="form-check-label fw-semibold" for="setting-auto-driver">{{ __('Assign drivers automatically') }}</label>
        </div>
        <div class="form-text">
            {{ __('On: each trip goes to the least busy driver who can take it, and is offered again after a failed attempt. Off: trips wait on the dispatch board for you to assign — the «dispatch» buttons there still work.') }}
        </div>
    </div>
</div>

{{-- Distance measurement, and how orders are handed to laundries. --}}
<div class="row g-3 border rounded p-3 mb-3">
    <h5 class="mb-3">{{ __('Distance and dispatch') }}</h5>
    <p class="text-muted small">
        {{ __('How the system measures the road between a customer and a laundry, and how it chooses between the laundries that cover the same area.') }}
    </p>

    <div class="col-md-6">
        <div class="form-group">
            <label for="setting-maps-key" class="form-label">{{ __('Google Maps API key') }}</label>
            <div class="controls">
                <input type="text" name="Google_Maps_Key" id="setting-maps-key" class="form-control"
                    autocomplete="off" placeholder="{{ __('Leave empty to use straight-line distance') }}"
                    value="{{ getSettingValue('Google_Maps_Key') }}">
            </div>
            <div class="form-text">
                {{ __('Needs the Distance Matrix API enabled. Restrict the key to this server IP in the Google console — an unrestricted key is billed to you by whoever finds it. Without a key, distances fall back to a straight line and delivery fees come out lower than the real road.') }}
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="form-group">
            <label for="setting-balance-tolerance" class="form-label">{{ __('Load balancing tolerance') }}</label>
            <div class="input-group">
                <input type="number" step="0.1" min="0" max="50" name="Balance_Tolerance_Km"
                    id="setting-balance-tolerance" class="form-control" placeholder="0"
                    value="{{ getSettingValue('Balance_Tolerance_Km') }}">
                <span class="input-group-text">{{ __('km') }}</span>
            </div>
            <div class="form-text">
                {{ __('How much further than the nearest laundry an order may travel to reach a less busy one. A laundry within this distance of the nearest takes the order when it has more free places in the pickup window the customer chose. Zero switches balancing off — the nearest always wins.') }}
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="form-group">
            <label for="setting-slot-overflow" class="form-label">{{ __('When every laundry in the area is full') }}</label>
            <div class="controls">
                <select name="Slot_Overflow_Behavior" id="setting-slot-overflow" class="form-select">
                    @foreach (\App\Modules\Order\Enums\SlotOverflowBehavior::cases() as $behavior)
                        <option value="{{ $behavior->value }}"
                            @selected(getSettingValue('Slot_Overflow_Behavior') === $behavior->value)>
                            {{ $behavior->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-text">
                {{ __('Capacity is set per laundry per window, from the laundry own screen or from the capacity grid. The order is never refused at checkout — this only decides who gets it.') }}
                <strong>{{ __('Hiding the window needs the mobile apps to send the address when they ask for windows; until they do, it behaves like the first option.') }}</strong>
            </div>
        </div>
    </div>

    {{-- «أقصى مدة لحجز التسليم» — the far end of the range the app is given for a
         delivery (Turnaround). Counted from the day the service is done, not
         from the pickup, so a four-day service gets the same room as a
         one-day one. --}}
    <div class="col-md-6">
        <div class="form-group">
            <label for="setting-delivery-window" class="form-label">{{ __('How far ahead a delivery can be booked') }}</label>
            <div class="input-group">
                <input type="number" step="1" min="1" max="365" name="Delivery_Window_Days"
                    id="setting-delivery-window" class="form-control"
                    placeholder="{{ \App\Modules\Order\Services\Turnaround::DEFAULT_WINDOW_DAYS }}"
                    value="{{ getSettingValue('Delivery_Window_Days') }}">
                <span class="input-group-text">{{ __('days') }}</span>
            </div>
            <div class="form-text">
                {{ __('Days after the earliest delivery the service allows. A customer can book the delivery anywhere in between, and nothing later. Blank is 14.') }}
            </div>
        </div>
    </div>

    {{-- «آخر حجز لميعاد النهارده» — SlotClock. A window of today closes this
         long before it ends: 08:00–10:00 can be booked until 09:00 with the
         default hour. Read on the business's clock (Cairo). --}}
    <div class="col-md-6">
        <div class="form-group">
            <label for="setting-slot-cutoff" class="form-label">{{ __('Last booking before a window ends') }}</label>
            <div class="input-group">
                <input type="number" step="1" min="0" max="720" name="Slot_Booking_Cutoff_Minutes"
                    id="setting-slot-cutoff" class="form-control"
                    placeholder="{{ \App\Modules\TimeSlot\Services\SlotClock::DEFAULT_CUTOFF_MINUTES }}"
                    value="{{ getSettingValue('Slot_Booking_Cutoff_Minutes') }}">
                <span class="input-group-text">{{ __('minutes') }}</span>
            </div>
            <div class="form-text">
                {{ __('A window of today stops being offered this long before it ends, so nobody books a time a driver cannot reach. Blank is 60; 0 keeps it open until it ends.') }}
            </div>
        </div>
    </div>
</div>

</div>

</div>
