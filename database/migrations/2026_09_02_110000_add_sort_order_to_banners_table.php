<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets operations order the hero carousel.
 *
 * `ContentController@banners` ordered by `latest('id')`, so the sequence the
 * customer swipes through was whichever order the banners happened to be
 * created in, newest first, and there was no way to change it from the panel.
 * `intros` has carried an `order` column since it was built; this is the same
 * idea under the name the newer tables use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('target_value');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
