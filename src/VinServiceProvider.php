<?php

namespace AlwaysCurious\Vin;

use Illuminate\Support\ServiceProvider;

class VinServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vin.php', 'vin');

        // The driver registry. Singleton so custom drivers registered via
        // VinManager::extend() / Vin::extend() (typically in a host service
        // provider) persist for the lifetime of the app.
        $this->app->singleton(VinManager::class, fn ($app) => new VinManager($app));
        $this->app->alias(VinManager::class, 'vin');

        // Resolve the default-driver lookup service for constructor injection and
        // app(VinLookupService::class). The manager builds a fresh service each
        // call, so the enabled gate and cache config are re-read at runtime.
        $this->app->bind(VinLookupService::class, function ($app): VinLookupService {
            /** @var VinManager $manager */
            $manager = $app->make(VinManager::class);

            return $manager->using();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/vin.php' => config_path('vin.php'),
            ], 'vin-config');
        }
    }
}
