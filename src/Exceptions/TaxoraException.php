<?php
declare(strict_types=1);

namespace Taxora\Sdk\Exceptions;

use Exception;
use Throwable;

/** @phpstan-ignore-next-line */
final class TaxoraException extends Exception
{
    protected array $context;

    public function __construct(
        string $message = 'An error occurred in the Taxora SDK.',
        int $code = 0,
        array $context = [],
        ?Throwable $previous = null
    ) {
        $this->context = $context;
        parent::__construct($message, $code, $previous);
    }

    public function getContext(): array
    {
        return $this->context;
    }
}