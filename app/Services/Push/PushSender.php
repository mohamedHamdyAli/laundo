<?php

namespace App\Services\Push;

/**
 * The seam between the application and whichever push service is used.
 *
 * Mirrors SmsSender deliberately: call sites depend on the contract, so the
 * vendor is a configuration detail. The difference is that push is
 * per-**device**, not per-person — one user can hold several tokens and each has
 * to be told separately.
 */
interface PushSender
{
    /**
     * Send to one device token.
     *
     * @param  array<string, string>  $data  key/value payload the app reads to
     *                                       decide where to navigate
     * @return bool false when the vendor rejected the send. Never throws for an
     *              ordinary rejection: a failed notification must not roll back
     *              the business action that triggered it.
     */
    public function send(string $token, string $title, string $body, array $data = []): bool;

    /**
     * Whether the last failure means the token is dead and should be removed.
     *
     * FCM distinguishes "this token no longer exists" from "we are busy, retry".
     * Pruning on the second would delete working devices; keeping the first
     * forever guarantees a permanent failure on every future send.
     */
    public function lastFailureWasPermanent(): bool;
}
