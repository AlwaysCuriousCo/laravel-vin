<?php

namespace AlwaysCurious\Vin\Tests;

use AlwaysCurious\Vin\VehicleData;
use InvalidArgumentException;

class VehicleDataProjectionTest extends TestCase
{
    private const VIN = '7YAMYFS50TY009706';

    private function vehicle(): VehicleData
    {
        return VehicleData::fake(
            vin: self::VIN,
            year: 2026,
            make: 'HYUNDAI',
            model: 'Ioniq 9',
            trim: 'Calligraphy',
            bodyClass: 'Sport Utility Vehicle (SUV)',
        );
    }

    /**
     * @spec VD-008
     */
    public function test_only_projects_named_properties_keyed_by_property_name(): void
    {
        $this->assertSame(
            ['make' => 'HYUNDAI', 'model' => 'Ioniq 9', 'year' => 2026, 'trim' => 'Calligraphy'],
            $this->vehicle()->only(['make', 'model', 'year', 'trim']),
        );
    }

    /**
     * @spec VD-008
     */
    public function test_to_columns_maps_properties_onto_column_names(): void
    {
        $this->assertSame(
            ['model_year' => 2026, 'make' => 'HYUNDAI', 'body_class' => 'Sport Utility Vehicle (SUV)'],
            $this->vehicle()->toColumns(['year' => 'model_year', 'make' => 'make', 'bodyClass' => 'body_class']),
        );
    }

    /**
     * @spec VD-008
     */
    public function test_only_throws_on_an_unknown_field(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->vehicle()->only(['make', 'nonsense']);
    }

    /**
     * @spec VD-008
     */
    public function test_to_columns_throws_on_an_unknown_field(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->vehicle()->toColumns(['nope' => 'col']);
    }
}
