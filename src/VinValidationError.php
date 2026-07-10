<?php

namespace AlwaysCurious\Vin;

/**
 * Why an offline VIN validation ({@see VinLookupService::inspect()}) failed.
 *
 * Carried on {@see VinValidation::$errors} so a caller can tell a wrong-length VIN from one with an
 * illegal character from one with a bad check digit — and render precise form feedback — instead of
 * only knowing that "the VIN is invalid" (VIN-025). A single {@see VinValidation} can carry more
 * than one of these (e.g. a short string that also contains an illegal character).
 */
enum VinValidationError: string
{
    /** The VIN is not exactly 17 characters long. */
    case WrongLength = 'wrong_length';

    /** The VIN contains a character outside `[A-HJ-NPR-Z0-9]` — an `I`, `O`, `Q` or punctuation. */
    case IllegalCharacters = 'illegal_characters';

    /** The VIN is structurally valid but its ISO 3779 9th-position check digit does not match. */
    case InvalidCheckDigit = 'invalid_check_digit';

    /**
     * A human-readable explanation, suitable for surfacing to an end user as form feedback.
     */
    public function message(): string
    {
        return match ($this) {
            self::WrongLength => 'A VIN must be exactly 17 characters.',
            self::IllegalCharacters => 'A VIN may only contain the letters A-Z (excluding I, O and Q) and the digits 0-9.',
            self::InvalidCheckDigit => 'The VIN check digit (9th character) does not match; the VIN may be mistyped.',
        };
    }
}
