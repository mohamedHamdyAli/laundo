<?php

use App\Models\Language;
use App\Models\Role;
use App\Modules\Banner\Models\banner;
use App\Modules\City\Models\City;
use App\Modules\Complaint\Models\Complaint;
use App\Modules\Country\Models\Country;
use App\Modules\Coupon\Models\Coupon;
use App\Modules\Driver\Models\Driver;
use App\Modules\Faq\Models\Faq;
use App\Modules\Intro\Models\intro;
use App\Modules\JourneyStep\Models\JourneyStep;
use App\Modules\Offer\Models\Offer;
use App\Modules\Item\Models\Item;
use App\Modules\ItemCategory\Models\ItemCategory;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\LaundryService\Models\LaundryService;
use App\Modules\LaundryStaff\Models\LaundryStaff;
use App\Modules\LaundryZone\Models\LaundryZone;
use App\Modules\Moderator\Models\Moderator;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRating;
use App\Modules\Order\Models\OrderRecurrence;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Models\Refund;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Report\Models\Report;
use App\Modules\Service\Models\Service;
use App\Modules\Setting\Models\Setting;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Zone\Models\Zone;

return [
    'models' => [
        User::class,
        banner::class,
        Language::class,
        City::class,
        Country::class,
        intro::class,
        Setting::class,
        Role::class,
        Moderator::class,
        Laundry::class,
        LaundryStaff::class,
        Service::class,
        ItemCategory::class,
        Item::class,
        ItemPrice::class,
        LaundryService::class,
        Zone::class,
        TimeSlot::class,
        LaundryZone::class,
        Driver::class,
        Order::class,
        Coupon::class,
        Offer::class,
        JourneyStep::class,
        Refund::class,
        Wallet::class,
        NotificationLog::class,
        Report::class,
        OrderRecurrence::class,
        OrderRating::class,
        Faq::class,
        Complaint::class,
        Payment::class,
        DriverEarning::class,
    ],
];
