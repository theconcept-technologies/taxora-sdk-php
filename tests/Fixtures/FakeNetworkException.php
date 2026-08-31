<?php

declare(strict_types=1);

namespace Taxora\Sdk\Tests\Fixtures;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/** Stands in for what a PSR-18 client throws on a reset connection or client-side timeout. */
final class FakeNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        string $message = 'cURL error 28: Operation timed out'
    ) {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
