<?php

namespace App\Modules\Order\Enums;

/**
 * What to do with an order when every laundry covering its zone has already
 * filled its intake for the window the customer chose.
 *
 * Three answers, chosen on the general settings screen, because which one is
 * right is a commercial decision and not a technical one. None of them refuses
 * the order at submit: a customer who has filled in a basket, picked a window
 * and pressed the button is not the right person to absorb our capacity
 * planning.
 */
enum SlotOverflowBehavior: string
{
    /**
     * Take the order and leave it unassigned for operations to place.
     *
     * The default, and the behaviour the codebase already had for a zone no
     * laundry covered — so an install that sets capacities and changes nothing
     * else gets a familiar queue rather than a new one.
     */
    case Unassigned = 'unassigned';

    /**
     * Give it to the nearest laundry regardless, letting it run over.
     *
     * For an operation that would rather a laundry worked late than a customer
     * waited on a human. The capacity figure stays meaningful as a ranking
     * input; it just stops being a ceiling.
     */
    case Nearest = 'nearest';

    /**
     * Do not offer the window to the customer at all.
     *
     * The cleanest experience and the only one that needs the mobile apps:
     * `GET /api/v1/time-slots` has to send `address_id` for the server to know
     * which zone — and therefore which laundries — a window should be measured
     * against. Until an app sends it, this behaves exactly like `Unassigned`,
     * which is stated on the settings field rather than left to be discovered.
     */
    case HideSlot = 'hide_slot';

    public static function current(): self
    {
        return self::tryFrom((string) getSettingValue('Slot_Overflow_Behavior')) ?? self::Unassigned;
    }

    /**
     * Whether a full laundry may still be handed the order.
     */
    public function allowsOverflow(): bool
    {
        return $this === self::Nearest;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unassigned => __('Accept the order unassigned, for operations to place'),
            self::Nearest => __('Give it to the nearest laundry anyway'),
            self::HideSlot => __('Hide the full window from the customer (needs app support)'),
        };
    }
}
