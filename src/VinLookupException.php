<?php

namespace AlwaysCurious\Vin;

use RuntimeException;
use Throwable;

class VinLookupException extends RuntimeException
{
    public static function invalidVin(string $vin): self
    {
        return new self("\"{$vin}\" is not a valid 17-character VIN.");
    }

    public static function connectionFailed(string $vin, Throwable $previous): self
    {
        return new self("Could not reach the NHTSA vPIC API to decode VIN {$vin}.", 0, $previous);
    }

    public static function requestFailed(string $vin, int $status): self
    {
        return new self("The NHTSA vPIC API returned HTTP {$status} while decoding VIN {$vin}.");
    }

    public static function unexpectedResponse(string $vin): self
    {
        return new self("The NHTSA vPIC API returned an unexpected response while decoding VIN {$vin}.");
    }

    public static function lookupDisabled(): self
    {
        return new self('Live VIN decoding is disabled by configuration.');
    }
}
