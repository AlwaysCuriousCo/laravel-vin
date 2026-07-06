<?php

namespace AlwaysCurious\Vin\Tests;

use AlwaysCurious\Vin\VinLookupException;
use AlwaysCurious\Vin\VinLookupService;
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
            ], $overrides)],
        ];
    }

    public function test_it_decodes_a_vin_into_vehicle_data(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->fakeDecodeResponse())]);

        $vehicle = (new VinLookupService)->lookup(self::VIN);

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

    public function test_it_normalizes_and_validates_the_vin(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->fakeDecodeResponse())]);

        $vehicle = (new VinLookupService)->lookup('  7yamyfs50ty009706 ');

        $this->assertSame(self::VIN, $vehicle->vin);
        Http::assertSent(fn ($request) => str_contains($request->url(), self::VIN));
    }

    public function test_it_rejects_a_structurally_invalid_vin(): void
    {
        Http::fake();
        $this->expectException(VinLookupException::class);
        (new VinLookupService)->lookup('NOT-A-VIN');
    }

    public function test_it_throws_when_the_api_returns_an_error_status(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response('', 500)]);
        $this->expectException(VinLookupException::class);
        (new VinLookupService)->lookup(self::VIN);
    }

    public function test_try_lookup_returns_null_on_failure(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response('', 500)]);
        $this->assertNull((new VinLookupService)->tryLookup(self::VIN));
    }

    public function test_it_passes_the_model_year_hint_to_the_api(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->fakeDecodeResponse())]);
        (new VinLookupService)->lookup(self::VIN, 2026);
        Http::assertSent(fn ($request) => $request['modelyear'] === 2026);
    }

    public function test_try_lookup_returns_null_without_calling_the_api_when_disabled(): void
    {
        Http::fake();
        config(['vin.enabled' => false]);
        $this->assertNull((new VinLookupService)->tryLookup(self::VIN));
        Http::assertNothingSent();
    }

    public function test_lookup_throws_when_decoding_is_disabled(): void
    {
        Http::fake();
        config(['vin.enabled' => false]);
        $this->expectException(VinLookupException::class);
        (new VinLookupService)->lookup(self::VIN);
    }

    public function test_it_caches_a_decoded_vin_and_reuses_it_within_a_version(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->fakeDecodeResponse())]);
        (new VinLookupService(cacheVersion: 1))->lookup(self::VIN);
        (new VinLookupService(cacheVersion: 1))->lookup(self::VIN);
        Http::assertSentCount(1);
    }

    public function test_bumping_the_cache_version_bypasses_the_previously_cached_vin(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::sequence()
            ->push($this->fakeDecodeResponse(['Model' => 'Ioniq 9']))
            ->push($this->fakeDecodeResponse(['Model' => 'Ioniq 9 Corrected']))]);

        $before = (new VinLookupService(cacheVersion: 1))->lookup(self::VIN);
        $after = (new VinLookupService(cacheVersion: 2))->lookup(self::VIN);

        $this->assertSame('Ioniq 9', $before->model);
        $this->assertSame('Ioniq 9 Corrected', $after->model);
        Http::assertSentCount(2);
    }
}
