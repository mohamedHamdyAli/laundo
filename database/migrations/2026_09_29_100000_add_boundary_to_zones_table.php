<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A zone drawn on the map.
 *
 * Zones were named areas picked from a dropdown — the table's own first
 * migration said so: «not a geofence». The owner now draws each one, and the
 * drawing decides which zone a pin is in (ZoneLocator).
 *
 * `boundary` is the ring of [lat, lng] corners as drawn. The four bounding-box
 * columns are the same drawing reduced to what SQL can filter on cheaply, so
 * finding the zone for a pin reads the one or two zones whose box contains it
 * rather than every zone's corners. Nullable throughout: a zone not drawn yet
 * keeps working the old way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->json('boundary')->nullable()->after('min_delivery_fee');
            $table->decimal('min_lat', 10, 7)->nullable()->after('boundary');
            $table->decimal('max_lat', 10, 7)->nullable()->after('min_lat');
            $table->decimal('min_lng', 10, 7)->nullable()->after('max_lat');
            $table->decimal('max_lng', 10, 7)->nullable()->after('min_lng');

            $table->index(['min_lat', 'max_lat', 'min_lng', 'max_lng'], 'zones_bbox_index');
        });
    }

    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropIndex('zones_bbox_index');
            $table->dropColumn(['boundary', 'min_lat', 'max_lat', 'min_lng', 'max_lng']);
        });
    }
};
