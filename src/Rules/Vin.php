<?php

namespace AlwaysCurious\Vin\Rules;

use AlwaysCurious\Vin\Support\VinCheckDigit;
use AlwaysCurious\Vin\VinLookupService;
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
        $vin = is_string($value) ? VinLookupService::normalize($value) : '';

        if (! preg_match(VinLookupService::VIN_PATTERN, $vin)) {
            $fail('The :attribute field must be a valid 17-character VIN.');

            return;
        }

        if ($this->checkDigit && ! VinCheckDigit::matches($vin)) {
            $fail('The :attribute field has an invalid VIN check digit.');
        }
    }
}
