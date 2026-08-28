<?php

namespace AlwaysCurious\Vin\Vehicle;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use JsonSerializable;

/**
 * Passive and active safety attributes lifted from a NHTSA "DecodeVinValues" row.
 *
 * NHTSA reports these as descriptive strings (e.g. airbag locations, "Standard"/"Optional"
 * for driver-assist systems), so every field is a nullable string; a blank source value is
 * null. Missing here means NHTSA had no value — not that the feature is absent.
 *
 * @implements Arrayable<string, string|null>
 */
final readonly class Safety implements Arrayable, JsonSerializable
{
    use ParsesNhtsaFields;

    public function __construct(
        public ?string $airbagFront = null,
        public ?string $airbagSide = null,
        public ?string $airbagCurtain = null,
        public ?string $airbagKnee = null,
        public ?string $seatbelts = null,
        public ?string $abs = null,
        public ?string $electronicStabilityControl = null,
        public ?string $tractionControl = null,
        public ?string $tpms = null,
        public ?string $rearVisibilitySystem = null,
        public ?string $forwardCollisionWarning = null,
        public ?string $laneDepartureWarning = null,
        public ?string $adaptiveCruiseControl = null,
        public ?string $blindSpotMonitoring = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            airbagFront: self::str($row, 'AirBagLocFront'),
            airbagSide: self::str($row, 'AirBagLocSide'),
            airbagCurtain: self::str($row, 'AirBagLocCurtain'),
            airbagKnee: self::str($row, 'AirBagLocKnee'),
            seatbelts: self::str($row, 'SeatBeltsAll'),
            abs: self::str($row, 'ABS'),
            electronicStabilityControl: self::str($row, 'ESC'),
            tractionControl: self::str($row, 'TractionControl'),
            tpms: self::str($row, 'TPMS'),
            // NHTSA's field for the backup/reverse camera (FMVSS 111 rear visibility).
            rearVisibilitySystem: self::str($row, 'RearVisibilitySystem'),
            forwardCollisionWarning: self::str($row, 'ForwardCollisionWarning'),
            laneDepartureWarning: self::str($row, 'LaneDepartureWarning'),
            adaptiveCruiseControl: self::str($row, 'AdaptiveCruiseControl'),
            blindSpotMonitoring: self::str($row, 'BlindSpotMon'),
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
