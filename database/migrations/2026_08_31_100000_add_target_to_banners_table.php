<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give a banner somewhere to point.
 *
 * The design's «عرض التفاصيل» button had no target in the schema, so every banner
 * operations published was decoration. Two columns rather than one free URL: the
 * kind is a closed set (see BannerTarget) so the app can route in-app and so it
 * stays possible to ask whether a banner produced an order.
 *
 * `target_value` is a string, not a foreign key, because it holds either a service
 * id or a coupon code depending on the kind. A constraint cannot cover both, so
 * the service validates it instead — and a deleted service leaves a banner whose
 * button does nothing rather than a broken delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('target_type')->default('none')->after('description');
            $table->string('target_value')->nullable()->after('target_type');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn(['target_type', 'target_value']);
        });
    }
};
