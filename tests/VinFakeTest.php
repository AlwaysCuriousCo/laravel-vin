<?php

namespace AlwaysCurious\Vin\Tests;

use AlwaysCurious\Vin\Facades\Vin;
use AlwaysCurious\Vin\VehicleData;
use AlwaysCurious\Vin\VinLookupException;
use Illuminate\Support\Facades\Http;

class VinFakeTest extends TestCase
{
    private const VIN = '7YAMYFS50TY009706';

    private const OTHER_VIN = '1FTFW1E50NKF12345';

    /**
     * @spec VD-009
     */
    public function test_vehicle_data_fake_overrides_only_the_named_fields(): void
    {
        $vehicle = VehicleData::fake(make: 'Ford', model: 'F-150');

        $this->assertSame('Ford', $vehicle->make);
        $this->assertSame('F-150', $vehicle->model);
        $this->assertSame(2026, $vehicle->year);       // default retained
        $this->assertTrue($vehicle->decodedSuccessfully());
    }

    /**
     * @spec VIN-024
     */
    public function test_fake_returns_preset_data_with_no_http_call(): void
    {
        Http::fake();
        Vin::fake([self::OTHER_VIN => VehicleData::fake(make: 'Ford')]);

        $vehicle = Vin::lookup(self::OTHER_VIN);

        $this->assertSame('Ford', $vehicle->make);
        Http::assertNothingSent();
    }

    /**
     * @spec VIN-024
     */
    public function test_fake_generates_data_for_unmapped_vins(): void
    {
        Http::fake();
        Vin::fake();

        $vehicle = Vin::lookup(self::VIN);

        $this->assertInstanceOf(VehicleData::class, $vehicle);
        $this->assertSame(self::VIN, $vehicle->vin);
        Http::assertNothingSent();
    }

    /**
     * @spec VIN-024
     */
    public function test_fake_records_lookups_for_assertion(): void
    {
        $fake = Vin::fake();

        Vin::lookup(self::VIN, 2026);
        Vin::tryLookup(self::OTHER_VIN);

        $fake->assertLookedUp(self::VIN);
        $fake->assertLookedUp(self::VIN, fn (string $vin, ?int $year) => $year === 2026);
        $fake->assertLookedUp(self::OTHER_VIN);
        $fake->assertNotLookedUp('11111111111111111');
        $fake->assertLookedUpCount(2);
    }

    /**
     * @spec VIN-024
     */
    public function test_fake_still_applies_validation_and_the_enabled_gate(): void
    {
        Vin::fake();

        // Structural validation still runs through the real service.
        $this->assertNull(Vin::tryLookup('NOT-A-VIN'));

        // The enabled gate still short-circuits.
        config(['vin.enabled' => false]);
        $this->assertNull(Vin::tryLookup(self::VIN));
    }

    /**
     * @spec VIN-024
     */
    public function test_fake_can_simulate_a_failure(): void
    {
        Vin::fake([self::VIN => VinLookupException::requestFailed(self::VIN, 503)]);

        $this->assertNull(Vin::tryLookup(self::VIN));

        $this->expectException(VinLookupException::class);
        Vin::lookup(self::VIN);
    }
}
