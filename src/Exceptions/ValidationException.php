<?php

declare(strict_types=1);

namespace Taxora\Sdk\Exceptions;

use Exception;
use Throwable;

final class ValidationException extends Exception
{
    /** @var array<string, list<string>> */
    protected array $errors;

    protected ?string $responseBody;

    /** @param array<string, list<string>> $errors */
    public function __construct(
        string $message = 'Validation failed for one or more fields.',
        array $errors = [],
        int $code = 422,
        ?Throwable $previous = null,
        ?string $responseBody = null
    ) {
        $this->errors = $errors;
        $this->responseBody = $responseBody;
        parent::__construct($message, $code, $previous);
    }

    /**
     * Build from a raw 422 response body. The API emits two shapes: Laravel
     * validation failures as {"message": ..., "errors": {field: [...]}} and
     * state-guard errors as {"error": "reason"} — the human-readable reason
     * becomes the exception message in both cases, and the raw body stays
     * available via getResponseBody().
     */
    public static function fromResponseBody(string $body): self
    {
        $message = 'Validation failed for one or more fields.';
        $errors = [];

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                /** @var array<string, list<string>> $errors */
                $errors = $decoded['errors'];
            }

            if (isset($decoded['message']) && is_string($decoded['message']) && $decoded['message'] !== '') {
                $message = $decoded['message'];
            } elseif (isset($decoded['error']) && is_string($decoded['error']) && $decoded['error'] !== '') {
                $message = $decoded['error'];
            }
        }

        return new self($message, $errors, 422, null, $body);
    }

    /** @return array<string, list<string>> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
