<?php
declare(strict_types=1);

namespace Taxora\Sdk\Tests\Fixtures;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Simple HTTP client stub that returns predefined responses and records requests.
 */
final class SequenceHttpClient implements ClientInterface
{
    /** @var ResponseInterface[] */
    private array $responses;

    /** @var RequestInterface[] */
    public array $requests = [];

    /**
     * @param ResponseInterface[] $responses
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

        return array_shift($this->responses);
    }
}
