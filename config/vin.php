<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Decoder Driver
    |--------------------------------------------------------------------------
    |
    | The decoder used by lookups. "nhtsa" ships with the package; register your
    | own with VinManager::extend() / Vin::extend() and name it here (or override
    | per call with Vin::using('your-driver')).
    |
    */

    'driver' => env('VIN_DRIVER', 'nhtsa'),

    /*
    |--------------------------------------------------------------------------
    | Decoder Configuration
    |--------------------------------------------------------------------------
    |
    | Per-driver settings, keyed by driver name (à la cache.stores / mail.mailers).
    | Add a block here for each custom driver that needs configuration.
    |
    */

    'decoders' => [

        'nhtsa' => [
            // NHTSA vPIC API base URL.
            'base_url' => env('VIN_BASE_URL', 'https://vpic.nhtsa.dot.gov/api'),

            // HTTP timeout (seconds) per decode request.
            'timeout' => (int) env('VIN_TIMEOUT', 10),

            // How much of each decode to hydrate onto VehicleData:
            //   'identity' — year/make/model/trim/body class/vehicle type/manufacturer
            //                (the ~80% set; VIN + decode status are always present)
            //   'typed'    — the above plus series and the engine/safety/body/plant groups
            //   'full'     — the above plus the raw attribute passthrough (every NHTSA field)
            // 'identity' is the default: clean and cheap. Step up when you need more. Lighter
            // levels skip that mapping work and cache a smaller row; changing the level changes
            // the stored shape, so bump VIN_CACHE_VERSION to re-decode already-cached VINs.
            'attributes' => env('VIN_ATTRIBUTES', 'identity'),

            // Transient-failure retry for each decode request. NHTSA occasionally blips; the HTTP
            // client retries this many times, sleeping this many milliseconds between attempts,
            // before a failure surfaces as VinLookupException::requestFailed / connectionFailed.
            'retry' => [
                'times' => (int) env('VIN_RETRY_TIMES', 2),
                'sleep' => (int) env('VIN_RETRY_SLEEP', 200),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | A VIN's decode is immutable, so results are cached. "store" names the cache
    | store to use (null = the app's default store). Bump "version" to invalidate
    | every previously cached decode at once, without flushing the whole store.
    |
    */

    'cache' => [
        'store' => env('VIN_CACHE_STORE'),
        'ttl' => (int) env('VIN_CACHE_TTL', 86400),
        'version' => (int) env('VIN_CACHE_VERSION', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Master Switch
    |--------------------------------------------------------------------------
    |
    | When false, lookup() throws and tryLookup() returns null without hitting
    | the network or any decoder.
    |
    */

    'enabled' => (bool) env('VIN_ENABLED', true),

];
