<?php

namespace AlwaysCurious\Vin\Tests;

use AlwaysCurious\Vin\Facades\Vin;
use AlwaysCurious\Vin\VinLookupException;
use Illuminate\Support\Facades\Http;

class VinLookupServiceTest extends TestCase
{
    private const VIN = '7YAMYFS50TY009706';

    private function fakeDecodeResponse(array $overrides = []): array
    {
        return [
            'Count' => 1,
            'Message' => 'Results returned successfully.',
            'SearchCriteria' => 'VIN:'.self::VIN,
            'Results' => [array_merge([
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
                'EngineHP' => '422',
                'DriveType' => 'AWD',
                'DestinationMarket' => 'North America',
            ], $overrides)],
        ];
    }

    /**
     * @spec VIN-010
     * @spec VIN-012
     */
    public function test_it_decodes_a_vin_into_vehicle_data(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->fakeDecodeResponse())]);

        $vehicle = Vin::lookup(self::VIN);

        $this->assertSame(self::VIN, $vehicle->vin);
        $this->assertSame(2026, $vehicle->year);
        $this->assertSame('HYUNDAI', $vehicle->make);
        $this->assertSame('Ioniq 9', $vehicle->model);
        $this->assertNull($vehicle->series);
        $this->assertSame('Calligraphy', $vehicle->trim);
        $this->assertSame('Sport Utility Vehicle (SUV)/Multi-Purpose Vehicle (MPV)', $vehicle->bodyClass);
        $this->assertSame('HYUNDAI MOTOR GROUP METAPLANT AMERICA', $vehicle->manufacturer);
        $this->assertSame('MULTIPURPOSE PASSENGER VEHICLE (MPV)', $vehicle->vehicleType);
        $this->assertSame(0, $vehicle->errorCode);
        $this->assertTrue($vehicle->decodedSuccessfully());
    }

    /**
     * @spec VD-007
     */
    public function test_the_default_level_is_identity(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->fakeDecodeResponse(['Series' => 'Line 5']))]);

        // No VIN_ATTRIBUTES configured — the shipped default is 'identity'.
        $vehicle = Vin::lookup(self::VIN);

        $this->assertSame('HYUNDAI', $vehicle->make);
        $this->assertSame('Calligraphy', $vehicle->trim);
        // series is not part of the default set, and neither are the groups or the raw bag.
        $this->assertNull($vehicle->series);
        $this->assertNull($vehicle->engine->horsepower);
        $this->assertSame([], $vehicle->attributes);
    }

    /**
     * @spec VD-007
     */
    public function test_the_identity_level_omits_series_groups_and_the_raw_bag(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->fakeDecodeResponse(['Series' => 'Line 5']))]);
        config(['vin.decoders.nhtsa.attributes' => 'identity']);

        $vehicle = Vin::lookup(self::VIN);

        $this->assertSame('HYUNDAI', $vehicle->make);
        $this->assertNull($vehicle->series);
        $this->assertNull($vehicle->engine->horsepower);
        $this->assertSame([], $vehicle->attributes);
    }

    /**
     * @spec VD-007
     */
    public function test_the_typed_attribute_level_adds_series_and_groups_but_not_the_raw_bag(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->fakeDecodeResponse(['Series' => 'Line 5']))]);
        config(['vin.decoders.nhtsa.attributes' => 'typed']);

        $vehicle = Vin::lookup(self::VIN);

        $this->assertSame('Line 5', $vehicle->series);
        $this->assertSame(422, $vehicle->engine->horsepower);
        $this->assertSame('AWD', $vehicle->engine->driveType);
        $this->assertSame([], $vehicle->attributes);
    }

    /**
     * @spec VD-007
     */
    public function test_the_full_level_exposes_series_groups_and_the_raw_passthrough(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->fakeDecodeResponse(['Series' => 'Line 5']))]);
        config(['vin.decoders.nhtsa.attributes' => 'full']);

        $vehicle = Vin::lookup(self::VIN);

        $this->assertSame('Line 5', $vehicle->series);
        $this->assertSame(422, $vehicle->engine->horsepower);
        $this->assertSame('North America', $vehicle->attribute('DestinationMarket'));
    }

    /**
     * @spec VIN-001
     */
    public function test_it_normalizes_and_validates_the_vin(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->fakeDecodeResponse())]);

        $vehicle = Vin::lookup('  7yamyfs50ty009706 ');

        $this->assertSame(self::VIN, $vehicle->vin);
        Http::assertSent(fn ($request) => str_contains($request->url(), self::VIN));
    }

    /**
     * @spec VIN-002
     */
    public function test_it_rejects_a_structurally_invalid_vin(): void
    {
        Http::fake();
        $this->expectException(VinLookupException::class);
        Vin::lookup('NOT-A-VIN');
    }

    /**
     * @spec VIN-003
     */
    public function test_is_valid_checks_structure_without_hitting_the_network(): void
    {
        Http::fake();

        $this->assertTrue(Vin::isValid('  7yamyfs50ty009706 '));
        $this->assertFalse(Vin::isValid('NOT-A-VIN'));
        Http::assertNothingSent();
    }

    /**
     * @spec VIN-011
     */
    public function test_it_throws_when_the_api_returns_an_error_status(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response('', 500)]);
        $this->expectException(VinLookupException::class);
        Vin::lookup(self::VIN);
    }

    /**
     * @spec VIN-005
     */
    public function test_try_lookup_returns_null_on_failure(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response('', 500)]);
        $this->assertNull(Vin::tryLookup(self::VIN));
    }

    /**
     * @spec VIN-010
     */
    public function test_it_passes_the_model_year_hint_to_the_api(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->fakeDecodeResponse())]);
        Vin::lookup(self::VIN, 2026);
        Http::assertSent(fn ($request) => $request['modelyear'] === 2026);
    }

    /**
     * @spec VIN-004
     */
    public function test_try_lookup_returns_null_without_calling_the_api_when_disabled(): void
    {
        Http::fake();
        config(['vin.enabled' => false]);
        $this->assertNull(Vin::tryLookup(self::VIN));
        Http::assertNothingSent();
    }

    /**
     * @spec VIN-004
     */
    public function test_lookup_throws_when_decoding_is_disabled(): void
    {
        Http::fake();
        config(['vin.enabled' => false]);
        $this->expectException(VinLookupException::class);
        Vin::lookup(self::VIN);
    }

    /**
     * @spec VIN-006
     */
    public function test_it_caches_a_decoded_vin_and_reuses_it_within_a_version(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->fakeDecodeResponse())]);

        Vin::lookup(self::VIN);
        Vin::lookup(self::VIN);

        Http::assertSentCount(1);
    }

    /**
     * @spec VIN-007
     */
    public function test_bumping_the_cache_version_bypasses_the_previously_cached_vin(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::sequence()
            ->push($this->fakeDecodeResponse(['Model' => 'Ioniq 9']))
            ->push($this->fakeDecodeResponse(['Model' => 'Ioniq 9 Corrected']))]);

        config(['vin.cache.version' => 1]);
        $before = Vin::lookup(self::VIN);

        config(['vin.cache.version' => 2]);
        $after = Vin::lookup(self::VIN);

        $this->assertSame('Ioniq 9', $before->model);
        $this->assertSame('Ioniq 9 Corrected', $after->model);
        Http::assertSentCount(2);
    }
}
