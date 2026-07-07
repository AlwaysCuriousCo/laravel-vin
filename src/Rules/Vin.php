<?php

namespace AlwaysCurious\Vin\Rules;

use AlwaysCurious\Vin\Support\VinCheckDigit;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A form-validation rule for a US VIN.
 *
 * By default it checks structure only — 17 characters excluding I/O/Q, the same check as
 * {@see \AlwaysCurious\Vin\Facades\Vin::isValid()} (VIN-019). Call {@see withCheckDigit()} to also
 * verify the ISO 3779 9th-position check digit and reject transposed/mistyped VINs before a decode
 * call (VIN-020). Input is normalized (uppercased, trimmed) before checking.
 *
 *     use AlwaysCurious\Vin\Rules\Vin;
 *
 *     $request->validate(['vin' => ['required', new Vin]]);
 *     $request->validate(['vin' => ['required', (new Vin)->withCheckDigit()]]);
 */
class Vin implements ValidationRule
{
    /** Mirrors VinLookupService::VIN_PATTERN — 17 chars, excluding I, O and Q (VIN-002). */
    private const PATTERN = '/^[A-HJ-NPR-Z0-9]{17}$/';

    public function __construct(private bool $checkDigit = false) {}

    /**
     * Additionally verify the ISO 3779 9th-position check digit.
     */
    public function withCheckDigit(bool $checkDigit = true): self
    {
        $this->checkDigit = $checkDigit;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $vin = is_string($value) ? strtoupper(trim($value)) : '';

        if (! preg_match(self::PATTERN, $vin)) {
            $fail('The :attribute field must be a valid 17-character VIN.');

            return;
        }

        if ($this->checkDigit && ! VinCheckDigit::matches($vin)) {
            $fail('The :attribute field has an invalid VIN check digit.');
        }
    }
}
