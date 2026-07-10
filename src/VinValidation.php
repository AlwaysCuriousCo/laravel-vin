<?php

namespace AlwaysCurious\Vin;

use AlwaysCurious\Vin\Facades\Vin;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The outcome of an **offline** VIN validation ({@see VinLookupService::inspect()}).
 *
 * Reports the two dimensions the package checks without a network call — structural validity
 * (charset + length) and the ISO 3779 9th-position check digit — separately, an overall {@see $valid}
 * verdict that requires both, and a typed {@see VinValidationError} per failure so a caller can tell a
 * wrong-length VIN from an illegal character from a bad check digit and render precise feedback
 * (VIN-025).
 *
 * `$structurallyValid` always equals {@see Vin::isValid()} for the same
 * input; `$valid` is the stricter "structure AND check digit" answer.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class VinValidation implements Arrayable, JsonSerializable
{
    /**
     * @param  string  $vin  The normalized (uppercased, trimmed) input that was inspected.
     * @param  list<VinValidationError>  $errors  Every failed check; empty when {@see $valid} is true.
     */
    public function __construct(
        public string $vin,
        public bool $valid,
        public bool $structurallyValid,
        public bool $checkDigitValid,
        public array $errors = [],
    ) {}

    /**
     * Whether the VIN passed every check (structurally valid AND correct check digit).
     */
    public function passes(): bool
    {
        return $this->valid;
    }

    /**
     * Whether the VIN failed any check — the inverse of {@see passes()}.
     */
    public function fails(): bool
    {
        return ! $this->valid;
    }

    /**
     * Whether a specific failure was recorded.
     */
    public function hasError(VinValidationError $error): bool
    {
        return in_array($error, $this->errors, true);
    }

    /**
     * The human-readable message for each failed check, in order.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(fn (VinValidationError $error) => $error->message(), $this->errors);
    }

    /**
     * @return array{vin: string, valid: bool, structurally_valid: bool, check_digit_valid: bool, errors: list<string>}
     */
    public function toArray(): array
    {
        return [
            'vin' => $this->vin,
            'valid' => $this->valid,
            'structurally_valid' => $this->structurallyValid,
            'check_digit_valid' => $this->checkDigitValid,
            'errors' => array_map(fn (VinValidationError $error) => $error->value, $this->errors),
        ];
    }

    /**
     * @return array{vin: string, valid: bool, structurally_valid: bool, check_digit_valid: bool, errors: list<string>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
