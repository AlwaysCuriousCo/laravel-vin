<?php

namespace AlwaysCurious\Vin;

use Illuminate\Support\ServiceProvider;

class VinServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vin.php', 'vin');

        // Bind (not singleton) so each resolve constructs a fresh service that
        // re-reads the current config(). A host app can override vin.* at
        // runtime — e.g. from database-backed settings — after boot without a
        // stale instance lingering in the container.
        $this->app->bind(VinLookupService::class, fn () => new VinLookupService);
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
