<?php

declare(strict_types=1);

namespace Taxora\Sdk;

use Psr\Http\Client\ClientInterface as Psr18Client;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Taxora\Sdk\Endpoints\AuthEndpoint;
use Taxora\Sdk\Endpoints\CompanyEndpoint;
use Taxora\Sdk\Endpoints\EReportingEndpoint;
use Taxora\Sdk\Endpoints\SmartEnrichmentEndpoint;
use Taxora\Sdk\Endpoints\VatEndpoint;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Enums\LoginIdentifier;
use Taxora\Sdk\Http\ApiKeyMiddleware;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\RetryPolicy;
use Taxora\Sdk\Http\SdkVersionClient;
use Taxora\Sdk\Http\TokenStorageInterface;
use Taxora\Sdk\Http\InMemoryTokenStorage;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Exceptions\AuthenticationException;
use Taxora\Sdk\Exceptions\ValidationException;
use Taxora\Sdk\Enums\Environment;

final class TaxoraClient
{
    private readonly Psr18Client $http;
    private string $baseUrl;
    private ApiVersion $apiVersion;
    private ApiKeyMiddleware $apiKeyMw;
    private AuthMiddleware $authMw;
    private TokenStorageInterface $tokenStore;
    private RetryPolicy $retryPolicy;
    private ?AuthEndpoint $authEndpoint = null;
    private ?VatEndpoint $vatEndpoint = null;
    private ?CompanyEndpoint $companyEndpoint = null;
    private ?SmartEnrichmentEndpoint $smartEnrichmentEndpoint = null;
    private ?EReportingEndpoint $eReportingEndpoint = null;

    public function __construct(
        Psr18Client $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        string $apiKey,
        ?TokenStorageInterface $tokenStorage = null,
        Environment $environment = Environment::SANDBOX,
        ApiVersion $apiVersion = ApiVersion::V1,
        ?RetryPolicy $retryPolicy = null
    ) {
        // Wrap the transport so every request carries the SDK version header.
        $this->http = new SdkVersionClient($http);

        $this->baseUrl = $environment === Environment::PRODUCTION
            ? 'https://api.taxora.io'
            : 'https://sandbox.taxora.io';

        $this->apiVersion = $apiVersion;
        $this->tokenStore = $tokenStorage ?? new InMemoryTokenStorage();
        $this->apiKeyMw   = new ApiKeyMiddleware($apiKey);
        $this->authMw     = new AuthMiddleware($this->tokenStore);
        // Gateway hiccups (502/503/504) are retried on read-only calls; see RetryPolicy.
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy();
    }

    /** -------- AUTH -------- */
    public function login(
        string $email,
        string $password,
        ?string $device = null,
        LoginIdentifier $loginIdentifier = LoginIdentifier::EMAIL
    ): Http\Token {
        return $this->auth()->login($email, $password, $device, $loginIdentifier);
    }

    public function loginWithClientId(string $clientId, string $password, ?string $device = null): Http\Token
    {
        return $this->auth()->loginWithClientId($clientId, $password, $device);
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
                $this->apiVersion,
                retryPolicy: $this->retryPolicy
            );
        }

        return $this->vatEndpoint;
    }

    /** -------- Smart Enrichment (reverse VAT lookup) -------- */
    public function smartEnrichment(): SmartEnrichmentEndpoint
    {
        if ($this->smartEnrichmentEndpoint === null) {
            $this->smartEnrichmentEndpoint = new SmartEnrichmentEndpoint(
                $this->http,
                $this->requestFactory,
                $this->streamFactory,
                $this->apiKeyMw,
                $this->authMw,
                $this->tokenStore,
                [$this, 'refresh'],
                $this->baseUrl,
                $this->apiVersion,
                retryPolicy: $this->retryPolicy
            );
        }

        return $this->smartEnrichmentEndpoint;
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
                $this->apiVersion,
                retryPolicy: $this->retryPolicy
            );
        }

        return $this->companyEndpoint;
    }

    /** -------- E-REPORTING (DGFiP Flux 10) -------- */
    public function eReporting(): EReportingEndpoint
    {
        if ($this->eReportingEndpoint === null) {
            $this->eReportingEndpoint = new EReportingEndpoint(
                $this->http,
                $this->requestFactory,
                $this->streamFactory,
                $this->apiKeyMw,
                $this->authMw,
                $this->tokenStore,
                [$this, 'refresh'],
                $this->baseUrl,
                $this->apiVersion,
                retryPolicy: $this->retryPolicy
            );
        }

        return $this->eReportingEndpoint;
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
                $this->apiVersion,
                retryPolicy: $this->retryPolicy
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
        return json_decode((string) $res->getBody(), true);
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
            throw ValidationException::fromResponseBody((string) $res->getBody());
        }

        $this->assertStatus($res, [200, 202]);
        return json_decode((string) $res->getBody(), true);
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
        return (string) $res->getBody(); // PDF/ZIP content
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
        return (string) $res->getBody();
    }

    private function tryRefreshAndRetry(ResponseInterface $res): void
    {
        // If we have a token, attempt refresh; otherwise bubble the 401
        try {
            $this->refresh();
        } catch (\Throwable) {
            throw AuthenticationException::refreshFailed((string) $res->getBody());
        }
    }

    private function assertStatus(ResponseInterface $res, array $allowed): void
    {
        if (!in_array($res->getStatusCode(), $allowed, true)) {
            $code = $res->getStatusCode();
            $body = (string) $res->getBody();
            if ($code === 401) {
                throw AuthenticationException::fromResponse($body);
            }
            if ($code === 422) {
                throw ValidationException::fromResponseBody($body);
            }
            throw HttpException::fromResponse($code, $body);
        }
    }
}
