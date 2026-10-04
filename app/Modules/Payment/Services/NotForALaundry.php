<?php

namespace App\Modules\Payment\Services;

use RuntimeException;

/**
 * Somebody inside a laundry asked to act on a driver's cash.
 *
 * Its own class rather than a message on `RuntimeException`, so the controller
 * can answer 403 for this and nothing else: a lock timeout is a
 * `QueryException`, which is a `RuntimeException` too, and must not read as
 * «forbidden».
 */
class NotForALaundry extends RuntimeException {}
