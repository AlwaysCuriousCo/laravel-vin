<?php

namespace AlwaysCurious\Vin\Support;

use AlwaysCurious\Vin\VinLookupService;

/**
 * ISO 3779 / 49 CFR 565 VIN check-digit (9th position) verification for North American VINs.
 *
 * This is **opt-in** and deliberately separate from structural validity: the package's
 * {@see VinLookupService::isValid()} checks charset + length only (VIN-002),
 * because check-digit compliance is a North-American rule that some structurally-valid VINs do not
 * honor — folding it into `isValid()` would reject VINs the decoder can still decode. Use this to
 * catch a transposed or mistyped VIN *before* spending a decode call (see VIN-020).
 */
final class VinCheckDigit
{
    /** Positional weights for positions 1..17; the 9th (the check digit itself) is weighted 0. */
    private const WEIGHTS = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];

    /** Letter → numeric value per the standard transliteration table (I, O, Q never appear). */
    private const TRANSLITERATION = [
        'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'F' => 6, 'G' => 7, 'H' => 8,
        'J' => 1, 'K' => 2, 'L' => 3, 'M' => 4, 'N' => 5, 'P' => 7, 'R' => 9,
        'S' => 2, 'T' => 3, 'U' => 4, 'V' => 5, 'W' => 6, 'X' => 7, 'Y' => 8, 'Z' => 9,
    ];

    /**
     * Whether the 9th-position check digit of a VIN is correct.
     *
     * Expects an already-normalized (uppercased, trimmed) 17-character VIN; returns false for any
     * other length or for a character with no transliteration value.
     */
    public static function matches(string $vin): bool
    {
        if (strlen($vin) !== 17) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 17; $i++) {
            $char = $vin[$i];

            if (ctype_digit($char)) {
                $value = (int) $char;
            } elseif (isset(self::TRANSLITERATION[$char])) {
                $value = self::TRANSLITERATION[$char];
            } else {
                return false;
            }

            $sum += $value * self::WEIGHTS[$i];
        }

        $remainder = $sum % 11;
        $expected = $remainder === 10 ? 'X' : (string) $remainder;

        return $vin[8] === $expected;
    }
}
