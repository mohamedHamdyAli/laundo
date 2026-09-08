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
        $this->create_new_config('Tax', 10);
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
