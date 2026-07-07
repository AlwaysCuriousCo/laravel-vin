<?php

namespace AlwaysCurious\Vin\Vehicle;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Engine, fuel and drivetrain attributes lifted from a NHTSA "DecodeVinValues" row.
 *
 * Every field is nullable — NHTSA populates them unevenly across VINs. Counts and
 * horsepower are ints; displacement is a float; blanks and non-numeric values are null,
 * never 0 (see VD-004).
 *
 * @implements Arrayable<string, int|float|string|null>
 */
final readonly class Engine implements Arrayable, JsonSerializable
{
    use ParsesNhtsaFields;

    public function __construct(
        public ?string $fuelTypePrimary = null,
        public ?string $fuelTypeSecondary = null,
        public ?int $cylinders = null,
        public ?float $displacementL = null,
        public ?float $displacementCc = null,
        public ?int $horsepower = null,
        public ?string $model = null,
        public ?string $configuration = null,
        public ?string $electrificationLevel = null,
        public ?string $driveType = null,
        public ?string $transmissionStyle = null,
        public ?int $transmissionSpeeds = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            fuelTypePrimary: self::str($row, 'FuelTypePrimary'),
            fuelTypeSecondary: self::str($row, 'FuelTypeSecondary'),
            cylinders: self::int($row, 'EngineCylinders'),
            displacementL: self::float($row, 'DisplacementL'),
            displacementCc: self::float($row, 'DisplacementCC'),
            horsepower: self::int($row, 'EngineHP'),
            model: self::str($row, 'EngineModel'),
            configuration: self::str($row, 'EngineConfiguration'),
            electrificationLevel: self::str($row, 'ElectrificationLevel'),
            driveType: self::str($row, 'DriveType'),
            transmissionStyle: self::str($row, 'TransmissionStyle'),
            transmissionSpeeds: self::int($row, 'TransmissionSpeeds'),
        );
    }

    /**
     * @return array<string, int|float|string|null>
     */
    public function toArray(): array
    {
        return [
            'fuel_type_primary' => $this->fuelTypePrimary,
            'fuel_type_secondary' => $this->fuelTypeSecondary,
            'cylinders' => $this->cylinders,
            'displacement_l' => $this->displacementL,
            'displacement_cc' => $this->displacementCc,
            'horsepower' => $this->horsepower,
            'model' => $this->model,
            'configuration' => $this->configuration,
            'electrification_level' => $this->electrificationLevel,
            'drive_type' => $this->driveType,
            'transmission_style' => $this->transmissionStyle,
            'transmission_speeds' => $this->transmissionSpeeds,
        ];
    }

    /**
     * @return array<string, int|float|string|null>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
