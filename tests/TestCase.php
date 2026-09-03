<?php

namespace Tests;

use App\Models\Language;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\Address\Models\Address;
use App\Modules\City\Models\City;
use App\Modules\Country\Models\Country;
use App\Modules\Driver\Models\Driver;
use App\Modules\Item\Models\Item;
use App\Modules\ItemCategory\Models\ItemCategory;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\LaundryService\Models\LaundryService;
use App\Modules\LaundryZone\Models\LaundryZone;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Service\Models\Service;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use App\Modules\Zone\Models\Zone;
use App\Services\PermissionGenerator;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    /**
     * The minimum a request needs to survive.
     *
     * Almost nothing in this application works without a default language:
     * `getDefaultLanguage()` throws when there is none, and the locale helpers
     * and every translated column read through it. Roles and permissions are
     * needed by CheckPermission and MenuBuilder. So this runs in most tests.
     *
     * Deliberately not calling LanguageSeeder: it writes JSON files to
     * resources/lang as a side effect, which a test has no business doing.
     */
    protected function seedCore(): void
    {
        Cache::flush();

        Language::create([
            'name' => 'English', 'name_en' => 'English', 'code' => 'en',
            'country_code' => 'US', 'default' => 'true', 'is_rtl' => 'false',
            'app_scope' => 'user',
        ]);

        Language::create([
            'name' => 'العربية', 'name_en' => 'Arabic', 'code' => 'ar',
            'country_code' => 'EG', 'default' => 'false', 'is_rtl' => 'true',
            'app_scope' => 'user',
        ]);

        Role::create(['name' => 'Super Admin', 'slug' => 'super_admin', 'type' => 'dashboard', 'is_system' => true]);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'type' => 'dashboard']);
        Role::create(['name' => 'User', 'slug' => Role::USER, 'type' => 'app', 'is_system' => true]);
        Role::create(['name' => 'Driver', 'slug' => Role::DRIVER, 'type' => 'app', 'is_system' => true]);
        Role::create(['name' => 'Laundry Owner', 'slug' => 'laundry_owner', 'type' => 'laundry', 'is_system' => true]);
        Role::create(['name' => 'Laundry Staff', 'slug' => 'laundry_staff', 'type' => 'laundry', 'is_system' => true]);

        // Generated rather than hand-listed, so the tests exercise the same
        // PermissionGenerator the application relies on.
        (new PermissionGenerator)->generate();

        $this->grant('laundry_owner', [
            'laundry.view', 'laundry.update',
            'laundry_staff.view', 'laundry_staff.create', 'laundry_staff.update',
            'laundry_staff.delete', 'laundry_staff.toggle',
            'laundry_service.view', 'laundry_service.update',
            'laundry_zone.view', 'laundry_zone.update',
            'order.view', 'order.update',
            // Mirrors RoleSeeder: the tenant-scoped reports only. Driver
            // performance and operations health are gated on report.update,
            // because tasks are not tenant-scoped and the scope would not stop
            // one laundry seeing another's drivers.
            'report.view',
            // Mirrors RoleSeeder. Ratings are tenant-scoped through
            // BelongsToLaundry, so unlike the driver report they are safe here.
            'order_rating.view',
        ]);

        $this->grant('laundry_staff', [
            'laundry.view', 'laundry_service.view', 'laundry_zone.view', 'order.view',
        ]);
    }

    /**
     * Egypt, one city, two zones, and the Country_Id setting that makes
     * SetTimezone resolve Africa/Cairo instead of leaving the app on UTC.
     *
     * @return array{country: Country, city: City, zones: array<int, Zone>}
     */
    protected function seedGeo(): array
    {
        $country = Country::create([
            'name' => json_encode(['en' => 'Egypt', 'ar' => 'مصر'], JSON_UNESCAPED_UNICODE),
            'code' => 'EG', 'phone_code' => '+20', 'timezone' => 'Africa/Cairo', 'status' => 'active',
        ]);

        $city = City::create([
            'name' => json_encode(['en' => 'Cairo', 'ar' => 'القاهرة'], JSON_UNESCAPED_UNICODE),
            'country_id' => $country->id, 'status' => 'active',
        ]);

        $zones = [
            Zone::create([
                'city_id' => $city->id,
                'name' => json_encode(['en' => 'Nasr City', 'ar' => 'مدينة نصر'], JSON_UNESCAPED_UNICODE),
                'sort_order' => 1, 'status' => 'active',
            ]),
            Zone::create([
                'city_id' => $city->id,
                'name' => json_encode(['en' => 'Maadi', 'ar' => 'المعادي'], JSON_UNESCAPED_UNICODE),
                'sort_order' => 2, 'status' => 'active',
            ]),
        ];

        Setting::create(['key' => 'Country_Id', 'value' => (string) $country->id]);
        Cache::forget('setting_Country_Id');

        return ['country' => $country, 'city' => $city, 'zones' => $zones];
    }

    /**
     * A priced catalogue: one per-item service, one quote-priced service, and two
     * items with prices under the first.
     *
     * Enough for an order to be placeable — which is the point. Every order test
     * needs a real (service, item, price) triple, because OrderPricing reads the
     * matrix and refuses a piece it cannot price.
     *
     * @return array{service: Service, quoted: Service, items: array<int, Item>, prices: array<int, float>}
     */
    protected function seedCatalog(): array
    {
        $service = Service::create([
            'name' => json_encode(['en' => 'Wash & Iron', 'ar' => 'غسيل وكي'], JSON_UNESCAPED_UNICODE),
            'pricing_mode' => 'per_item', 'sort_order' => 1, 'status' => 'active',
        ]);

        $quoted = Service::create([
            'name' => json_encode(['en' => 'Dry Clean', 'ar' => 'تنظيف جاف'], JSON_UNESCAPED_UNICODE),
            'pricing_mode' => 'quote', 'sort_order' => 2, 'status' => 'active',
        ]);

        $category = ItemCategory::create([
            'name' => json_encode(['en' => 'Tops', 'ar' => 'علوي'], JSON_UNESCAPED_UNICODE),
            'sort_order' => 1, 'status' => 'active',
        ]);

        $shirt = Item::create([
            'item_category_id' => $category->id,
            'name' => json_encode(['en' => 'Shirt', 'ar' => 'قميص'], JSON_UNESCAPED_UNICODE),
            'sort_order' => 1, 'status' => 'active',
        ]);

        $trousers = Item::create([
            'item_category_id' => $category->id,
            'name' => json_encode(['en' => 'Trousers', 'ar' => 'بنطلون'], JSON_UNESCAPED_UNICODE),
            'sort_order' => 2, 'status' => 'active',
        ]);

        ItemPrice::create(['item_id' => $shirt->id, 'service_id' => $service->id, 'price' => 17]);
        ItemPrice::create(['item_id' => $trousers->id, 'service_id' => $service->id, 'price' => 23]);

        return [
            'service' => $service,
            'quoted' => $quoted,
            'items' => [$shirt, $trousers],
            'prices' => [17.0, 23.0],
        ];
    }

    /**
     * Declare that a laundry serves a zone and offers a service, and give it a
     * location so a delivery fee can be measured from it.
     *
     * Written through withoutGlobalScopes because a test acts as nobody in
     * particular: with no tenant in context the BelongsToLaundry creating hook
     * leaves laundry_id alone, but being explicit documents the intent.
     */
    protected function cover(Laundry $laundry, int $zoneId, int $serviceId, float $lat = 30.0561, float $lng = 31.2003): void
    {
        $laundry->update(['lat' => $lat, 'lng' => $lng]);

        LaundryZone::withoutGlobalScopes()->updateOrCreate([
            'laundry_id' => $laundry->id, 'zone_id' => $zoneId,
        ]);

        LaundryService::withoutGlobalScopes()->updateOrCreate(
            ['laundry_id' => $laundry->id, 'service_id' => $serviceId],
            ['status' => 'active']
        );
    }

    /**
     * A customer address with a map pin, in the given zone.
     */
    protected function addressFor(
        User $customer,
        Zone $zone,
        float $lat = 30.0500,
        float $lng = 31.2100,
        string $label = 'Home',
    ): Address {
        return Address::create([
            'user_id' => $customer->id,
            'label' => $label,
            'city_id' => $zone->city_id,
            'zone_id' => $zone->id,
            'street' => 'Test Street',
            'lat' => $lat,
            'lng' => $lng,
            'is_default' => true,
        ]);
    }

    /**
     * @param  array<int, string>  $slugs
     */
    protected function grant(string $roleSlug, array $slugs): void
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $role->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id')->all());
    }

    /**
     * The super admin.
     *
     * `firstOrCreate`, not `create`: the identity here is fixed, so there can only
     * ever be one of them, and `users.phone` is unique. A test that asks for the
     * super admin twice — checking two screens in one assertion block, say — wants
     * the same person, not a constraint violation.
     */
    protected function superAdmin(): User
    {
        return User::firstOrCreate(
            ['phone' => '+201000000001'],
            [
                'name' => 'Super', 'email' => 'super@test.local',
                'password' => 'password', 'status' => 'active',
                'role_id' => Role::where('slug', 'super_admin')->value('id'),
                'phone_verified_at' => now(),
            ]
        );
    }

    /**
     * A laundry plus its owner, which is the unit almost every tenancy test needs.
     *
     * @return array{laundry: Laundry, owner: User}
     */
    protected function laundryWithOwner(string $tag, string $laundryPhone, string $ownerPhone): array
    {
        $laundry = Laundry::withoutGlobalScopes()->create([
            'name' => json_encode(['en' => "Laundry {$tag}", 'ar' => "مغسلة {$tag}"], JSON_UNESCAPED_UNICODE),
            'phone' => $laundryPhone,
            'email' => strtolower("laundry{$tag}").'@test.local',
            'status' => 'active',
        ]);

        $owner = User::create([
            'name' => "Owner {$tag}", 'email' => strtolower("owner{$tag}").'@test.local',
            'phone' => $ownerPhone, 'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'laundry_owner')->value('id'),
            'laundry_id' => $laundry->id,
            'phone_verified_at' => now(),
        ]);

        return ['laundry' => $laundry, 'owner' => $owner];
    }

    protected function customer(string $phone = '+201099887766', bool $verified = true): User
    {
        return User::create([
            'name' => 'Customer', 'phone' => $phone, 'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', Role::USER)->value('id'),
            'phone_verified_at' => $verified ? now() : null,
        ]);
    }

    /**
     * A driver with a profile and, optionally, the zones they serve.
     *
     * @param  array<int, int>  $zoneIds
     */
    protected function driverUser(
        string $phone = '+201033330001',
        bool $available = true,
        bool $active = true,
        array $zoneIds = [],
    ): Driver {
        $driver = Driver::create([
            'name' => 'Test Driver',
            'phone' => $phone,
            'password' => 'password',
            'status' => $active ? 'active' : 'inactive',
            'role_id' => Role::where('slug', Role::DRIVER)->value('id'),
            'phone_verified_at' => now(),
        ]);

        $driver->profile()->create([
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'ABC 123',
            'license_number' => 'DL-9911',
            'license_expiry' => now()->addYear()->toDateString(),
            'shift_start' => '09:00',
            'shift_end' => '21:00',
            'is_available' => $available,
        ]);

        if ($zoneIds !== []) {
            $driver->zones()->sync($zoneIds);
        }

        return $driver->fresh(['profile', 'zones']);
    }

    /**
     * Headers every API test needs. Without Accept: application/json Laravel
     * would negotiate HTML for some error paths.
     *
     * @return array<string, string>
     */
    protected function apiHeaders(?string $lang = null): array
    {
        $headers = ['Accept' => 'application/json'];

        if ($lang) {
            $headers['lang'] = $lang;
        }

        return $headers;
    }
}
