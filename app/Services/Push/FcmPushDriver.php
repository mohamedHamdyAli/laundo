<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging, HTTP v1.
 *
 * Written against Google's API directly rather than through `kreait/firebase-php`.
 * The whole of what is needed is an RS256 JWT signed with `openssl`, one token
 * exchange, and one POST — perhaps sixty lines. Pulling in a large dependency
 * tree for that is a decision that belongs to whoever maintains this project, and
 * it is avoidable.
 *
 * Two details that are easy to get wrong and expensive to debug:
 *
 *  - **The access token is cached.** Google issues one per hour; minting a fresh
 *    JWT per notification would add a round trip to every send and hit the token
 *    endpoint's rate limit on any real volume.
 *
 *  - **A 404 or 403 from the send endpoint means the token is dead**, and a 5xx
 *    means Google is busy. Treating the second as permanent deletes working
 *    devices; treating the first as transient guarantees a failure on every
 *    future send forever. `lastFailureWasPermanent()` is how the caller tells
 *    them apart.
 */
class FcmPushDriver implements PushSender
{
    private const TOKEN_CACHE_KEY = 'fcm_access_token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private bool $permanentFailure = false;

    public function send(string $token, string $title, string $body, array $data = []): bool
    {
        $this->permanentFailure = false;

        $credentials = $this->credentials();
        $projectId = $credentials['project_id'] ?? null;

        if (! $projectId) {
            Log::error('[FCM] service account has no project_id');

            return false;
        }

        try {
            $accessToken = $this->accessToken($credentials);
        } catch (RuntimeException $e) {
            Log::error('[FCM] could not obtain an access token', ['error' => $e->getMessage()]);

            return false;
        }

        $response = Http::withToken($accessToken)
            ->timeout(config('push.timeout'))
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    // FCM requires every data value to be a string; an int here
                    // is rejected by the API with an unhelpful message.
                    'data' => array_map(fn ($v) => (string) $v, $data),
                    'android' => ['priority' => 'high'],
                    'apns' => ['headers' => ['apns-priority' => '10']],
                ],
            ]);

        if ($response->successful()) {
            return true;
        }

        // UNREGISTERED / INVALID_ARGUMENT on the token mean this device is gone.
        $this->permanentFailure = in_array($response->status(), [400, 403, 404], true);

        Log::warning('[FCM] send failed', [
            'status' => $response->status(),
            'permanent' => $this->permanentFailure,
            'body' => $response->json() ?? $response->body(),
        ]);

        return false;
    }

    public function lastFailureWasPermanent(): bool
    {
        return $this->permanentFailure;
    }

    /**
     * A Google OAuth2 access token, cached until just before it expires.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function accessToken(array $credentials): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $assertion = $this->signedAssertion($credentials);

        $response = Http::asForm()
            ->timeout(config('push.timeout'))
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('token endpoint returned '.$response->status());
        }

        $token = $response->json('access_token');
        $expires = (int) ($response->json('expires_in') ?? 3600);

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('token endpoint returned no access_token');
        }

        // Sixty seconds of headroom: a token that expires mid-flight fails the
        // send it was fetched for.
        Cache::put(self::TOKEN_CACHE_KEY, $token, max($expires - 60, 60));

        return $token;
    }

    /**
     * The RS256 JWT Google exchanges for an access token.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function signedAssertion(array $credentials): string
    {
        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        $claims = [
            'iss' => $credentials['client_email'] ?? '',
            'scope' => self::SCOPE,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $unsigned = $this->base64Url(json_encode($header))
            .'.'.$this->base64Url(json_encode($claims));

        $key = openssl_pkey_get_private((string) ($credentials['private_key'] ?? ''));

        if ($key === false) {
            throw new RuntimeException('the service account private key could not be read');
        }

        $signature = '';

        if (! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('could not sign the assertion');
        }

        return $unsigned.'.'.$this->base64Url($signature);
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        $path = config('push.fcm.credentials');

        if (! $path || ! file_exists($path)) {
            // Loud rather than silent: a deploy without credentials should be
            // obvious, not a system that quietly notifies nobody.
            Log::error('[FCM] service account file not found', ['path' => $path]);

            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
