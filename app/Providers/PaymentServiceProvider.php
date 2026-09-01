<?php

namespace App\Providers;

use App\Modules\Payment\Contracts\PaymentGateway;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Binds whichever gateway is configured.
 *
 * The only place in the application that knows a provider's class name. Nothing
 * else type-hints anything but the contract.
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function () {
            $name = config('payments.default');
            $driver = config("payments.gateways.{$name}.driver");

            if (! $driver || ! class_exists($driver)) {
                // Loud rather than silently falling back: a misconfigured
                // provider that quietly becomes the fake one would let a
                // production deploy take no money and report success.
                throw new RuntimeException("Payment gateway [{$name}] is not configured.");
            }

            return $this->app->make($driver);
        });
    }
}
