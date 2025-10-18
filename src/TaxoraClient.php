<?php
declare(strict_types=1);

namespace Taxora\Sdk;

use Psr\Http\Client\ClientInterface as Psr18Client;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Taxora\Sdk\Endpoints\AuthEndpoint;
use Taxora\Sdk\Endpoints\CompanyEndpoint;
use Taxora\Sdk\Endpoints\VatEndpoint;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Http\ApiKeyMiddleware;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\TokenStorageInterface;
use Taxora\Sdk\Http\InMemoryTokenStorage;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Exceptions\AuthenticationException;
use Taxora\Sdk\Exceptions\ValidationException;
use Taxora\Sdk\Enums\Environment;

final class TaxoraClient
{
    private string $baseUrl;
    private ApiVersion $apiVersion;
    private ApiKeyMiddleware $apiKeyMw;
    private AuthMiddleware $authMw;
    private TokenStorageInterface $tokenStore;
    private ?AuthEndpoint $authEndpoint = null;
    private ?VatEndpoint $vatEndpoint = null;
    private ?CompanyEndpoint $companyEndpoint = null;

    public function __construct(
        private readonly Psr18Client $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        string $apiKey,
        ?TokenStorageInterface $tokenStorage = null,
        Environment $environment = Environment::SANDBOX,
        ApiVersion $apiVersion = ApiVersion::V1
    ) {
        $this->baseUrl = $environment === Environment::PRODUCTION
            ? 'https://api.taxora.io'
            : 'https://sandbox.taxora.io';

        $this->apiVersion = $apiVersion;
        $this->tokenStore = $tokenStorage ?? new InMemoryTokenStorage();
        $this->apiKeyMw   = new ApiKeyMiddleware($apiKey);
        $this->authMw     = new AuthMiddleware($this->tokenStore);
    }

    /** -------- AUTH -------- */
    public function login(string $email, string $password, ?string $device = null): Http\Token
    {
        return $this->auth()->login($email, $password, $device);
    }

    public function refresh(): Http\Token
    {
        return $this->auth()->refresh();
    }

    /** -------- VAT -------- */
    public function vat(): VatEndpoint
    {
        if ($this->vatEndpoint === null) {
            $this->vatEndpoint = new VatEndpoint(
                $this->http,
                $this->requestFactory,
                $this->streamFactory,
                $this->apiKeyMw,
                $this->authMw,
                $this->tokenStore,
                [$this, 'refresh'],
                $this->baseUrl,
                $this->apiVersion
            );
        }

        return $this->vatEndpoint;
    }

    /** -------- COMPANY -------- */
    public function company(): CompanyEndpoint
    {
        if ($this->companyEndpoint === null) {
            $this->companyEndpoint = new CompanyEndpoint(
                $this->http,
                $this->requestFactory,
                $this->apiKeyMw,
                $this->authMw,
                $this->tokenStore,
                [$this, 'refresh'],
                $this->baseUrl,
                $this->apiVersion
            );
        }

        return $this->companyEndpoint;
    }

    public function auth(): AuthEndpoint
    {
        if ($this->authEndpoint === null) {
            $this->authEndpoint = new AuthEndpoint(
                $this->http,
                $this->requestFactory,
                $this->streamFactory,
                $this->apiKeyMw,
                $this->authMw,
                $this->tokenStore,
                $this->baseUrl,
                $this->apiVersion
            );
        }

        return $this->authEndpoint;
    }

    /** -------- internals -------- */

    private function jsonGet(string $uri): array
    {
        $req = $this->requestFactory->createRequest('GET', $uri);
        $req = ($this->apiKeyMw)($req);
        $req = ($this->authMw)($req);

        $res = $this->http->sendRequest($req);
        if ($res->getStatusCode() === 401) {
            $this->tryRefreshAndRetry($res);
            $res = $this->http->sendRequest(($this->authMw)(($this->apiKeyMw)($req)));
        }
        $this->assertStatus($res, [200]);
        return json_decode((string)$res->getBody(), true);
    }

    private function jsonPost(string $uri, array $body): array
    {
        /** @psalm-suppress PossiblyFalseArgument */
        $req = $this->requestFactory->createRequest('POST', $uri)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($body, JSON_UNESCAPED_SLASHES)));

        $req = ($this->apiKeyMw)($req);
        $req = ($this->authMw)($req);

        $res = $this->http->sendRequest($req);
        if ($res->getStatusCode() === 401) {
            $this->tryRefreshAndRetry($res);
            $res = $this->http->sendRequest(($this->authMw)(($this->apiKeyMw)($req)));
        }

        if ($res->getStatusCode() === 422) {
            throw new ValidationException((string)$res->getBody(), code: 422);
        }

        $this->assertStatus($res, [200, 202]);
        return json_decode((string)$res->getBody(), true);
    }

    private function binaryGet(string $uri): string
    {
        $req = ($this->authMw)(($this->apiKeyMw)($this->requestFactory->createRequest('GET', $uri)));
        $res = $this->http->sendRequest($req);
        if ($res->getStatusCode() === 401) {
            $this->tryRefreshAndRetry($res);
            $res = $this->http->sendRequest(($this->authMw)(($this->apiKeyMw)($req)));
        }
        $this->assertStatus($res, [200]);
        return (string)$res->getBody(); // PDF/ZIP content
    }

    private function binaryPost(string $uri, array $body): string
    {
        /** @psalm-suppress PossiblyFalseArgument */
        $req = $this->requestFactory->createRequest('POST', $uri)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($body, JSON_UNESCAPED_SLASHES)));

        $req = ($this->apiKeyMw)($req);
        $req = ($this->authMw)($req);

        $res = $this->http->sendRequest($req);
        if ($res->getStatusCode() === 401) {
            $this->tryRefreshAndRetry($res);
            $res = $this->http->sendRequest(($this->authMw)(($this->apiKeyMw)($req)));
        }
        $this->assertStatus($res, [200]);
        return (string)$res->getBody();
    }

    private function tryRefreshAndRetry(ResponseInterface $res): void
    {
        // If we have a token, attempt refresh; otherwise bubble the 401
        try {
            $this->refresh();
        } catch (\Throwable) {
            throw new AuthenticationException('Unauthorized and refresh failed: '.(string)$res->getBody(), 401);
        }
    }

    private function assertStatus(ResponseInterface $res, array $allowed): void
    {
        if (!in_array($res->getStatusCode(), $allowed, true)) {
            $code = $res->getStatusCode();
            $body = (string)$res->getBody();
            if ($code === 401) {
                throw new AuthenticationException($body, 401);
            }
            if ($code === 422) {
                throw new ValidationException($body, code: 422);
            }
            throw new HttpException("HTTP $code: $body", $code);
        }
    }
}
