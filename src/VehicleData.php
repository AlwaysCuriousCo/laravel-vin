<?php

namespace AlwaysCurious\Vin;

use AlwaysCurious\Vin\Testing\FakeVinDecoder;
use AlwaysCurious\Vin\Vehicle\AttributeLevel;
use AlwaysCurious\Vin\Vehicle\Body;
use AlwaysCurious\Vin\Vehicle\Engine;
use AlwaysCurious\Vin\Vehicle\ParsesNhtsaFields;
use AlwaysCurious\Vin\Vehicle\Plant;
use AlwaysCurious\Vin\Vehicle\Safety;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
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
     * The attribute level controls how much beyond identity is hydrated: lighter levels
     * skip the group mapping and/or the raw passthrough so an app that does not need them
     * spends no cycles building them and caches a smaller row (VD-007).
     *
     * @param  array<string, mixed>  $result
     */
    public static function fromFlatResult(string $vin, array $result, AttributeLevel $level = AttributeLevel::Full): self
    {
        // NHTSA returns ErrorCode as a comma-separated list (e.g. "0,12");
        // the first entry is the primary decode status.
        $errorCode = self::str($result, 'ErrorCode');
        $primaryError = $errorCode !== null ? (int) explode(',', $errorCode)[0] : null;

        // Groups gate at `typed`; `series` rides with them as extended identity, so the
        // default `identity` level stays the clean year/make/model/trim/body/type/mfr set.
        $withGroups = $level->includesGroups();

        return new self(
            vin: $vin,
            year: self::int($result, 'ModelYear'),
            make: self::str($result, 'Make'),
            model: self::str($result, 'Model'),
            series: $withGroups ? self::str($result, 'Series') : null,
            trim: self::str($result, 'Trim'),
            bodyClass: self::str($result, 'BodyClass'),
            errorCode: $primaryError,
            errorText: self::str($result, 'ErrorText'),
            manufacturer: self::str($result, 'Manufacturer'),
            vehicleType: self::str($result, 'VehicleType'),
            engine: $withGroups ? Engine::fromRow($result) : new Engine,
            safety: $withGroups ? Safety::fromRow($result) : new Safety,
            body: $withGroups ? Body::fromRow($result) : new Body,
            plant: $withGroups ? Plant::fromRow($result) : new Plant,
            attributes: $level->includesRawAttributes() ? self::keepNonEmpty($result) : [],
        );
    }

    /**
     * Build a VehicleData with test-friendly defaults, overriding only the fields you name.
     *
     * The convenience factory for tests and {@see FakeVinDecoder} — a
     * consumer writes `VehicleData::fake(make: 'Ford', model: 'F-150')` without hand-assembling the
     * full constructor or knowing any provider's wire format (VD-009).
     *
     * @param  array<string, string>  $attributes
     */
    public static function fake(
        string $vin = '1FTFW1E50NKF12345',
        ?int $year = 2026,
        ?string $make = 'ACME',
        ?string $model = 'Rocket',
        ?string $series = null,
        ?string $trim = null,
        ?string $bodyClass = null,
        ?int $errorCode = 0,
        ?string $errorText = null,
        ?string $manufacturer = null,
        ?string $vehicleType = null,
        Engine $engine = new Engine,
        Safety $safety = new Safety,
        Body $body = new Body,
        Plant $plant = new Plant,
        array $attributes = [],
    ): self {
        return new self(
            vin: $vin,
            year: $year,
            make: $make,
            model: $model,
            series: $series,
            trim: $trim,
            bodyClass: $bodyClass,
            errorCode: $errorCode,
            errorText: $errorText,
            manufacturer: $manufacturer,
            vehicleType: $vehicleType,
            engine: $engine,
            safety: $safety,
            body: $body,
            plant: $plant,
            attributes: $attributes,
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
     * Project a subset of the identity fields, keyed by their property name — the common shape for
     * a `Model::fill()`. Keys are VehicleData property names (`vin`, `year`, `make`, `model`,
     * `series`, `trim`, `bodyClass`, `manufacturer`, `vehicleType`, `errorCode`, `errorText`); an
     * unknown key throws so a typo surfaces rather than silently dropping (VD-008).
     *
     * @param  array<int, string>  $keys
     * @return array<string, int|string|null>
     */
    public function only(array $keys): array
    {
        $identity = $this->identityValues();
        $projection = [];

        foreach ($keys as $key) {
            $projection[$key] = $this->identityValue($identity, $key);
        }

        return $projection;
    }

    /**
     * Project identity fields onto arbitrary column names for a `Model::fill()`. The map is
     * `property => column`; only the named properties are returned, keyed by their target column
     * (VD-008).
     *
     *     $data->toColumns(['year' => 'model_year', 'make' => 'make', 'bodyClass' => 'body_class']);
     *     // => ['model_year' => 2026, 'make' => 'HYUNDAI', 'body_class' => 'Sport Utility ...']
     *
     * @param  array<string, string>  $map
     * @return array<string, int|string|null>
     */
    public function toColumns(array $map): array
    {
        $identity = $this->identityValues();
        $columns = [];

        foreach ($map as $key => $column) {
            $columns[$column] = $this->identityValue($identity, $key);
        }

        return $columns;
    }

    /**
     * The identity fields keyed by property name — the projectable surface for {@see only()} /
     * {@see toColumns()}. The nested typed groups are intentionally excluded: they are not a flat
     * column shape (reach them via ->engine/->safety/->body/->plant or ->toArray()).
     *
     * @return array<string, int|string|null>
     */
    private function identityValues(): array
    {
        return [
            'vin' => $this->vin,
            'year' => $this->year,
            'make' => $this->make,
            'model' => $this->model,
            'series' => $this->series,
            'trim' => $this->trim,
            'bodyClass' => $this->bodyClass,
            'manufacturer' => $this->manufacturer,
            'vehicleType' => $this->vehicleType,
            'errorCode' => $this->errorCode,
            'errorText' => $this->errorText,
        ];
    }

    /**
     * @param  array<string, int|string|null>  $identity
     */
    private function identityValue(array $identity, string $key): int|string|null
    {
        if (! array_key_exists($key, $identity)) {
            throw new InvalidArgumentException(
                "Unknown VehicleData field [{$key}]. Projectable fields: ".implode(', ', array_keys($identity)).'.',
            );
        }

        return $identity[$key];
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
