<?php

declare(strict_types=1);

namespace Taxora\Sdk\Exceptions;

use Exception;

/**
 * Thrown when a Taxora webhook request fails signature verification. Answer the request with HTTP 400.
 * getReason() returns one of the REASON_* constants for logging or branching.
 */
final class WebhookSignatureVerificationException extends Exception
{
    public const REASON_MISSING_SECRET = 'missing_secret';
    public const REASON_MISSING_HEADER = 'missing_header';
    public const REASON_MALFORMED_HEADER = 'malformed_header';
    public const REASON_TIMESTAMP_OUTSIDE_TOLERANCE = 'timestamp_outside_tolerance';
    public const REASON_NO_MATCHING_SIGNATURE = 'no_matching_signature';
    public const REASON_INVALID_PAYLOAD = 'invalid_payload';

    public function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
