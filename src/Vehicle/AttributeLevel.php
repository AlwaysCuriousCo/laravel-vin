<?php

namespace AlwaysCurious\Vin\Vehicle;

use AlwaysCurious\Vin\VehicleData;

/**
 * How much of a decode {@see VehicleData::fromFlatResult()} hydrates.
 *
 * Lighter levels skip work and shrink the cached row: an app that only needs year/make/model
 * pays neither the group mapping nor the raw-attribute passthrough. Ordered cheapest → richest.
 *
 * Changing the level changes the stored shape, so pair a change with a `vin.cache.version`
 * bump (VIN-007) to re-decode already-cached VINs at the new level.
 */
enum AttributeLevel: string
{
    /**
     * The default. Core identity only — year, make, model, trim, body class, vehicle type,
     * manufacturer (plus VIN and decode status, which are always present). `series` and the
     * groups are present-but-empty; the raw bag is empty.
     */
    case Identity = 'identity';

    /** Core identity plus `series` and the typed engine/safety/body/plant groups. No raw passthrough. */
    case Typed = 'typed';

    /** Everything at `Typed`, plus the full raw attribute passthrough (every NHTSA field). */
    case Full = 'full';

    /**
     * Resolve a config value to a level, falling back to {@see self::Full} for a
     * missing or unrecognized value — the safe default (more data, never less).
     */
    public static function fromConfig(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Full) : self::Full;
    }

    /** Whether this level hydrates the typed attribute groups. */
    public function includesGroups(): bool
    {
        return $this !== self::Identity;
    }

    /** Whether this level hydrates the raw attribute passthrough. */
    public function includesRawAttributes(): bool
    {
        return $this === self::Full;
    }
}
