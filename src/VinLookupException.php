<?php

namespace AlwaysCurious\Vin;

use RuntimeException;
use Throwable;

class VinLookupException extends RuntimeException
{
    /**
     * The typed cause of the failure. Set by every named constructor so a caller can branch on
     * `$e->reason` instead of the message (VIN-021). Kept as an optional trailing parameter so the
     * historical `new VinLookupException($message, $code, $previous)` signature still works.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly VinFailureReason $reason = VinFailureReason::UnexpectedResponse,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function invalidVin(string $vin): self
    {
        return new self("\"{$vin}\" is not a valid 17-character VIN.", reason: VinFailureReason::InvalidVin);
    }

    public static function connectionFailed(string $vin, Throwable $previous): self
    {
        return new self("Could not reach the NHTSA vPIC API to decode VIN {$vin}.", 0, $previous, VinFailureReason::ConnectionFailed);
    }

    public static function requestFailed(string $vin, int $status): self
    {
        return new self("The NHTSA vPIC API returned HTTP {$status} while decoding VIN {$vin}.", reason: VinFailureReason::RequestFailed);
    }

    public static function unexpectedResponse(string $vin): self
    {
        return new self("The NHTSA vPIC API returned an unexpected response while decoding VIN {$vin}.", reason: VinFailureReason::UnexpectedResponse);
    }

    public static function lookupDisabled(): self
    {
        return new self('Live VIN decoding is disabled by configuration.', reason: VinFailureReason::Disabled);
    }
}
