<?php

namespace App\Modules\Order\Services;

use RuntimeException;

/**
 * What `OrderService`'s failure codes mean, said once.
 *
 * The service throws codes rather than sentences on purpose: it has no business
 * knowing about HTTP or the screen asking. Two callers turn them into words —
 * the app's `Api\V1\OrderController` (an envelope: 422, 404 or a message) and
 * the panel's «إنشاء طلب» (a 422 beside the field). They used to carry a copy
 * of the mapping each, and a code added to one was the code the other answered
 * with «We could not complete your order.» (code review, 2026-11-08).
 *
 * `kind` is what the code is, not how to answer it: `validation` (the request
 * is wrong, against `field`), `not_found` (an id that is not the caller's —
 * the app answers 404 so an id says nothing about whose it is), `message`
 * (nothing to point at).
 */
final class PlacementFailure
{
    /**
     * @return array{kind: 'validation'|'not_found'|'message', field: string|null, message: string}
     */
    public static function describe(RuntimeException $e): array
    {
        $code = $e->getMessage();

        // The turnaround and closed-window backstops carry their own sentence.
        foreach (['delivery_date', 'pickup_slot_id', 'delivery_slot_id'] as $field) {
            if (str_starts_with($code, $field.':')) {
                return self::make('validation', $field, substr($code, strlen($field.':')));
            }
        }

        if (str_starts_with($code, 'unpriced_items:')) {
            return self::make('validation', 'items', __('Some pieces are not available for this service.'));
        }

        return match ($code) {
            'service_not_found' => self::make('not_found', 'service_id', __('Service not found.')),
            'pickup_address_not_found' => self::make('not_found', 'pickup_address_id', __('Pickup address not found.')),
            'delivery_address_not_found' => self::make('not_found', 'delivery_address_id', __('Delivery address not found.')),
            'empty_basket' => self::make('validation', 'items', __('Please add at least one piece.')),
            'not_cancellable' => self::make('message', null, __('This order can no longer be cancelled.')),
            // Another checkout spent the points between this one's quote and
            // its submit. Named against the field so the caller can re-quote.
            'points_changed' => self::make('validation', 'use_points', __('Your points balance has changed. Please review your order and try again.')),
            'slot_full' => self::make('validation', 'pickup_slot_id', __('This window is fully booked. Please choose another one.')),
            // «التحكم في عدد القطع» (item 19, 2026-11-08) — `PieceLimits`.
            'too_many_pieces' => self::make('validation', 'items', __('One order can carry up to :max pieces.', ['max' => app(PieceLimits::class)->perOrder() ?? 0])),
            'window_pieces_full' => self::make('validation', 'pickup_slot_id', __('This pickup window is fully booked. Please choose another one.')),
            default => self::make('message', null, __('We could not complete your order.')),
        };
    }

    /**
     * @param  'validation'|'not_found'|'message'  $kind
     * @return array{kind: 'validation'|'not_found'|'message', field: string|null, message: string}
     */
    private static function make(string $kind, ?string $field, string $message): array
    {
        return ['kind' => $kind, 'field' => $field, 'message' => $message];
    }
}
