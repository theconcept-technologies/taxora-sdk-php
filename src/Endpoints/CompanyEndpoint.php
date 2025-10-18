<?php
declare(strict_types=1);

namespace Taxora\Sdk\Endpoints;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Exceptions\AuthenticationException;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Http\ApiKeyMiddleware;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\TokenStorageInterface;
use Throwable;

final class CompanyEndpoint
{
    private readonly Closure $refreshCallback;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $req,
        private readonly ApiKeyMiddleware $apiKey,
        private readonly AuthMiddleware $auth,
        private readonly TokenStorageInterface $tokens,
        callable $refreshCallback,
        private readonly string $baseUrl,
        private readonly ApiVersion $apiVersion = ApiVersion::V1
    ) {
        $this->refreshCallback = Closure::fromCallable($refreshCallback);
    }

    /** Returns your company context (raw array) */
    public function get(): array
    {
        $uri = sprintf('%s/api/%s/company', $this->baseUrl, $this->apiVersion->value);
        $response = $this->send(fn() => $this->req->createRequest('GET', $uri));

        if ($response->getStatusCode() !== 200) {
            throw new HttpException((string) $response->getBody(), $response->getStatusCode());
        }

        return (array) json_decode((string) $response->getBody(), true);
    }

    /** @param callable():\Psr\Http\Message\RequestInterface $factory */
    private function send(callable $factory): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            $this->ensureValidToken();

            $request = $factory();
            $request = ($this->apiKey)($request);
            $request = ($this->auth)($request);

            $response = $this->http->sendRequest($request);
            if ($response->getStatusCode() !== 401) {
                return $response;
            }

            if ($attempt++ >= 1) {
                throw new AuthenticationException((string) $response->getBody(), 401);
            }

            $this->handleUnauthorized((string) $response->getBody());
        }
    }

    private function ensureValidToken(): void
    {
        $token = $this->tokens->get();
        if ($token !== null && $token->isExpired()) {
            $this->refreshTokenOrFail('Token expired before request');
        }
    }

    private function handleUnauthorized(string $body): void
    {
        $this->refreshTokenOrFail($body);
    }

    private function refreshTokenOrFail(string $body): void
    {
        if ($this->tokens->get() === null) {
            throw new AuthenticationException($body, 401);
        }

        try {
            ($this->refreshCallback)();
        } catch (Throwable $exception) {
            throw new AuthenticationException('Unauthorized and refresh failed: '.$body, 401, $exception);
        }
    }
}
