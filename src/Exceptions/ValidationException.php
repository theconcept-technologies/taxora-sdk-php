<?php
declare(strict_types=1);

namespace Taxora\Sdk\Exceptions;

use Exception;
use Throwable;

final class ValidationException extends Exception
{
    protected array $errors;

    public function __construct(
        string $message = 'Validation failed for one or more fields.',
        array $errors = [],
        int $code = 422,
        ?Throwable $previous = null
    ) {
        $this->errors = $errors;
        parent::__construct($message, $code, $previous);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}