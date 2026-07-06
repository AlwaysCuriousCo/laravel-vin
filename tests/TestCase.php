<?php

namespace AlwaysCurious\Vin\Tests;

use AlwaysCurious\Vin\VinServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            VinServiceProvider::class,
        ];
    }
}
