<?php

namespace AlwaysCurious\Vin\Vehicle;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Body and dimensional attributes lifted from a NHTSA "DecodeVinValues" row.
 *
 * Door/seat counts are ints (null when blank/non-numeric, never 0 — see VD-004); GVWR and the
 * cab/wheelbase/trailer descriptors are the strings NHTSA returns (e.g. GVWR is a class range
 * like "Class 1: 6,000 lb or less").
 *
 * @implements Arrayable<string, int|string|null>
 */
final readonly class Body implements Arrayable, JsonSerializable
{
    use ParsesNhtsaFields;

    public function __construct(
        public ?int $doors = null,
        public ?int $seats = null,
        public ?int $seatRows = null,
        public ?string $gvwr = null,
        public ?string $cabType = null,
        public ?string $wheelBaseType = null,
        public ?string $trailerType = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            doors: self::int($row, 'Doors'),
            seats: self::int($row, 'Seats'),
            seatRows: self::int($row, 'SeatRows'),
            gvwr: self::str($row, 'GVWR'),
            cabType: self::str($row, 'BodyCabType'),
            wheelBaseType: self::str($row, 'WheelBaseType'),
            trailerType: self::str($row, 'TrailerType'),
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'doors' => $this->doors,
            'seats' => $this->seats,
            'seat_rows' => $this->seatRows,
            'gvwr' => $this->gvwr,
            'cab_type' => $this->cabType,
            'wheel_base_type' => $this->wheelBaseType,
            'trailer_type' => $this->trailerType,
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
