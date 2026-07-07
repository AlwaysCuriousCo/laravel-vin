<?php

namespace AlwaysCurious\Vin;

/**
 * Why a VIN lookup failed.
 *
 * Carried on {@see VinLookupException::$reason} (and {@see Events\VinDecodeFailed::$reason}) so a
 * caller can branch on the *cause* — show the right message, retry only transient failures — from a
 * single `catch (VinLookupException $e)` instead of matching on the exception message. This is the
 * typed answer to "why did `tryLookup()` return null?".
 */
enum VinFailureReason: string
{
    /** The input was not a structurally valid 17-character VIN (VIN-002). */
    case InvalidVin = 'invalid_vin';

    /** Live decoding is disabled by configuration (`vin.enabled = false`, VIN-004). */
    case Disabled = 'disabled';

    /** The decoder could not reach the provider (connection reset, DNS, timeout). */
    case ConnectionFailed = 'connection_failed';

    /** The provider answered with an unsuccessful HTTP status. */
    case RequestFailed = 'request_failed';

    /** The provider's response could not be understood by the decoder. */
    case UnexpectedResponse = 'unexpected_response';

    /**
     * Whether this failure is transient — a later retry might succeed. Invalid input and the
     * disabled gate are permanent for the given input/config; transport failures are not.
     */
    public function isTransient(): bool
    {
        return $this === self::ConnectionFailed || $this === self::RequestFailed;
    }
}
