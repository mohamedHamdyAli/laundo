<?php

namespace Database\Seeders;

use App\Modules\Setting\Models\Setting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $loreamEn = 'It is in fact part of the Latin gibberish that printers use to fill in space in a layout temporarily whilst awaiting the arrival of the
        final text, so that the client can have an idea in advance of what the finished page will look like!';
        $loreamDe = 'Es handelt sich dabei um lateinische Wörter, die Drucker verwenden, um Lücken im Layout vorübergehend zu füllen, bis der endgültige Text vorliegt,
 damit der Kunde eine Vorstellung davon bekommt, wie die fertige Seite aussehen wird!';


        $this->create_new_config('App_Name', 'BaseCode');
        $this->create_new_config('About', json_encode(['en' => $loreamEn, 'ar' => $loreamDe], JSON_UNESCAPED_UNICODE));
        $this->create_new_config('App_Logo', 'logo1.png');
        $this->create_new_config('Login_Cover', 'cover.png');

        $this->create_new_config('Privacy_Policy', json_encode(['en' => $loreamEn, 'ar' => $loreamDe], JSON_UNESCAPED_UNICODE));
        $this->create_new_config('Terms', json_encode(['en' => $loreamEn, 'ar' => $loreamDe], JSON_UNESCAPED_UNICODE));

        $this->create_new_config('Whats_App', 'http://whatsapp.com/');
        $this->create_new_config('Facebook_Url', 'http://facebook.com/');
        $this->create_new_config('Twitter_Url', 'http://twitter.com/');
        $this->create_new_config('Instagram_Url', 'http://instagram.com');
        $this->create_new_config('Linkedin_Url', 'http://linkedin.com');
        $this->create_new_config('Youtube_Url', 'http://youtube.com');
        $this->create_new_config('Snapchat_Url', 'http://snapchat.com/en-GB');
        $this->create_new_config('Gmail_Url', 'http://gmail.com');
        $this->create_new_config('Tax', 10);
        // Contact Us
        $this->create_new_config('Hotline', Null);
        $this->create_new_config('Call', Null);
        $this->create_new_config('Email', 'nahrPhpTeam@nahrPhpTeam.com');
    }
    function create_new_config($key, $value)
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}
