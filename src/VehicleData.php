<?php

namespace AlwaysCurious\Vin;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The subset of NHTSA vPIC decode results this package exposes.
 *
 * @implements Arrayable<string, int|string|null>
 */
final readonly class VehicleData implements Arrayable, JsonSerializable
{
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
    ) {}

    /**
     * Build a VehicleData from a single flat NHTSA "DecodeVinValues" result row.
     *
     * @param  array<string, mixed>  $result
     */
    public static function fromFlatResult(string $vin, array $result): self
    {
        $value = static function (string $key) use ($result): ?string {
            $raw = $result[$key] ?? null;

            return filled($raw) ? trim((string) $raw) : null;
        };

        $year = $value('ModelYear');

        // NHTSA returns ErrorCode as a comma-separated list (e.g. "0,12");
        // the first entry is the primary decode status.
        $errorCode = $value('ErrorCode');
        $primaryError = $errorCode !== null ? (int) explode(',', $errorCode)[0] : null;

        return new self(
            vin: $vin,
            year: $year !== null ? (int) $year : null,
            make: $value('Make'),
            model: $value('Model'),
            series: $value('Series'),
            trim: $value('Trim'),
            bodyClass: $value('BodyClass'),
            errorCode: $primaryError,
            errorText: $value('ErrorText'),
            manufacturer: $value('Manufacturer'),
            vehicleType: $value('VehicleType'),
        );
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
     * @return array<string, int|string|null>
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
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
