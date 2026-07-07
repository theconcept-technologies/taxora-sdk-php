<?php

declare(strict_types=1);

namespace Taxora\Sdk\Exceptions;

use Exception;
use Throwable;

/**
 * Thrown when a client-side wait (e.g. SmartEnrichmentEndpoint::waitUntilComplete())
 * exceeds its configured timeout before the operation finished on the server.
 *
 * The operation itself keeps running server-side — the job can still be polled
 * afterwards; only the local wait gave up.
 */
final class TimeoutException extends Exception
{
    public function __construct(
        string $message = 'The operation did not complete within the configured timeout.',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
