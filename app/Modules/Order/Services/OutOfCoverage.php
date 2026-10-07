<?php

namespace App\Modules\Order\Services;

use App\Modules\Address\Models\Address;
use RuntimeException;

/**
 * An order to or from an address we do not serve — a pin outside every zone
 * drawn on the map, so the address has no zone, or an address in a zone (or
 * city) the owner switched off (`Address::isCovered()`).
 *
 * Its own class rather than a message on `RuntimeException`, because it
 * carries the addresses: the app is told which field to mark, and each
 * address is recorded for «طلبات خارج التغطية» so somebody can ring the
 * customer once the area is served.
 */
class OutOfCoverage extends RuntimeException
{
    /**
     * @param  array<string, Address>  $addresses  keyed by the request field
     *                                             — `pickup_address_id`,
     *                                             `delivery_address_id`
     */
    public function __construct(public readonly array $addresses)
    {
        parent::__construct('out_of_coverage');
    }
}
