<?php

namespace App\Services\Sms;

/**
 * The seam between the application and whichever SMS vendor is chosen.
 *
 * Call sites depend only on this contract, so wiring a real Egyptian provider
 * later is a new class plus one line of config — no controller or service
 * changes. Same approach as the payment driver planned for P9.
 */
interface SmsSender
{
    /**
     * Send a message to a single number.
     *
     * Returns false rather than throwing when the vendor rejects the send, so a
     * caller can decide whether that is fatal. Delivery is never guaranteed by a
     * successful return — carriers report asynchronously.
     */
    public function send(string $phone, string $message): bool;
}
