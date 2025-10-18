<?php
declare(strict_types=1);


namespace Taxora\Sdk\Http;

use Psr\Http\Message\RequestInterface;

final readonly class ApiKeyMiddleware
{
    public function __construct(private string $apiKey) {}

    public function __invoke(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('x-api-key', $this->apiKey);
    }
}