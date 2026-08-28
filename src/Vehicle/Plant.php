<?php

namespace AlwaysCurious\Vin\Vehicle;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use JsonSerializable;

/**
 * Manufacturing plant attributes lifted from a NHTSA "DecodeVinValues" row —
 * where the vehicle was assembled. Every field is a nullable string; a blank source
 * value is null.
 *
 * @implements Arrayable<string, string|null>
 */
final readonly class Plant implements Arrayable, JsonSerializable
{
    use ParsesNhtsaFields;

    public function __construct(
        public ?string $city = null,
        public ?string $state = null,
        public ?string $country = null,
        public ?string $company = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            city: self::str($row, 'PlantCity'),
            state: self::str($row, 'PlantState'),
            country: self::str($row, 'PlantCountry'),
            company: self::str($row, 'PlantCompanyName'),
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        $values = get_object_vars($this);

        return array_combine(array_map(Str::snake(...), array_keys($values)), $values);
    }

    /**
     * @return array<string, string|null>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
