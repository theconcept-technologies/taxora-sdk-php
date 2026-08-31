<?php

declare(strict_types=1);

namespace Taxora\Sdk\Tests\Fixtures;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Simple HTTP client stub that returns predefined responses and records requests.
 * An entry may also be a Throwable, which is thrown instead of returned — that is
 * how a transport failure (connection reset, client timeout) is simulated.
 */
final class SequenceHttpClient implements ClientInterface
{
    /** @var array<int, ResponseInterface|Throwable> */
    private array $responses;

    /** @var RequestInterface[] */
    public array $requests = [];

    /**
     * @param array<int, ResponseInterface|Throwable> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->responses === []) {
            throw new RuntimeException('No more responses configured');
        }

        $this->requests[] = $request;

        $next = array_shift($this->responses);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}
