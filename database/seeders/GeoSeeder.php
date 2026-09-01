<?php

namespace Database\Seeders;

use App\Modules\City\Models\City;
use App\Modules\Country\Models\Country;
use App\Modules\Setting\Models\Setting;
use App\Modules\Zone\Models\Zone;
use App\Support\CountryTimezones;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Egypt, its 27 governorates as cities, and zones for Greater Cairo.
 *
 * Also sets the `Country_Id` setting, which is what makes the app run on
 * Africa/Cairo instead of UTC: SetTimezone reads that setting, looks up the
 * country's timezone and applies it per request. Without it every pickup and
 * delivery window would be stored and compared 2–3 hours off.
 *
 * Idempotent. Note the lookups: `where('name->en', ...)` and not the whole JSON
 * blob, because MySQL normalizes what it stores in a json column (sorted keys,
 * a space after each colon) so an equality check against json_encode() output
 * can never match. Same trap as CatalogSeeder.
 *
 *     php artisan db:seed --class=GeoSeeder
 */
class GeoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $country = $this->seedCountry();
            $cities = $this->seedCities($country);
            $this->seedZones($cities);
            $this->applyAsDefaultCountry($country);
        });

        // getSettingValue() and the language helpers use rememberForever, so a
        // fresh Country_Id stays invisible until the cache is dropped.
        Cache::forget('setting_Country_Id');

        $this->command?->info('Geo seeded: '.Country::count().' country, '
            .City::count().' cities, '.Zone::count().' zones. Timezone: '
            .(Country::first()?->timezone ?? 'none'));
    }

    private function seedCountry(): Country
    {
        $country = Country::where('code', 'EG')->first() ?? new Country;

        $country->fill([
            'name' => json_encode(['en' => 'Egypt', 'ar' => 'مصر'], JSON_UNESCAPED_UNICODE),
            'code' => 'EG',
            'phone_code' => '+20',
            // CountryTimezones already maps EG => Africa/Cairo.
            'timezone' => CountryTimezones::resolve('EG') ?? 'Africa/Cairo',
            'status' => 'active',
        ])->save();

        return $country;
    }

    /**
     * All 27 governorates. Only Greater Cairo gets zones below; the rest exist so
     * addresses and coverage can be extended from the dashboard without a deploy.
     *
     * @return array<string, City>
     */
    private function seedCities(Country $country): array
    {
        $governorates = [
            ['Cairo', 'القاهرة'],
            ['Giza', 'الجيزة'],
            ['Alexandria', 'الإسكندرية'],
            ['Qalyubia', 'القليوبية'],
            ['Dakahlia', 'الدقهلية'],
            ['Sharqia', 'الشرقية'],
            ['Gharbia', 'الغربية'],
            ['Monufia', 'المنوفية'],
            ['Beheira', 'البحيرة'],
            ['Kafr El Sheikh', 'كفر الشيخ'],
            ['Damietta', 'دمياط'],
            ['Port Said', 'بورسعيد'],
            ['Ismailia', 'الإسماعيلية'],
            ['Suez', 'السويس'],
            ['North Sinai', 'شمال سيناء'],
            ['South Sinai', 'جنوب سيناء'],
            ['Beni Suef', 'بني سويف'],
            ['Faiyum', 'الفيوم'],
            ['Minya', 'المنيا'],
            ['Asyut', 'أسيوط'],
            ['Sohag', 'سوهاج'],
            ['Qena', 'قنا'],
            ['Luxor', 'الأقصر'],
            ['Aswan', 'أسوان'],
            ['Red Sea', 'البحر الأحمر'],
            ['New Valley', 'الوادي الجديد'],
            ['Matrouh', 'مطروح'],
        ];

        $out = [];

        foreach ($governorates as [$en, $ar]) {
            $city = City::where('name->en', $en)->first() ?? new City;

            $city->fill([
                'name' => json_encode(['en' => $en, 'ar' => $ar], JSON_UNESCAPED_UNICODE),
                'country_id' => $country->id,
                'status' => 'active',
            ])->save();

            $out[$en] = $city;
        }

        return $out;
    }

    /**
     * Zones for Greater Cairo. مدينة نصر, الدقي and الرحاب appear verbatim in the
     * design's address and register screens.
     *
     * @param  array<string, City>  $cities
     */
    private function seedZones(array $cities): void
    {
        $tree = [
            'Cairo' => [
                ['Nasr City', 'مدينة نصر'],
                ['Heliopolis', 'مصر الجديدة'],
                ['Maadi', 'المعادي'],
                ['Zamalek', 'الزمالك'],
                ['Downtown', 'وسط البلد'],
                ['Al Rehab', 'الرحاب'],
                ['Madinaty', 'مدينتي'],
                ['Fifth Settlement', 'التجمع الخامس'],
                ['Mokattam', 'المقطم'],
                ['Ain Shams', 'عين شمس'],
                ['Helwan', 'حلوان'],
                ['Shubra', 'شبرا'],
                ['El Zeitoun', 'الزيتون'],
                ['Abbassia', 'العباسية'],
                ['Sayeda Zeinab', 'السيدة زينب'],
            ],
            'Giza' => [
                ['Dokki', 'الدقي'],
                ['Mohandessin', 'المهندسين'],
                ['Agouza', 'العجوزة'],
                ['Haram', 'الهرم'],
                ['Faisal', 'فيصل'],
                ['Imbaba', 'إمبابة'],
                ['Sheikh Zayed', 'الشيخ زايد'],
                ['6th of October', '6 أكتوبر'],
                ['Pyramids Gardens', 'حدائق الأهرام'],
                ['Badrashin', 'البدرشين'],
            ],
        ];

        foreach ($tree as $cityKey => $zones) {
            if (! isset($cities[$cityKey])) {
                continue;
            }

            $order = 1;

            foreach ($zones as [$en, $ar]) {
                $zone = Zone::where('name->en', $en)->first() ?? new Zone;

                $zone->fill([
                    'city_id' => $cities[$cityKey]->id,
                    'name' => json_encode(['en' => $en, 'ar' => $ar], JSON_UNESCAPED_UNICODE),
                    'sort_order' => $order++,
                    'status' => 'active',
                ])->save();
            }
        }
    }

    /**
     * Points the `Country_Id` setting at Egypt so SetTimezone has something to
     * resolve. Settings are key/value rows with PascalCase keys in this project.
     */
    private function applyAsDefaultCountry(Country $country): void
    {
        Setting::updateOrCreate(['key' => 'Country_Id'], ['value' => (string) $country->id]);
    }
}
