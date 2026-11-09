<?php

namespace App\Modules\Order\Services;

use App\Modules\Address\Models\Address;
use App\Modules\Address\Services\AddressBook;
use App\Modules\Loyalty\Services\PointsPolicy;
use App\Modules\Offer\Repositories\OfferRepository;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Pricing\Services\pricingService;
use App\Modules\Service\Repositories\ServiceRepository;
use App\Modules\TimeSlot\Repositories\TimeSlotRepository;
use App\Modules\User\Models\User;
use App\Modules\User\Repositories\UserRepository;
use App\Modules\Zone\Services\coverageRequestCrudService;
use App\Support\LaundryContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * «إنشاء طلب» — an order placed from the panel, for a customer (2026-11-08).
 *
 * The client asked for it so an operator can take an order over the phone.
 * The owner's answers set its shape:
 *
 * - **An existing customer only.** No account is created here; the operator
 *   picks one (`UserRepository::activeCustomersMatching()`), and the address is
 *   one of theirs or a new pin saved to them (`AddressBook`, the app's own rule).
 * - **Exactly the app's checkout.** Every payment method, a coupon or an
 *   offer, the customer's points — all through `OrderService::quote()` and
 *   `place()`, the same two calls the app makes, so coverage, the booking
 *   cutoff, slot capacity, the turnaround, laundry assignment and the
 *   customer's notification cannot differ between the two ways in.
 * - **The platform only.** `order.create`, and refused here — not only at the
 *   route — inside a laundry, even when a role grants the permission: which
 *   laundry gets an order is the platform's decision (`LaundryAssigner`), and a
 *   laundry placing its own would choose itself.
 *
 * The history says who placed it: the opening log is the operator's, as
 * `admin` (`OrderStateMachine::open()`), and the activity log records the
 * insert under their name.
 */
class PanelOrders
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly UserRepository $users,
        private readonly AddressBook $book,
        private readonly ServiceRepository $services,
        private readonly TimeSlotRepository $slots,
        private readonly OfferRepository $offers,
        private readonly pricingService $pricing,
        private readonly PointsPolicy $points,
        private readonly coverageRequestCrudService $coverage,
    ) {}

    /**
     * Everything the form draws before a customer is chosen.
     *
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        $this->refuseInsideALaundry();

        return [
            'services' => $this->services->allActive(),
            // The app's own price list, per service — the figures the customer
            // would see on their phone, platform fee and any rise included.
            'catalog' => $this->pricing->publicCatalog(),
            'pickupSlots' => $this->slots->activeFor('pickup'),
            'deliverySlots' => $this->slots->activeFor('delivery'),
            'offers' => $this->offers->live(),
            'paymentMethods' => PaymentMethod::cases(),
            'pointsEnabled' => $this->points->enabled(),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    public function customers(?string $term): Collection
    {
        $this->refuseInsideALaundry();

        return $this->users->activeCustomersMatching($term);
    }

    /**
     * A customer's saved addresses, default first — the zone loaded for
     * `isCovered()`, so the screen can say which ones an order would be refused for.
     *
     * @return Collection<int, Address>
     */
    public function addressesOf(int $customerId): Collection
    {
        return $this->customer($customerId)
            ->addresses()
            ->with(['zone.city'])
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data  validated by `AddressRequest`
     */
    public function addAddress(int $customerId, array $data): Address
    {
        return $this->book->add($this->customer($customerId), $data)->load('zone.city');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function quote(array $data): array
    {
        $customer = $this->customer((int) ($data['user_id'] ?? 0));
        unset($data['user_id']);

        return $this->orders->quote($customer, $data);
    }

    /**
     * An address we do not serve is recorded for «خارج التغطية» as the app's
     * refusal is — on the submit only, not on every re-quote, or one phone
     * call would count as a dozen attempts.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $photos
     */
    public function place(array $data, User $operator, array $photos = []): Order
    {
        $customer = $this->customer((int) ($data['user_id'] ?? 0));
        unset($data['user_id'], $data['photos']);

        try {
            $order = $this->orders->place($customer, $data, true, $operator);
        } catch (OutOfCoverage $e) {
            foreach ($e->addresses as $address) {
                $this->coverage->record($customer, $address);
            }

            throw $e;
        }

        $this->orders->attachStainPhotos($order, $photos, $operator);

        return $order;
    }

    /**
     * An active customer, or `customer_not_found`. Refuses inside a laundry
     * first, so no path below reaches a customer's data from a tenant account.
     */
    public function customer(int $id): User
    {
        $this->refuseInsideALaundry();

        return $this->users->findActiveCustomer($id)
            ?? throw new RuntimeException('customer_not_found');
    }

    private function refuseInsideALaundry(): void
    {
        if (LaundryContext::currentId() !== null) {
            throw new RuntimeException('not_yours_to_place');
        }
    }
}
