<?php

namespace AlwaysCurious\Vin\Vehicle;

use AlwaysCurious\Vin\VehicleData;

/**
 * Shared row-field extraction for {@see VehicleData} and the typed
 * attribute groups. Every NHTSA "DecodeVinValues" value arrives as a string (or blank);
 * these helpers give one place to trim it, treat blanks as null, and coerce the numeric
 * fields without turning a blank or non-numeric value into a bogus 0.
 */
trait ParsesNhtsaFields
{
    /**
     * Trimmed string value for a row key, or null when blank/absent.
     *
     * @param  array<string, mixed>  $row
     */
    protected static function str(array $row, string $key): ?string
    {
        $raw = $row[$key] ?? null;

        return filled($raw) ? trim((string) $raw) : null;
    }

    /**
     * Integer value for a row key, or null when blank/absent/non-numeric.
     *
     * @param  array<string, mixed>  $row
     */
    protected static function int(array $row, string $key): ?int
    {
        $value = static::str($row, $key);

        return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    /**
     * Float value for a row key, or null when blank/absent/non-numeric.
     *
     * @param  array<string, mixed>  $row
     */
    protected static function float(array $row, string $key): ?float
    {
        $value = static::str($row, $key);

        return $value !== null && is_numeric($value) ? (float) $value : null;
    }
}
