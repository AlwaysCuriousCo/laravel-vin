<?php

namespace AlwaysCurious\Vin;

use AlwaysCurious\Vin\Vehicle\Body;
use AlwaysCurious\Vin\Vehicle\Engine;
use AlwaysCurious\Vin\Vehicle\ParsesNhtsaFields;
use AlwaysCurious\Vin\Vehicle\Plant;
use AlwaysCurious\Vin\Vehicle\Safety;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The decoded vehicle: a curated typed identity, four typed attribute groups (engine,
 * safety, body, plant) and a raw passthrough of every non-empty provider field.
 *
 * The identity fields and the groups are typed projections of the same NHTSA
 * "DecodeVinValues" row; `$attributes` keeps the full row verbatim so the long tail we do
 * not lift into a property stays reachable via {@see attribute()}. See the vehicle-data spec
 * (VD-NNN) and ADR-0005.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class VehicleData implements Arrayable, JsonSerializable
{
    use ParsesNhtsaFields;

    /**
     * @param  array<string, string>  $attributes  Every non-empty provider field, keyed by
     *                                             its original NHTSA field name (trimmed strings).
     */
    public function __construct(
        public string $vin,
        public ?int $year,
        public ?string $make,
        public ?string $model,
        public ?string $series,
        public ?string $trim,
        public ?string $bodyClass,
        public ?int $errorCode,
        public ?string $errorText,
        public ?string $manufacturer = null,
        public ?string $vehicleType = null,
        public Engine $engine = new Engine,
        public Safety $safety = new Safety,
        public Body $body = new Body,
        public Plant $plant = new Plant,
        public array $attributes = [],
    ) {}

    /**
     * Build a VehicleData from a single flat NHTSA "DecodeVinValues" result row.
     *
     * @param  array<string, mixed>  $result
     */
    public static function fromFlatResult(string $vin, array $result): self
    {
        // NHTSA returns ErrorCode as a comma-separated list (e.g. "0,12");
        // the first entry is the primary decode status.
        $errorCode = self::str($result, 'ErrorCode');
        $primaryError = $errorCode !== null ? (int) explode(',', $errorCode)[0] : null;

        return new self(
            vin: $vin,
            year: self::int($result, 'ModelYear'),
            make: self::str($result, 'Make'),
            model: self::str($result, 'Model'),
            series: self::str($result, 'Series'),
            trim: self::str($result, 'Trim'),
            bodyClass: self::str($result, 'BodyClass'),
            errorCode: $primaryError,
            errorText: self::str($result, 'ErrorText'),
            manufacturer: self::str($result, 'Manufacturer'),
            vehicleType: self::str($result, 'VehicleType'),
            engine: Engine::fromRow($result),
            safety: Safety::fromRow($result),
            body: Body::fromRow($result),
            plant: Plant::fromRow($result),
            attributes: self::keepNonEmpty($result),
        );
    }

    /**
     * A raw provider field by its exact NHTSA field name, or $default when blank/absent.
     *
     * The escape hatch for fields not lifted into a typed property (e.g. 'DestinationMarket',
     * 'NCSABodyType', 'Note').
     */
    public function attribute(string $key, ?string $default = null): ?string
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Whether NHTSA decoded the VIN without a blocking error.
     *
     * Error code 0 means a clean decode; non-zero codes (other than the
     * benign "model year mismatch" warning) indicate an incomplete result.
     */
    public function decodedSuccessfully(): bool
    {
        return $this->errorCode === 0;
    }

    /**
     * Whether the decode yields a full Year + Make + Model — enough to identify
     * a specific vehicle, regardless of whether NHTSA flagged a non-blocking
     * warning. A clean decode is not required.
     */
    public function isFullyIdentified(): bool
    {
        return $this->year !== null && filled($this->make) && filled($this->model);
    }

    /**
     * The identity fields plus the four nested typed groups. The raw $attributes bag is
     * intentionally not embedded here — reach it via ->attributes / attribute() (VD-005).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'vin' => $this->vin,
            'year' => $this->year,
            'make' => $this->make,
            'model' => $this->model,
            'series' => $this->series,
            'trim' => $this->trim,
            'body_class' => $this->bodyClass,
            'error_code' => $this->errorCode,
            'error_text' => $this->errorText,
            'manufacturer' => $this->manufacturer,
            'vehicle_type' => $this->vehicleType,
            'engine' => $this->engine->toArray(),
            'safety' => $this->safety->toArray(),
            'body' => $this->body->toArray(),
            'plant' => $this->plant->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Every non-empty row field, trimmed to a string, keyed by its NHTSA field name.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, string>
     */
    private static function keepNonEmpty(array $result): array
    {
        $attributes = [];

        foreach ($result as $key => $raw) {
            if (filled($raw)) {
                $attributes[$key] = trim((string) $raw);
            }
        }

        return $attributes;
    }
}
