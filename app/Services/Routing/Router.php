<?php

namespace App\Services\Routing;

/**
 * The seam between the application and whichever distance provider is used.
 *
 * Mirrors `PushSender` deliberately — call sites depend on the contract and the
 * vendor is a configuration detail.
 *
 * The contract is a **matrix**, not a pair, because every question this codebase
 * asks is one origin against several destinations: which of these laundries is
 * nearest, which of these laundries is this driver nearest to. Providers bill
 * and rate-limit per request, so asking once for five destinations is not an
 * optimisation, it is the difference between one call and five.
 */
interface Router
{
    /**
     * Measure the origin against each destination.
     *
     * @param  array<array-key, Coordinate>  $destinations
     * @return array<array-key, RouteLeg|null> keyed exactly as `$destinations`;
     *                                         null for a leg this provider could
     *                                         not measure. Never throws for an
     *                                         ordinary provider failure — the
     *                                         caller substitutes an estimate, and
     *                                         a vendor outage must not take down
     *                                         order placement.
     */
    public function matrix(Coordinate $origin, array $destinations): array;

    /**
     * What `RouteLeg::$source` this provider stamps on its results.
     */
    public function source(): string;
}
