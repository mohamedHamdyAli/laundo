<?php

use App\Providers\ActivityLogServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\PaymentServiceProvider;
use App\Providers\ViewServiceProvider;

return [
    AppServiceProvider::class,
    ActivityLogServiceProvider::class,
    PaymentServiceProvider::class,
    ViewServiceProvider::class,
];
