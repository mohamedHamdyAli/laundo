<?php

namespace Database\Seeders;

use App\Modules\Setting\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->create_new_config('App_Name', 'BaseCode');
        $this->create_new_config('App_Logo', 'logo1.png');
        $this->create_new_config('Login_Cover', 'cover.png');

        /*
         * `About`, `Terms` and `Privacy_Policy` are **not** seeded here any more.
         *
         * They used to hold Latin and German filler, which was served verbatim to
         * both apps and — once the landing page shipped — published at
         * /{locale}/terms and /privacy for anyone to read.
         *
         * `LegalContentSeeder` owns them now, and is called from
         * `DatabaseSeeder`. It is separate so the legal copy can be refreshed on
         * a live install without this seeder resetting `App_Name`, `Currency`,
         * `Country_Id` and every social URL to their template defaults on the way
         * past.
         */

        $this->create_new_config('Whats_App', 'http://whatsapp.com/');
        $this->create_new_config('Facebook_Url', 'http://facebook.com/');
        $this->create_new_config('Twitter_Url', 'http://twitter.com/');
        $this->create_new_config('Instagram_Url', 'http://instagram.com');
        $this->create_new_config('Linkedin_Url', 'http://linkedin.com');
        $this->create_new_config('Youtube_Url', 'http://youtube.com');
        $this->create_new_config('Snapchat_Url', 'http://snapchat.com/en-GB');
        $this->create_new_config('Gmail_Url', 'http://gmail.com');
        // A percentage added on the order total — «ضريبة الدولة». Read by
        // OrderPricing since it was finally wired up; before that the row sat on
        // the settings form and changed no price at all.
        // 14 — Egypt's VAT, the owner's decision. It was 10, which was a
        // seeder's guess rather than anybody's decision, and it sat unread for
        // months because nothing applied the setting at all.
        $this->create_new_config('Tax', 14);

        /*
         * What the platform takes from a laundry on each order, as a percentage
         * of the order total before tax.
         *
         * Seeded at zero rather than at a plausible 15, and the difference
         * matters on a live install: a seeder that invents a commission is a
         * seeder that quietly starts charging every laundry on the platform.
         * The number is a commercial decision and belongs to whoever signs the
         * contracts, so nothing is deducted until they enter it.
         */
        $this->create_new_config('Commission_Rate', 0);
        // EGP rather than USD, which is what `moneyFormat()` used to default to
        // — a laundry in Cairo was quoting dollars on every screen.
        $this->create_new_config('Currency', 'EGP');
        // Contact Us
        $this->create_new_config('Hotline', null);
        $this->create_new_config('Call', null);
        $this->create_new_config('Email', 'nahrPhpTeam@nahrPhpTeam.com');
    }

    public function create_new_config($key, $value)
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}
