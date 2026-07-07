<?php

namespace AlwaysCurious\Vin\Tests;

use AlwaysCurious\Vin\Vehicle\Body;
use AlwaysCurious\Vin\Vehicle\Engine;
use AlwaysCurious\Vin\Vehicle\Plant;
use AlwaysCurious\Vin\Vehicle\Safety;
use AlwaysCurious\Vin\VehicleData;

class VehicleDataTest extends TestCase
{
    private const VIN = '7YAMYFS50TY009706';

    /**
     * A representative DecodeVinValues row: identity, populated and blank spec fields,
     * a non-numeric numeric field, a spaced value, and a long-tail field we do not type.
     *
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            // Identity
            'Make' => 'HYUNDAI',
            'Model' => 'Ioniq 9',
            'ModelYear' => '2026',
            'Series' => '',
            'Trim' => 'Calligraphy',
            'BodyClass' => 'Sport Utility Vehicle (SUV)/Multi-Purpose Vehicle (MPV)',
            'Manufacturer' => 'HYUNDAI MOTOR GROUP METAPLANT AMERICA',
            'VehicleType' => 'MULTIPURPOSE PASSENGER VEHICLE (MPV)',
            'ErrorCode' => '0,12',
            'ErrorText' => '0 - VIN decoded clean. Check Digit (9th position) is correct;',
            // Engine / powertrain
            'FuelTypePrimary' => 'Electric',
            'EngineCylinders' => '',
            'DisplacementL' => '',
            'EngineHP' => '422',
            'DriveType' => 'AWD',
            'TransmissionStyle' => 'Automatic',
            'TransmissionSpeeds' => '1',
            'ElectrificationLevel' => 'BEV (Battery Electric Vehicle)',
            // Body
            'Doors' => '4',
            'Seats' => '',
            'SeatRows' => 'Not Applicable',
            'GVWR' => 'Class 2E: 6,001 - 7,000 lb (2,722 - 3,175 kg)',
            // Plant
            'PlantCity' => 'ELLABELL',
            'PlantState' => 'GEORGIA',
            'PlantCountry' => 'UNITED STATES (USA)',
            'PlantCompanyName' => '',
            // Safety (BackupCamera is deliberately padded to prove trimming)
            'BackupCamera' => ' Standard ',
            'ABS' => 'Standard',
            'AirBagLocCurtain' => 'All Rows',
            // Long-tail field intentionally not lifted into a typed property
            'DestinationMarket' => 'North America',
        ], $overrides);
    }

    /**
     * @spec VD-001
     */
    public function test_it_keeps_every_non_empty_field_in_the_attribute_bag(): void
    {
        $vehicle = VehicleData::fromFlatResult(self::VIN, $this->row());

        // Non-empty fields are preserved verbatim, keyed by NHTSA field name...
        $this->assertSame('HYUNDAI', $vehicle->attributes['Make']);
        // ...including the long tail we do not type.
        $this->assertSame('North America', $vehicle->attributes['DestinationMarket']);
        // Values are trimmed.
        $this->assertSame('Standard', $vehicle->attributes['BackupCamera']);
        // Blank fields are omitted entirely.
        $this->assertArrayNotHasKey('Series', $vehicle->attributes);
        $this->assertArrayNotHasKey('EngineCylinders', $vehicle->attributes);
        $this->assertArrayNotHasKey('PlantCompanyName', $vehicle->attributes);
    }

    /**
     * @spec VD-002
     */
    public function test_attribute_reads_the_bag_with_a_default(): void
    {
        $vehicle = VehicleData::fromFlatResult(self::VIN, $this->row());

        $this->assertSame('HYUNDAI', $vehicle->attribute('Make'));
        $this->assertNull($vehicle->attribute('NoSuchField'));
        $this->assertSame('n/a', $vehicle->attribute('NoSuchField', 'n/a'));
        // A blank source field is absent from the bag, so the default applies.
        $this->assertSame('n/a', $vehicle->attribute('Series', 'n/a'));
    }

    /**
     * @spec VD-003
     */
    public function test_it_populates_the_typed_groups_from_the_row(): void
    {
        $vehicle = VehicleData::fromFlatResult(self::VIN, $this->row());

        $this->assertSame('Electric', $vehicle->engine->fuelTypePrimary);
        $this->assertSame(422, $vehicle->engine->horsepower);
        $this->assertSame('AWD', $vehicle->engine->driveType);
        $this->assertSame('BEV (Battery Electric Vehicle)', $vehicle->engine->electrificationLevel);

        $this->assertSame(4, $vehicle->body->doors);
        $this->assertSame('Class 2E: 6,001 - 7,000 lb (2,722 - 3,175 kg)', $vehicle->body->gvwr);

        $this->assertSame('ELLABELL', $vehicle->plant->city);
        $this->assertSame('UNITED STATES (USA)', $vehicle->plant->country);

        $this->assertSame('Standard', $vehicle->safety->backupCamera);
        $this->assertSame('All Rows', $vehicle->safety->airbagCurtain);
    }

    /**
     * @spec VD-003
     */
    public function test_groups_are_present_but_empty_when_the_row_lacks_them(): void
    {
        // Row carries only identity — no spec fields.
        $vehicle = VehicleData::fromFlatResult(self::VIN, ['Make' => 'FORD']);

        $this->assertInstanceOf(Engine::class, $vehicle->engine);
        $this->assertInstanceOf(Safety::class, $vehicle->safety);
        $this->assertInstanceOf(Body::class, $vehicle->body);
        $this->assertInstanceOf(Plant::class, $vehicle->plant);
        $this->assertNull($vehicle->engine->horsepower);
        $this->assertNull($vehicle->body->doors);

        // A custom driver that constructs VehicleData directly gets the same non-null
        // groups and an empty bag from the constructor defaults.
        $bare = new VehicleData(
            vin: self::VIN, year: null, make: null, model: null, series: null,
            trim: null, bodyClass: null, errorCode: null, errorText: null,
        );

        $this->assertInstanceOf(Engine::class, $bare->engine);
        $this->assertSame([], $bare->attributes);
    }

    /**
     * @spec VD-004
     */
    public function test_numeric_group_fields_are_null_when_blank_or_non_numeric(): void
    {
        $vehicle = VehicleData::fromFlatResult(self::VIN, [
            'EngineHP' => '422',
            'EngineCylinders' => '',              // blank
            'DisplacementL' => '5.0',             // float
            'DisplacementCC' => 'n/a',            // non-numeric
            'Doors' => '4',
            'Seats' => '',                        // blank
            'SeatRows' => 'Not Applicable',       // non-numeric
            'TransmissionSpeeds' => '10',
        ]);

        $this->assertSame(422, $vehicle->engine->horsepower);
        $this->assertNull($vehicle->engine->cylinders);
        $this->assertSame(5.0, $vehicle->engine->displacementL);
        $this->assertNull($vehicle->engine->displacementCc);
        $this->assertSame(4, $vehicle->body->doors);
        $this->assertNull($vehicle->body->seats);
        $this->assertNull($vehicle->body->seatRows);
        $this->assertSame(10, $vehicle->engine->transmissionSpeeds);
    }

    /**
     * @spec VD-005
     */
    public function test_to_array_nests_the_groups_and_omits_the_raw_bag(): void
    {
        $vehicle = VehicleData::fromFlatResult(self::VIN, $this->row());

        $array = $vehicle->toArray();

        $this->assertSame('HYUNDAI', $array['make']);
        $this->assertSame(422, $array['engine']['horsepower']);
        $this->assertSame(4, $array['body']['doors']);
        $this->assertSame('UNITED STATES (USA)', $array['plant']['country']);
        $this->assertSame('Standard', $array['safety']['backup_camera']);

        // The raw bag is not embedded, and long-tail fields do not leak to the top level.
        $this->assertArrayNotHasKey('attributes', $array);
        $this->assertArrayNotHasKey('DestinationMarket', $array);

        // JsonSerializable produces the same shape as toArray().
        $this->assertSame($array, json_decode(json_encode($vehicle), true));
    }

    /**
     * @spec VD-006
     */
    public function test_decode_status_helpers_are_independent(): void
    {
        // Full identity, but a non-zero (non-blocking) primary error: identified yet not clean.
        $warned = VehicleData::fromFlatResult(self::VIN, $this->row(['ErrorCode' => '1,12']));
        $this->assertTrue($warned->isFullyIdentified());
        $this->assertFalse($warned->decodedSuccessfully());

        // Clean decode with a full identity: both true.
        $clean = VehicleData::fromFlatResult(self::VIN, $this->row());
        $this->assertTrue($clean->isFullyIdentified());
        $this->assertTrue($clean->decodedSuccessfully());

        // Clean status but no model: decoded successfully, yet not fully identified.
        $partial = VehicleData::fromFlatResult(self::VIN, $this->row(['Model' => '']));
        $this->assertTrue($partial->decodedSuccessfully());
        $this->assertFalse($partial->isFullyIdentified());
    }
}
