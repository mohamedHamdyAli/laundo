@php

    $aboutJson = getSettingValue('About');

    $aboutTranslations = !empty($aboutJson) ? json_decode($aboutJson, true) : [];

@endphp



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

    <h5 class="mb-3">{{ __('Platform commission') }}</h5>

    <p class="text-muted small">

        {{ __('What the platform takes from a laundry on each completed order, as a percentage of the order total before tax. The commission is credited to the super admin wallet and the rest to the laundry wallet.') }}

        {{ __('A laundry that negotiated its own rate overrides this from the laundries list.') }}

    </p>

    <div class="col-md-6">

        <div class="form-group">

            <label for="setting-commission" class="form-label">{{ __('General commission rate') }}</label>

            <div class="input-group">

                <input type="number" step="0.01" min="0" max="100" name="Commission_Rate" id="setting-commission"

                    class="form-control" placeholder="{{ __('e.g. 15') }}"

                    value="{{ getSettingValue('Commission_Rate') }}">

                <span class="input-group-text">%</span>

            </div>

            <div class="form-text">

                {{ __('Leave empty or zero to take no commission.') }}

            </div>

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

