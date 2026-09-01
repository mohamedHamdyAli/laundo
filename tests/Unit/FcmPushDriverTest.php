<?php

namespace Tests\Unit;

use App\Services\Push\FcmPushDriver;
use App\Services\Push\LogPushDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The FCM driver, exercised against a faked Google.
 *
 * A real send needs a Firebase service account this environment does not have, so
 * what is provable here is everything up to the wire: that the assertion is a
 * well-formed RS256 JWT, that the access token is cached rather than re-minted
 * per notification, that data values are stringified the way FCM demands, and —
 * the one that costs real devices if it is wrong — that a permanent rejection is
 * told apart from a transient one.
 */
class FcmPushDriverTest extends TestCase
{
    // No RefreshDatabase: nothing here touches a table. The driver's only stateful
    // dependency is the cache, and that is pointed at the array store below so a
    // token cached in one test cannot leak into the next.
    private string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway service account, generated rather than committed — a real
        // private key does not belong in a repository even as a fixture.
        //
        // Windows PHP ships an openssl.cnf it does not point at by default, so
        // key *generation* fails with a config error out of the box. Loading and
        // signing — which is all the driver ever does — need no config at all, so
        // this only affects the fixture.
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $bundled = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'
            .DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';

        if (file_exists($bundled)) {
            $options['config'] = $bundled;
        }

        $key = @openssl_pkey_new($options);

        if ($key === false) {
            $this->markTestSkipped('OpenSSL cannot generate a key here: '.openssl_error_string());
        }

        @openssl_pkey_export($key, $pem, null, $options);

        $this->keyPath = storage_path('app/test-firebase.json');

        file_put_contents($this->keyPath, json_encode([
            'type' => 'service_account',
            'project_id' => 'laundo-test',
            'client_email' => 'push@laundo-test.iam.gserviceaccount.com',
            'private_key' => $pem,
        ]));

        config()->set('push.fcm.credentials', $this->keyPath);
        config()->set('push.timeout', 5);
        config()->set('cache.default', 'array');

        Cache::flush();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->keyPath)) {
            unlink($this->keyPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_signs_a_well_formed_jwt_and_sends_to_the_project_endpoint(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/laundo-test/messages/1']),
        ]);

        $sent = (new FcmPushDriver)->send('tok-1', 'عنوان', 'نص', ['order_id' => 42]);

        $this->assertTrue($sent);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'oauth2.googleapis.com')) {
                return false;
            }

            $assertion = $request['assertion'] ?? '';
            $parts = explode('.', $assertion);

            if (count($parts) !== 3) {
                return false;
            }

            $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
            $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

            return $header['alg'] === 'RS256'
                && $claims['aud'] === 'https://oauth2.googleapis.com/token'
                && str_contains($claims['scope'], 'firebase.messaging')
                && $claims['exp'] > $claims['iat'];
        });

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'fcm.googleapis.com')) {
                return false;
            }

            $message = $request['message'];

            return str_contains($request->url(), 'projects/laundo-test/messages:send')
                && $message['token'] === 'tok-1'
                && $message['notification']['title'] === 'عنوان'
                // FCM rejects a non-string data value with an unhelpful error.
                && $message['data']['order_id'] === '42'
                && is_string($message['data']['event'] ?? '');
        });
    }

    #[Test]
    public function the_access_token_is_minted_once_and_reused(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
        ]);

        $driver = new FcmPushDriver;

        foreach (range(1, 4) as $i) {
            $driver->send("tok-{$i}", 'title', 'body');
        }

        // A JWT per notification adds a round trip to every send and hits the
        // token endpoint's rate limit on any real volume.
        $tokenCalls = 0;

        Http::assertSent(function ($request) use (&$tokenCalls) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                $tokenCalls++;
            }

            return true;
        });

        $this->assertSame(1, $tokenCalls);
    }

    /**
     * Split per status with a provider rather than looped in one test: repeated
     * Http::fake() calls *merge* their stubs, so a 403 registered by an earlier
     * iteration kept matching and the loop silently tested the same response
     * three times. It looked like a driver bug and was a test bug.
     */
    #[Test]
    #[DataProvider('permanentStatuses')]
    public function a_dead_token_is_permanent(int $status): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNREGISTERED']], $status),
        ]);

        $driver = new FcmPushDriver;

        $this->assertFalse($driver->send('tok-dead', 'a', 'b'));
        // Keeping a dead token guarantees a failure on every future send.
        $this->assertTrue($driver->lastFailureWasPermanent(), "status {$status} should be permanent");
    }

    #[Test]
    #[DataProvider('transientStatuses')]
    public function a_busy_google_is_not_permanent(int $status): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['error' => 'busy'], $status),
        ]);

        $driver = new FcmPushDriver;

        $this->assertFalse($driver->send('tok-busy', 'a', 'b'));
        // Deleting this one would silence a working handset.
        $this->assertFalse($driver->lastFailureWasPermanent(), "status {$status} should be transient");
    }

    /**
     * @return array<string, array<int, int>>
     */
    public static function permanentStatuses(): array
    {
        return ['404 gone' => [404], '400 bad token' => [400], '403 forbidden' => [403]];
    }

    /**
     * @return array<string, array<int, int>>
     */
    public static function transientStatuses(): array
    {
        return ['500 server error' => [500], '503 unavailable' => [503], '429 rate limited' => [429]];
    }

    #[Test]
    public function a_missing_service_account_fails_loudly_rather_than_pretending(): void
    {
        config()->set('push.fcm.credentials', storage_path('app/nope.json'));

        Http::fake();

        $driver = new FcmPushDriver;

        // A deploy that never got its credentials must not look like a system
        // that is notifying people.
        $this->assertFalse($driver->send('tok', 'a', 'b'));
        Http::assertNothingSent();
    }

    #[Test]
    public function a_token_failure_does_not_reach_the_send_endpoint(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->assertFalse((new FcmPushDriver)->send('tok', 'a', 'b'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com'));
    }

    #[Test]
    public function the_log_driver_sends_nothing_and_says_so(): void
    {
        Http::fake();

        $driver = new LogPushDriver;

        // The development default: every path exercisable without a vendor.
        $this->assertTrue($driver->send('tok', 'title', 'body'));
        $this->assertFalse($driver->lastFailureWasPermanent());

        Http::assertNothingSent();
    }
}
