<?php

return [
    // NHTSA vPIC API base URL.
    'base_url' => env('VIN_BASE_URL', 'https://vpic.nhtsa.dot.gov/api'),

    // HTTP timeout (seconds) per decode request.
    'timeout' => (int) env('VIN_TIMEOUT', 10),

    // How long (seconds) a decoded VIN stays cached. A VIN decode is immutable.
    'cache_ttl' => (int) env('VIN_CACHE_TTL', 86400),

    // Bump this integer to invalidate every previously cached decode at once,
    // without flushing the whole cache store.
    'cache_version' => (int) env('VIN_CACHE_VERSION', 1),

    // Master switch for live decoding. When false, lookup() throws and
    // tryLookup() returns null without hitting the network.
    'enabled' => (bool) env('VIN_ENABLED', true),
];
