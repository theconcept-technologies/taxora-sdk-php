<?php

declare(strict_types=1);

namespace Taxora\Sdk\Exceptions;

use Exception;
use Throwable;

final class HttpException extends Exception
{
    protected int $statusCode;
    protected ?string $responseBody;
    protected ?string $retryAfter;

    public function __construct(
        string $message = 'HTTP request failed when communicating with Taxora API.',
        int $statusCode = 500,
        ?string $responseBody = null,
        int $code = 0,
        ?Throwable $previous = null,
        ?string $retryAfter = null
    ) {
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        $this->retryAfter = $retryAfter;
        parent::__construct($message, $code, $previous);
    }

    /**
     * Build from a raw error response. The message is derived from the body
     * (see ApiErrorMessage) instead of being the body itself, so HTML gateway
     * error pages never end up in log lines or stack traces. The untouched body
     * remains available via getResponseBody().
     */
    public static function fromResponse(
        int $statusCode,
        ?string $responseBody,
        ?Throwable $previous = null,
        ?string $retryAfter = null
    ): self {
        return new self(
            ApiErrorMessage::describe($responseBody, $statusCode),
            $statusCode,
            $responseBody,
            previous: $previous,
            retryAfter: $retryAfter
        );
    }

    /**
     * The final failure after the SDK retried a transient gateway error, e.g.
     * "Taxora VAT validation failed after 3 attempts (HTTP 504 Gateway Timeout)."
     */
    public static function afterAttempts(string $operation, int $attempts, self $last): self
    {
        return new self(
            sprintf(
                'Taxora %s failed after %d attempts (%s).',
                $operation,
                $attempts,
                ApiErrorMessage::detail($last->getResponseBody(), $last->getStatusCode())
            ),
            $last->getStatusCode(),
            $last->getResponseBody(),
            previous: $last,
            retryAfter: $last->getRetryAfter()
        );
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    /** Raw `Retry-After` header of the failed response, when it carried one. */
    public function getRetryAfter(): ?string
    {
        return $this->retryAfter;
    }
}
