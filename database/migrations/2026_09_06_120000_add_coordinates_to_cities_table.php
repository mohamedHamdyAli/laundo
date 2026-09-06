<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A city needs a location before a map can be told to follow it.
 *
 * `laundries` has carried `lat`/`lng` since P2 and `cities` never did, which was
 * fine while the coordinates were two number boxes somebody pasted into. The
 * moment the form grows a map that re-centres when the city changes, "where is
 * this city" stops being trivia and becomes the thing the feature runs on.
 *
 * Same `decimal(10, 7)` as `laundries`, deliberately: seven decimal places is
 * roughly a centimetre, the two columns are compared and averaged by the same
 * code, and a narrower type here would silently round one side of that.
 *
 * Nullable, because a city added tomorrow has no coordinates until somebody
 * drops the pin — the picker falls back to the country view rather than
 * refusing to draw.
 */
return new class extends Migration
{
    /**
     * Governorate centres, keyed by the English name already in the `name` JSON.
     *
     * Matched on the decoded `en` value rather than a `LIKE` over the raw JSON:
     * the column is `text` holding `{"en":…,"ar":…}` and a LIKE would depend on
     * the encoder's spacing, which is not a thing to bet a data migration on.
     *
     * Each is the governorate's capital, which is what somebody picking a
     * laundry's position in "Dakahlia" actually means — the seat, not the
     * polygon's centroid, which for the desert governorates is nowhere anybody
     * has ever opened a shop.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const CENTRES = [
        'Cairo' => [30.0444, 31.2357],
        'Giza' => [30.0131, 31.2089],
        'Alexandria' => [31.2001, 29.9187],
        'Qalyubia' => [30.4658, 31.1837],        // Banha
        'Dakahlia' => [31.0409, 31.3785],        // Mansoura
        'Sharqia' => [30.5877, 31.5020],         // Zagazig
        'Gharbia' => [30.7865, 31.0004],         // Tanta
        'Monufia' => [30.5522, 31.0100],         // Shibin El Kom
        'Beheira' => [31.0341, 30.4682],         // Damanhur
        'Kafr El Sheikh' => [31.1117, 30.9398],
        'Damietta' => [31.4165, 31.8133],
        'Port Said' => [31.2653, 32.3019],
        'Ismailia' => [30.6043, 32.2723],
        'Suez' => [29.9668, 32.5498],
        'North Sinai' => [31.1316, 33.7984],     // Arish
        'South Sinai' => [28.2416, 33.6222],     // El Tor
        'Beni Suef' => [29.0661, 31.0994],
        'Faiyum' => [29.3084, 30.8428],
        'Minya' => [28.1099, 30.7503],
        'Asyut' => [27.1783, 31.1859],
        'Sohag' => [26.5591, 31.6957],
        'Qena' => [26.1551, 32.7160],
        'Luxor' => [25.6872, 32.6396],
        'Aswan' => [24.0889, 32.8998],
        'Red Sea' => [27.2579, 33.8116],         // Hurghada
        'New Valley' => [25.4514, 30.5464],      // Kharga
        'Matrouh' => [31.3543, 27.2373],         // Marsa Matruh
    ];

    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('country_id');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });

        // Backfill by primary key, one row at a time. A bulk `where name like`
        // is what turned a probe cleanup into a deleted governorate once
        // already; an id from the row in hand cannot hit a neighbour.
        foreach (DB::table('cities')->select('id', 'name')->orderBy('id')->get() as $city) {
            $decoded = json_decode((string) $city->name);
            $english = is_object($decoded) ? ($decoded->en ?? null) : null;

            if ($english === null || ! isset(self::CENTRES[$english])) {
                continue;
            }

            [$lat, $lng] = self::CENTRES[$english];

            DB::table('cities')->where('id', $city->id)->update([
                'lat' => $lat,
                'lng' => $lng,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng']);
        });
    }
};
