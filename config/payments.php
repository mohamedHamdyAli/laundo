<?php

use App\Modules\Payment\Gateways\FakeGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Default gateway
    |--------------------------------------------------------------------------
    |
    | `fake` until a provider is chosen. It is a real implementation of the
    | contract — it issues references and waits to be told by a webhook, exactly
    | as a hosted checkout does — so every path is exercisable now and choosing
    | Paymob or Fawry later is a new class rather than a new flow.
    |
    */

    'default' => env('PAYMENT_GATEWAY', 'fake'),

    'currency' => env('PAYMENT_CURRENCY', 'EGP'),

    'gateways' => [
        'fake' => [
            'driver' => FakeGateway::class,
        ],
    ],

];
