<?php

declare(strict_types=1);

namespace Taxora\Sdk\Exceptions;

use Exception;
use Throwable;

final class AuthenticationException extends Exception
{
    public function __construct(
        string $message = 'Authentication failed with Taxora API.',
        int $code = 401,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Build from a raw 401 response body; the body itself never becomes the
     * message (see ApiErrorMessage).
     */
    public static function fromResponse(
        ?string $responseBody,
        int $code = 401,
        ?Throwable $previous = null
    ): self {
        return new self(ApiErrorMessage::describe($responseBody, $code), $code, $previous);
    }

    /** Unauthorized, and the follow-up token refresh failed as well. */
    public static function refreshFailed(?string $responseBody, ?Throwable $previous = null): self
    {
        return new self(
            'Unauthorized and refresh failed: ' . ApiErrorMessage::describe($responseBody, 401),
            401,
            $previous
        );
    }
}
