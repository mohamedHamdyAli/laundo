<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\TaskHold;
use RuntimeException;

/**
 * The driver's «تأكيد» was recorded as a question for the platform, not as a
 * completion (`TaskHolds`, 2026-11-08). Thrown after the hold has committed —
 * the leg is still started, and the caller says so.
 */
class TaskHeld extends RuntimeException
{
    public function __construct(public readonly TaskHold $hold)
    {
        parent::__construct('awaiting_approval');
    }
}
