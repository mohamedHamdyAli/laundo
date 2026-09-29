<?php

namespace App\Support\Geo;

/**
 * A zone's boundary as the owner drew it on the map — a closed ring of
 * [lat, lng] corners.
 *
 * Plain arithmetic on the plane (longitude as x, latitude as y), not spherical
 * geometry: a delivery zone is a few kilometres across, where the difference
 * is centimetres, and it keeps the whole thing in PHP rather than in a spatial
 * extension the MariaDB on the box may not have. The ring is stored as it was
 * drawn; the last corner joins the first.
 *
 * **Touching is not overlapping.** Neighbouring zones are drawn along the same
 * street, the drawing tool snaps a corner onto a neighbour's corner or edge,
 * and the corners are stored to seven decimal places — so a shared border is
 * two edges within a hair of each other, not exactly on each other. Anything
 * within TOLERANCE (about ten centimetres) of a border counts as on it.
 */
final class Polygon
{
    /** A drawing, not a survey — enough corners for any street-following edge. */
    public const MAX_POINTS = 500;

    /** How close counts as touching, in degrees — about ten centimetres. */
    public const TOLERANCE = 1e-6;

    /** Why a drawing was refused. */
    public const NOT_A_SHAPE = 'not_a_shape';

    public const TOO_MANY_CORNERS = 'too_many_corners';

    /** How far inside an edge an interior sample is taken — about a metre. */
    private const PROBE = 1e-5;

    /** A ring with less area than this has none — its corners are on a line. */
    private const MIN_AREA = 1e-10;

    /** @var array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}|null */
    private ?array $box = null;

    /**
     * @param  list<array{0: float, 1: float}>  $points  [lat, lng], not closed
     */
    private function __construct(private readonly array $points) {}

    /**
     * From what the form or the database holds: a list of [lat, lng] pairs.
     * Null for anything that is not a usable ring — see problem().
     */
    public static function fromArray(mixed $raw): ?self
    {
        return self::parse($raw)[0];
    }

    /**
     * Why a drawing is not a usable ring, or null when it is: too many corners,
     * or not a shape — fewer than three corners, a coordinate off the globe,
     * two corners on the same spot, no area, edges that cross or touch, a spike
     * that doubles back on itself.
     */
    public static function problem(mixed $raw): ?string
    {
        return self::parse($raw)[1];
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    public function points(): array
    {
        return $this->points;
    }

    /**
     * @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}
     */
    public function boundingBox(): array
    {
        if ($this->box === null) {
            $lats = array_column($this->points, 0);
            $lngs = array_column($this->points, 1);

            $this->box = [
                'min_lat' => min($lats), 'max_lat' => max($lats),
                'min_lng' => min($lngs), 'max_lng' => max($lngs),
            ];
        }

        return $this->box;
    }

    /**
     * Whether a pin falls inside — ray casting. A pin within TOLERANCE of an
     * edge may land either side; a pin is a customer's thumb on a phone.
     */
    public function contains(float $lat, float $lng): bool
    {
        $box = $this->boundingBox();

        if ($lat < $box['min_lat'] || $lat > $box['max_lat'] || $lng < $box['min_lng'] || $lng > $box['max_lng']) {
            return false;
        }

        $inside = false;
        $count = count($this->points);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$latI, $lngI] = $this->points[$i];
            [$latJ, $lngJ] = $this->points[$j];

            if (($latI > $lat) !== ($latJ > $lat)
                && $lng < ($lngJ - $lngI) * ($lat - $latI) / ($latJ - $latI) + $lngI) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Whether two zones claim the same ground.
     *
     * Edges that cross, a corner well inside the other ring, or — for the
     * shapes that neither catches, the same ring saved twice or one ring whose
     * corners all sit on the other's border — a point just inside one of its
     * edges that is well inside the other ring. Sharing a border, or touching
     * at a corner, is not overlapping: that is how neighbours are drawn.
     */
    public function overlaps(self $other): bool
    {
        $a = $this->boundingBox();
        $b = $other->boundingBox();

        if ($a['max_lat'] < $b['min_lat'] - self::TOLERANCE || $b['max_lat'] < $a['min_lat'] - self::TOLERANCE
            || $a['max_lng'] < $b['min_lng'] - self::TOLERANCE || $b['max_lng'] < $a['min_lng'] - self::TOLERANCE) {
            return false;
        }

        foreach ($this->edges() as [$p, $q]) {
            foreach ($other->edges() as [$r, $s]) {
                if (self::properlyCross($p, $q, $r, $s)) {
                    return true;
                }
            }
        }

        return $this->hasSampleWellInside($other) || $other->hasSampleWellInside($this);
    }

    /**
     * @return array{0: self|null, 1: string|null}
     */
    private static function parse(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [null, self::NOT_A_SHAPE];
        }

        $points = [];

        foreach (array_values($raw) as $pair) {
            if (! is_array($pair) || count($pair) !== 2) {
                return [null, self::NOT_A_SHAPE];
            }

            [$lat, $lng] = array_values($pair);

            if (! is_numeric($lat) || ! is_numeric($lng)) {
                return [null, self::NOT_A_SHAPE];
            }

            $lat = (float) $lat;
            $lng = (float) $lng;

            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                return [null, self::NOT_A_SHAPE];
            }

            $points[] = [$lat, $lng];
        }

        // A ring drawn closed — its last corner repeating its first — is the
        // same ring.
        if (count($points) > 1 && self::distance($points[0], $points[count($points) - 1]) <= self::TOLERANCE) {
            array_pop($points);
        }

        if (count($points) > self::MAX_POINTS) {
            return [null, self::TOO_MANY_CORNERS];
        }

        if (count($points) < 3) {
            return [null, self::NOT_A_SHAPE];
        }

        $polygon = new self($points);

        return $polygon->isValidRing() ? [$polygon, null] : [null, self::NOT_A_SHAPE];
    }

    /**
     * One shape with an inside: no two corners on the same spot, some area,
     * no edge touching or crossing another it does not share a corner with,
     * and no corner where the ring turns straight back on itself.
     */
    private function isValidRing(): bool
    {
        $count = count($this->points);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (self::distance($this->points[$i], $this->points[$j]) <= self::TOLERANCE) {
                    return false;
                }
            }
        }

        if (abs($this->signedArea()) < self::MIN_AREA) {
            return false;
        }

        $edges = $this->edges();

        for ($i = 0; $i < $count; $i++) {
            // A spike: the next edge runs back along this one.
            [$a, $b] = $edges[$i];
            $c = $edges[($i + 1) % $count][1];

            if (self::distanceToLine($c, $a, $b) <= self::TOLERANCE
                && (($b[1] - $a[1]) * ($c[1] - $b[1]) + ($b[0] - $a[0]) * ($c[0] - $b[0])) < 0) {
                return false;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                // Neighbouring edges share a corner by construction.
                if ($j === $i + 1 || ($i === 0 && $j === $count - 1)) {
                    continue;
                }

                if (self::touch($edges[$i][0], $edges[$i][1], $edges[$j][0], $edges[$j][1])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * A corner, or a point just inside an edge, that is inside the other ring
     * and not merely on its border.
     */
    private function hasSampleWellInside(self $other): bool
    {
        foreach ($this->points as [$lat, $lng]) {
            if ($other->wellInside($lat, $lng)) {
                return true;
            }
        }

        // Inward is to the left of each edge on a counter-clockwise ring.
        $inward = $this->signedArea() > 0 ? 1.0 : -1.0;

        foreach ($this->edges() as [$p, $q]) {
            $length = self::distance($p, $q);

            if ($length <= self::TOLERANCE) {
                continue;
            }

            // Unit normal on the plane (x = lng, y = lat): (-dy, dx).
            $dx = ($q[1] - $p[1]) / $length;
            $dy = ($q[0] - $p[0]) / $length;
            $lat = ($p[0] + $q[0]) / 2 + $inward * $dx * self::PROBE;
            $lng = ($p[1] + $q[1]) / 2 - $inward * $dy * self::PROBE;

            if ($other->wellInside($lat, $lng)) {
                return true;
            }
        }

        return false;
    }

    private function wellInside(float $lat, float $lng): bool
    {
        if (! $this->contains($lat, $lng)) {
            return false;
        }

        foreach ($this->edges() as [$p, $q]) {
            if (self::distanceToSegment([$lat, $lng], $p, $q) <= self::TOLERANCE) {
                return false;
            }
        }

        return true;
    }

    /**
     * Twice the signed area, x = lng and y = lat: positive counter-clockwise.
     */
    private function signedArea(): float
    {
        $sum = 0.0;
        $count = count($this->points);

        for ($i = 0; $i < $count; $i++) {
            [$y1, $x1] = $this->points[$i];
            [$y2, $x2] = $this->points[($i + 1) % $count];
            $sum += $x1 * $y2 - $x2 * $y1;
        }

        return $sum;
    }

    /**
     * @return list<array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}>
     */
    private function edges(): array
    {
        $edges = [];
        $count = count($this->points);

        for ($i = 0; $i < $count; $i++) {
            $edges[] = [$this->points[$i], $this->points[($i + 1) % $count]];
        }

        return $edges;
    }

    /**
     * Two segments cross properly — each separates the other's ends by more
     * than TOLERANCE. Touching, sharing a corner or running along each other
     * is not crossing.
     *
     * @param  array{0: float, 1: float}  $p
     * @param  array{0: float, 1: float}  $q
     * @param  array{0: float, 1: float}  $r
     * @param  array{0: float, 1: float}  $s
     */
    private static function properlyCross(array $p, array $q, array $r, array $s): bool
    {
        $d1 = self::side($r, $s, $p);
        $d2 = self::side($r, $s, $q);
        $d3 = self::side($p, $q, $r);
        $d4 = self::side($p, $q, $s);

        return $d1 * $d2 < 0 && $d3 * $d4 < 0;
    }

    /**
     * Two segments meet at all — cross, or one's end within TOLERANCE of the
     * other. For edges of one ring that share no corner, meeting is folding.
     *
     * @param  array{0: float, 1: float}  $p
     * @param  array{0: float, 1: float}  $q
     * @param  array{0: float, 1: float}  $r
     * @param  array{0: float, 1: float}  $s
     */
    private static function touch(array $p, array $q, array $r, array $s): bool
    {
        return self::properlyCross($p, $q, $r, $s)
            || self::distanceToSegment($p, $r, $s) <= self::TOLERANCE
            || self::distanceToSegment($q, $r, $s) <= self::TOLERANCE
            || self::distanceToSegment($r, $p, $q) <= self::TOLERANCE
            || self::distanceToSegment($s, $p, $q) <= self::TOLERANCE;
    }

    /**
     * Which side of the line a→b the point is on: +1, -1, or 0 within
     * TOLERANCE of it.
     *
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     * @param  array{0: float, 1: float}  $c
     */
    private static function side(array $a, array $b, array $c): int
    {
        if (self::distanceToLine($c, $a, $b) <= self::TOLERANCE) {
            return 0;
        }

        $cross = ($b[1] - $a[1]) * ($c[0] - $a[0]) - ($b[0] - $a[0]) * ($c[1] - $a[1]);

        return $cross > 0 ? 1 : -1;
    }

    /**
     * @param  array{0: float, 1: float}  $c
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     */
    private static function distanceToLine(array $c, array $a, array $b): float
    {
        $length = self::distance($a, $b);

        if ($length == 0.0) {
            return self::distance($a, $c);
        }

        return abs(($b[1] - $a[1]) * ($c[0] - $a[0]) - ($b[0] - $a[0]) * ($c[1] - $a[1])) / $length;
    }

    /**
     * @param  array{0: float, 1: float}  $c
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     */
    private static function distanceToSegment(array $c, array $a, array $b): float
    {
        $dx = $b[1] - $a[1];
        $dy = $b[0] - $a[0];
        $lengthSquared = $dx * $dx + $dy * $dy;

        if ($lengthSquared == 0.0) {
            return self::distance($a, $c);
        }

        $t = max(0.0, min(1.0, (($c[1] - $a[1]) * $dx + ($c[0] - $a[0]) * $dy) / $lengthSquared));

        return self::distance($c, [$a[0] + $t * $dy, $a[1] + $t * $dx]);
    }

    /**
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     */
    private static function distance(array $a, array $b): float
    {
        return hypot($a[0] - $b[0], $a[1] - $b[1]);
    }
}
