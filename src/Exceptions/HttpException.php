<?php
declare(strict_types=1);

namespace Taxora\Sdk\Exceptions;

use Exception;
use Throwable;

final class HttpException extends Exception
{
    protected int $statusCode;
    protected ?string $responseBody;

    public function __construct(
        string $message = 'HTTP request failed when communicating with Taxora API.',
        int $statusCode = 500,
        ?string $responseBody = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}