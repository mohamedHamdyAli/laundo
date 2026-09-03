<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Modules\Address\Models\Address;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\LaundryService\Models\LaundryService;
use App\Modules\LaundryZone\Models\LaundryZone;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Service\Models\Service;
use App\Modules\User\Models\User;
use App\Modules\Zone\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Development fixtures for exercising multi-tenancy by hand.
 *
 * Creates two laundries with an owner and a staff account each, plus one
 * customer, so tenant isolation can be checked by signing in rather than only
 * by reading code: laundry A's owner must never see laundry B's anything.
 *
 * From P6 it also seeds what an order needs to exist at all: coordinates on each
 * laundry, a per-km rate on the zones they cover, coverage rows linking the two,
 * and an address for the customer. Without those the pricing and assignment paths
 * cannot be exercised by hand — the fee comes back «unknown» and every order
 * lands unassigned, which looks like a bug and is only missing data.
 *
 * From P7 it also parks one order at `picked_up` — the stage where the laundry's
 * review screen appears. That screen is the dashboard's most important surface
 * and it renders for exactly one status, so without a fixture sitting at it there
 * is nothing to look at and nothing for a browser test to drive.
 *
 * Deliberately NOT wired into DatabaseSeeder — it is opt-in, and it refuses to
 * run outside a local/testing environment so it cannot seed known-password
 * accounts into production:
 *
 *     php artisan db:seed --class=DevFixturesSeeder
 *
 * Idempotent: re-running updates the same rows instead of duplicating them.
 */
class DevFixturesSeeder extends Seeder
{
    /**
     * Every fixture account uses this password.
     */
    private const PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error(
                'DevFixturesSeeder refuses to run in ['.app()->environment().']. '
                .'It creates accounts with a known password.'
            );

            return;
        }

        $ownerRole = Role::where('slug', 'laundry_owner')->first();
        $staffRole = Role::where('slug', 'laundry_staff')->first();
        $customerRole = Role::where('slug', Role::USER)->first();

        if (! $ownerRole || ! $staffRole) {
            $this->command?->error('Laundry roles are missing. Run RoleSeeder and PermissionSeeder first.');

            return;
        }

        $tenants = [
            // Mohandessin and Nasr City — about 9 km apart.
            ['tag' => 'A', 'laundry_phone' => '+201011110001', 'owner_phone' => '+201011110002', 'staff_phone' => '+201011110003', 'lat' => 30.0561, 'lng' => 31.2003],
            ['tag' => 'B', 'laundry_phone' => '+201022220001', 'owner_phone' => '+201022220002', 'staff_phone' => '+201022220003', 'lat' => 30.0626, 'lng' => 31.3348],
        ];

        DB::transaction(function () use ($tenants, $ownerRole, $staffRole, $customerRole) {
            foreach ($tenants as $t) {
                $tag = $t['tag'];
                $lower = strtolower($tag);

                $laundry = Laundry::updateOrCreate(
                    ['phone' => $t['laundry_phone']],
                    [
                        'name' => json_encode(
                            ['en' => "Laundry $tag", 'ar' => "مغسلة $tag"],
                            JSON_UNESCAPED_UNICODE
                        ),
                        'email' => "laundry$lower@test.local",
                        // Real points in Cairo, a few kilometres apart, so the
                        // haversine fee and the nearest-laundry sort both produce
                        // figures a person can sanity-check.
                        'lat' => $t['lat'],
                        'lng' => $t['lng'],
                        'status' => 'active',
                    ]
                );

                User::updateOrCreate(
                    ['email' => "owner$lower@test.local"],
                    [
                        'name' => "Owner $tag",
                        'phone' => $t['owner_phone'],
                        'password' => self::PASSWORD,
                        'role_id' => $ownerRole->id,
                        'laundry_id' => $laundry->id,
                        'status' => 'active',
                    ]
                );

                User::updateOrCreate(
                    ['email' => "staff$lower@test.local"],
                    [
                        'name' => "Staff $tag",
                        'phone' => $t['staff_phone'],
                        'password' => self::PASSWORD,
                        'role_id' => $staffRole->id,
                        'laundry_id' => $laundry->id,
                        'status' => 'active',
                    ]
                );

                $this->coverage($laundry);

                $this->command?->info("laundry $tag -> id={$laundry->id}  owner$lower@test.local / staff$lower@test.local");
            }

            // An `app`-type account, to confirm customers stay locked out of /admin.
            if ($customerRole) {
                User::updateOrCreate(
                    ['email' => 'customer@test.local'],
                    [
                        'name' => 'Customer',
                        'phone' => '+201055556666',
                        'password' => self::PASSWORD,
                        'role_id' => $customerRole->id,
                        'status' => 'active',
                    ]
                );

                $this->command?->info('customer@test.local (role=user, type=app — must get 403 on /admin)');

                $this->customerAddress(User::where('email', 'customer@test.local')->firstOrFail());
            }

            $this->zoneRates();
        });

        // Outside the transaction above: placing an order runs the assignment and
        // pricing services, which open transactions of their own.
        $this->reviewableOrder();

        $this->command?->info('All fixture accounts use the password: '.self::PASSWORD);
    }

    /**
     * Give the laundry every active zone and every active service.
     *
     * Broad on purpose: the point of the fixtures is that a hand-placed order
     * finds *a* laundry, so assignment can be seen working. Narrowing coverage to
     * test the unassigned path is a per-test concern, not a fixture one.
     */
    private function coverage(Laundry $laundry): void
    {
        foreach (Zone::where('status', 'active')->pluck('id') as $zoneId) {
            LaundryZone::withoutGlobalScopes()->updateOrCreate([
                'laundry_id' => $laundry->id,
                'zone_id' => $zoneId,
            ]);
        }

        foreach (Service::where('status', 'active')->pluck('id') as $serviceId) {
            LaundryService::withoutGlobalScopes()->updateOrCreate(
                ['laundry_id' => $laundry->id, 'service_id' => $serviceId],
                ['status' => 'active']
            );
        }
    }

    /**
     * A per-km rate on every zone that has none.
     *
     * Only fills the gaps — a rate set by hand in the dashboard is left alone, so
     * re-running the seeder cannot quietly overwrite someone's pricing.
     */
    private function zoneRates(): void
    {
        $updated = Zone::whereNull('price_per_km')->update([
            'price_per_km' => 5.00,
            'min_delivery_fee' => 20.00,
        ]);

        if ($updated > 0) {
            $this->command?->info("priced $updated zone(s) at 5.00/km, minimum 20.00");
        }
    }

    /**
     * One order parked at `picked_up`, where the review screen lives.
     *
     * Idempotent in the way that matters: it makes one only if no order is
     * already waiting to be reviewed, so re-running the seeder does not bury the
     * dashboard in fixtures.
     */
    private function reviewableOrder(): void
    {
        if (Order::withoutGlobalScopes()->where('status', OrderStatus::PickedUp->value)->exists()) {
            $this->command?->info('a reviewable order already exists — left alone');

            return;
        }

        $customer = User::where('email', 'customer@test.local')->first();
        $address = $customer?->addresses()->first();

        if (! $customer || ! $address) {
            return;
        }

        $price = ItemPrice::with('service')->whereHas(
            'service',
            fn ($q) => $q->where('status', 'active')->where('pricing_mode', 'per_item')
        )->first();

        if (! $price) {
            $this->command?->warn('no priced item found — skipping the reviewable order');

            return;
        }

        $order = app(OrderService::class)->place($customer, [
            'service_id' => $price->service_id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $price->item_id, 'qty' => 2]],
            'pickup_date' => now()->toDateString(),
            'accepts_review_terms' => true,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        $this->command?->info("order #{$order->code} parked at picked_up — the review screen renders for it");
    }

    /**
     * One saved address for the fixture customer, pinned in Mohandessin — close
     * to laundry A, so the nearest-laundry rule has a visible winner.
     */
    private function customerAddress(User $customer): void
    {
        $zone = Zone::where('status', 'active')->first();

        Address::updateOrCreate(
            ['user_id' => $customer->id, 'label' => 'Home'],
            [
                'city_id' => $zone?->city_id,
                'zone_id' => $zone?->id,
                'street' => 'Test Street 1',
                'building' => '10',
                'floor' => '3',
                'apartment' => '5',
                'lat' => 30.0500,
                'lng' => 31.2100,
                'is_default' => true,
            ]
        );

        $this->command?->info('customer address seeded (Mohandessin pin, default)');
    }
}
