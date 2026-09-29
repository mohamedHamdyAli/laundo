<?php

namespace Tests\Unit;

use App\Support\Geo\Polygon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A zone's boundary as drawn on the map.
 *
 * Coordinates are around Nasr City and Maadi, so the numbers read like the
 * ones the owner will draw.
 */
class PolygonTest extends TestCase
{
    /** Roughly Nasr City: a box, corners [lat, lng]. */
    private function nasrCity(): Polygon
    {
        return Polygon::fromArray([[30.08, 31.30], [30.08, 31.36], [30.03, 31.36], [30.03, 31.30]]);
    }

    #[Test]
    public function a_pin_inside_is_inside_and_one_outside_is_not(): void
    {
        $zone = $this->nasrCity();

        $this->assertTrue($zone->contains(30.05, 31.33));
        $this->assertFalse($zone->contains(29.96, 31.25)); // Maadi
        $this->assertFalse($zone->contains(30.05, 31.40)); // east of it
    }

    #[Test]
    public function a_concave_zone_does_not_claim_its_notch(): void
    {
        // An L: the square missing its top-right quarter.
        $zone = Polygon::fromArray([
            [30.00, 31.00], [30.10, 31.00], [30.10, 31.05], [30.05, 31.05], [30.05, 31.10], [30.00, 31.10],
        ]);

        $this->assertTrue($zone->contains(30.08, 31.02));
        $this->assertTrue($zone->contains(30.02, 31.08));
        $this->assertFalse($zone->contains(30.08, 31.08));
    }

    #[Test]
    public function what_is_not_a_ring_is_refused(): void
    {
        $this->assertNull(Polygon::fromArray(null));
        $this->assertNull(Polygon::fromArray('[[30,31]]'));
        $this->assertNull(Polygon::fromArray([[30.0, 31.0], [30.1, 31.1]]));           // two corners
        $this->assertNull(Polygon::fromArray([[95.0, 31.0], [30.1, 31.1], [30.0, 31.2]])); // off the globe
        $this->assertNull(Polygon::fromArray([[30.0, 'x'], [30.1, 31.1], [30.0, 31.2]]));
        $this->assertNull(Polygon::fromArray([[30.0, 31.0], [30.0, 31.0], [30.1, 31.1], [30.0, 31.2]])); // repeated corner

        // A figure of eight has no single inside.
        $this->assertNull(Polygon::fromArray([[30.0, 31.0], [30.1, 31.1], [30.1, 31.0], [30.0, 31.1]]));
    }

    #[Test]
    public function a_ring_drawn_closed_is_the_same_ring(): void
    {
        $closed = Polygon::fromArray([[30.08, 31.30], [30.08, 31.36], [30.03, 31.36], [30.03, 31.30], [30.08, 31.30]]);

        $this->assertNotNull($closed);
        $this->assertCount(4, $closed->points());
    }

    #[Test]
    public function zones_that_claim_the_same_ground_overlap(): void
    {
        $zone = $this->nasrCity();

        // Pushed half into it.
        $this->assertTrue($zone->overlaps(Polygon::fromArray([[30.06, 31.34], [30.06, 31.40], [30.00, 31.40], [30.00, 31.34]])));
        // Wholly inside it — no edge crosses, and still the same ground.
        $this->assertTrue($zone->overlaps(Polygon::fromArray([[30.06, 31.32], [30.06, 31.34], [30.05, 31.34], [30.05, 31.32]])));
        // …and the other way round.
        $this->assertTrue(Polygon::fromArray([[30.06, 31.32], [30.06, 31.34], [30.05, 31.34], [30.05, 31.32]])->overlaps($zone));
    }

    #[Test]
    public function neighbours_drawn_along_the_same_street_do_not_overlap(): void
    {
        $zone = $this->nasrCity();

        // East of it, sharing the 31.36 border.
        $this->assertFalse($zone->overlaps(Polygon::fromArray([[30.08, 31.36], [30.08, 31.42], [30.03, 31.42], [30.03, 31.36]])));
        // Touching at one corner only.
        $this->assertFalse($zone->overlaps(Polygon::fromArray([[30.03, 31.36], [30.03, 31.40], [29.99, 31.40], [29.99, 31.36]])));
        // Far apart.
        $this->assertFalse($zone->overlaps(Polygon::fromArray([[29.98, 31.24], [29.98, 31.28], [29.94, 31.28], [29.94, 31.24]])));
    }

    #[Test]
    public function the_same_ground_is_caught_even_when_no_edge_crosses_and_no_corner_is_inside(): void
    {
        $square = Polygon::fromArray([[0.0, 0.0], [0.0, 4.0], [4.0, 4.0], [4.0, 0.0]]);

        // The same ring saved twice, either way round.
        $this->assertTrue($square->overlaps(Polygon::fromArray([[0.0, 0.0], [0.0, 4.0], [4.0, 4.0], [4.0, 0.0]])));
        $this->assertTrue($square->overlaps(Polygon::fromArray([[4.0, 0.0], [4.0, 4.0], [0.0, 4.0], [0.0, 0.0]])));
        // A diamond with its corners on the square's edges.
        $this->assertTrue($square->overlaps(Polygon::fromArray([[0.0, 2.0], [2.0, 4.0], [4.0, 2.0], [2.0, 0.0]])));
        // Half of it, as a triangle.
        $this->assertTrue($square->overlaps(Polygon::fromArray([[0.0, 0.0], [0.0, 4.0], [4.0, 4.0]])));
        // Offset rectangles sharing a strip, their corners on each other's edges.
        $this->assertTrue($square->overlaps(Polygon::fromArray([[0.0, 2.0], [0.0, 6.0], [4.0, 6.0], [4.0, 2.0]])));
    }

    #[Test]
    public function a_border_snapped_to_a_neighbour_and_rounded_is_still_a_border(): void
    {
        $zone = $this->nasrCity();

        // East of it along the 31.36 border, the shared corners stored to seven
        // places a hair inside Nasr City — what snapping and rounding produce.
        $this->assertFalse($zone->overlaps(Polygon::fromArray([
            [30.0800000, 31.3599999], [30.08, 31.42], [30.03, 31.42], [30.0300000, 31.3599999],
        ])));
    }

    #[Test]
    public function a_shape_with_no_area_or_folding_on_itself_is_refused(): void
    {
        // All on one line.
        $this->assertNull(Polygon::fromArray([[0.0, 0.0], [1.0, 1.0], [2.0, 2.0]]));
        // A spike that doubles back along an earlier edge.
        $this->assertNull(Polygon::fromArray([[0.0, 0.0], [0.0, 4.0], [0.0, 2.0], [3.0, 2.0]]));
        // A corner resting on an edge it does not belong to.
        $this->assertNull(Polygon::fromArray([[0.0, 0.0], [0.0, 4.0], [4.0, 4.0], [2.0, 4.0], [4.0, 0.0]]));
        $this->assertSame(Polygon::NOT_A_SHAPE, Polygon::problem([[0.0, 0.0], [1.0, 1.0], [2.0, 2.0]]));
    }

    #[Test]
    public function too_many_corners_is_its_own_reason(): void
    {
        $ring = [];
        for ($i = 0; $i < Polygon::MAX_POINTS + 1; $i++) {
            $angle = 2 * M_PI * $i / (Polygon::MAX_POINTS + 1);
            $ring[] = [30.0 + 0.05 * sin($angle), 31.3 + 0.05 * cos($angle)];
        }

        $this->assertSame(Polygon::TOO_MANY_CORNERS, Polygon::problem($ring));
        $this->assertNull(Polygon::problem(array_slice($ring, 0, 200)));
    }

    #[Test]
    public function the_bounding_box_is_the_corners_extremes(): void
    {
        $this->assertSame(
            ['min_lat' => 30.03, 'max_lat' => 30.08, 'min_lng' => 31.30, 'max_lng' => 31.36],
            $this->nasrCity()->boundingBox(),
        );
    }
}
