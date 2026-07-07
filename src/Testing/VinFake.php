<?php

namespace AlwaysCurious\Vin\Testing;

use AlwaysCurious\Vin\Contracts\VinDecoder;
use AlwaysCurious\Vin\Facades\Vin;
use AlwaysCurious\Vin\VehicleData;
use AlwaysCurious\Vin\VinLookupService;
use AlwaysCurious\Vin\VinManager;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * The fake {@see VinManager} installed by {@see Vin::fake()}.
 *
 * It routes every driver to a {@see FakeVinDecoder} — so lookups return preset data with no network
 * call — records each lookup for assertion, and, because it still builds the real
 * {@see VinLookupService}, keeps validation, the enabled gate and caching intact.
 * A consumer can therefore test their integration against the package's real behavior without ever
 * faking the NHTSA wire format (VIN-024).
 */
class VinFake extends VinManager
{
    private FakeVinDecoder $decoder;

    /** @var array<int, array{vin: string, modelYear: int|null}> */
    private array $lookups = [];

    /**
     * @param  array<string, VehicleData|Throwable>  $map  Preset decodes keyed by VIN.
     */
    public function __construct(Container $container, array $map = [])
    {
        parent::__construct($container);

        $this->decoder = new FakeVinDecoder($map);
    }

    /**
     * Every driver name resolves to the single fake decoder.
     */
    public function driver($driver = null): VinDecoder
    {
        return $this->decoder;
    }

    public function lookup(string $vin, ?int $modelYear = null): VehicleData
    {
        $this->record($vin, $modelYear);

        return parent::lookup($vin, $modelYear);
    }

    public function tryLookup(string $vin, ?int $modelYear = null): ?VehicleData
    {
        $this->record($vin, $modelYear);

        return parent::tryLookup($vin, $modelYear);
    }

    public function lookupMany(array $vins, ?int $modelYear = null): array
    {
        foreach ($vins as $vin) {
            $this->record($vin, $modelYear);
        }

        return parent::lookupMany($vins, $modelYear);
    }

    /**
     * The recorded lookups, in order, each as `['vin' => ..., 'modelYear' => ...]`.
     *
     * @return array<int, array{vin: string, modelYear: int|null}>
     */
    public function recorded(): array
    {
        return $this->lookups;
    }

    /**
     * Assert a lookup was requested for the VIN, optionally matching a callback that receives the
     * normalized VIN and the model-year hint.
     */
    public function assertLookedUp(string $vin, ?callable $callback = null): void
    {
        $vin = strtoupper(trim($vin));

        $matches = array_filter(
            $this->lookups,
            fn (array $lookup) => $lookup['vin'] === $vin
                && ($callback === null || $callback($lookup['vin'], $lookup['modelYear'])),
        );

        Assert::assertNotEmpty(
            $matches,
            "Expected a VIN lookup for [{$vin}], but none matching was recorded.",
        );
    }

    /**
     * Assert no lookup was requested for the given VIN.
     */
    public function assertNotLookedUp(string $vin): void
    {
        $vin = strtoupper(trim($vin));

        Assert::assertNotContains(
            $vin,
            array_column($this->lookups, 'vin'),
            "Unexpected VIN lookup for [{$vin}].",
        );
    }

    /**
     * Assert no lookups were requested at all.
     */
    public function assertNothingLookedUp(): void
    {
        Assert::assertSame([], $this->lookups, 'Expected no VIN lookups, but some were recorded.');
    }

    /**
     * Assert exactly $count lookups were requested.
     */
    public function assertLookedUpCount(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->lookups,
            "Expected {$count} VIN lookups, but recorded ".count($this->lookups).'.',
        );
    }

    private function record(string $vin, ?int $modelYear): void
    {
        $this->lookups[] = ['vin' => strtoupper(trim($vin)), 'modelYear' => $modelYear];
    }
}
