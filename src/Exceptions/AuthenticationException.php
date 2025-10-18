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
}