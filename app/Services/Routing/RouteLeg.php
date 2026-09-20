<?php

namespace App\Services\Routing;

/**
 * One measured journey: how far, and how long.
 *
 * `minutes` is nullable and that is the whole point of this being an object
 * rather than a float. A road provider answers both questions; the haversine
 * fallback can only answer the first, and a duration invented from a straight
 * line and an assumed speed is a number that would be shown to an operator as
 * though somebody had measured it.
 *
 * `source` says which of those produced this leg, so a screen can mark an
 * estimate as an estimate instead of quietly presenting it as a road distance.
 */
final class RouteLeg
{
    public const SOURCE_GOOGLE = 'google';

    public const SOURCE_HAVERSINE = 'haversine';

    public function __construct(
        public readonly float $km,
        public readonly ?float $minutes,
        public readonly string $source,
    ) {}

    public static function road(float $km, ?float $minutes): self
    {
        return new self(round($km, 3), $minutes === null ? null : round($minutes, 1), self::SOURCE_GOOGLE);
    }

    public static function straightLine(float $km): self
    {
        return new self(round($km, 3), null, self::SOURCE_HAVERSINE);
    }

    /**
     * True when this is a straight line standing in for a road we could not
     * measure. Screens draw it differently; the fee logs it.
     */
    public function isEstimate(): bool
    {
        return $this->source === self::SOURCE_HAVERSINE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'km' => $this->km,
            'minutes' => $this->minutes,
            'source' => $this->source,
            'estimate' => $this->isEstimate(),
        ];
    }

    /**
     * Rebuild from a cached array.
     *
     * The cache holds arrays rather than serialized instances on purpose. A
     * serialized object carries this class's shape with it, so adding a property
     * would leave every entry written before the deploy unserializing into an
     * instance with an uninitialized readonly property — a fatal error on every
     * cache hit, for as long as the entries live. An array cannot do that: a key
     * that is not there reads as null.
     *
     * @param  array<string, mixed>  $cached
     */
    public static function fromArray(array $cached): ?self
    {
        if (! isset($cached['km']) || ! is_numeric($cached['km'])) {
            return null;
        }

        $minutes = $cached['minutes'] ?? null;

        return new self(
            (float) $cached['km'],
            is_numeric($minutes) ? (float) $minutes : null,
            is_string($cached['source'] ?? null) ? $cached['source'] : self::SOURCE_HAVERSINE,
        );
    }
}
