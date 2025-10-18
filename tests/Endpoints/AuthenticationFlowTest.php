<?php
declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Enums\Environment;
use Taxora\Sdk\Exceptions\AuthenticationException;
use Taxora\Sdk\Http\InMemoryTokenStorage;
use Taxora\Sdk\Http\Token;
use Taxora\Sdk\TaxoraClient;
use Taxora\Sdk\Tests\Fixtures\SequenceHttpClient;

final class AuthenticationFlowTest extends TestCase
{
    private RequestFactory $requestFactory;
    private StreamFactory $streamFactory;

    protected function setUp(): void
    {
        $this->requestFactory = new RequestFactory();
        $this->streamFactory = new StreamFactory();
    }

    public function testExpiredTokenTriggersPreemptiveRefreshBeforeCompanyRequest(): void
    {
        $responses = [
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'Bearer refreshed-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], JSON_UNESCAPED_SLASHES)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'company' => 'Taxora GmbH',
            ], JSON_UNESCAPED_SLASHES)),
        ];

        $http = new SequenceHttpClient($responses);
        $store = new InMemoryTokenStorage();
        $store->set(new Token('expired-token', 'Bearer', new \DateTimeImmutable('-5 minutes')));

        $client = new TaxoraClient(
            http: $http,
            requestFactory: $this->requestFactory,
            streamFactory: $this->streamFactory,
            apiKey: 'test-key',
            tokenStorage: $store,
            environment: Environment::SANDBOX
        );

        $result = $client->company()->get();

        self::assertSame(['company' => 'Taxora GmbH'], $result);
        self::assertCount(2, $http->requests, 'Expected refresh call followed by company request');

        $refreshRequest = $http->requests[0];
        self::assertSame('POST', $refreshRequest->getMethod());
        self::assertSame('https://sandbox.taxora.io/api/v1/refresh', (string) $refreshRequest->getUri());
        self::assertSame(['test-key'], $refreshRequest->getHeader('x-api-key'));
        self::assertSame(['Bearer expired-token'], $refreshRequest->getHeader('Authorization'));

        $companyRequest = $http->requests[1];
        self::assertSame('GET', $companyRequest->getMethod());
        self::assertSame('https://sandbox.taxora.io/api/v1/company', (string) $companyRequest->getUri());
        self::assertSame(['Bearer refreshed-token'], $companyRequest->getHeader('Authorization'));
    }

    public function testUnauthorizedResponseTriggersRefreshAndRetry(): void
    {
        $responses = [
            new Response(401, ['Content-Type' => 'application/json'], json_encode(['message' => 'expired'], JSON_UNESCAPED_SLASHES)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'Bearer replacement-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], JSON_UNESCAPED_SLASHES)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'company' => 'Taxora GmbH',
            ], JSON_UNESCAPED_SLASHES)),
        ];

        $http = new SequenceHttpClient($responses);
        $store = new InMemoryTokenStorage();
        $store->set(new Token('initial-token', 'Bearer', new \DateTimeImmutable('+10 minutes')));

        $client = new TaxoraClient(
            http: $http,
            requestFactory: $this->requestFactory,
            streamFactory: $this->streamFactory,
            apiKey: 'test-key',
            tokenStorage: $store,
            environment: Environment::SANDBOX
        );

        $result = $client->company()->get();

        self::assertSame(['company' => 'Taxora GmbH'], $result);
        self::assertCount(3, $http->requests, 'Expected original request, refresh call, then retry');

        $firstRequest = $http->requests[0];
        self::assertSame('GET', $firstRequest->getMethod());
        self::assertSame(['Bearer initial-token'], $firstRequest->getHeader('Authorization'));

        $refreshRequest = $http->requests[1];
        self::assertSame('POST', $refreshRequest->getMethod());
        self::assertSame('https://sandbox.taxora.io/api/v1/refresh', (string) $refreshRequest->getUri());
        self::assertSame(['Bearer initial-token'], $refreshRequest->getHeader('Authorization'));

        $retryRequest = $http->requests[2];
        self::assertSame('GET', $retryRequest->getMethod());
        self::assertSame(['Bearer replacement-token'], $retryRequest->getHeader('Authorization'));
    }

    public function testRefreshFailureBubblesAuthenticationException(): void
    {
        $responses = [
            new Response(401, ['Content-Type' => 'application/json'], json_encode(['message' => 'expired'], JSON_UNESCAPED_SLASHES)),
            new Response(401, ['Content-Type' => 'application/json'], json_encode(['message' => 'refresh failed'], JSON_UNESCAPED_SLASHES)),
        ];

        $http = new SequenceHttpClient($responses);
        $store = new InMemoryTokenStorage();
        $store->set(new Token('stale-token', 'Bearer', new \DateTimeImmutable('+1 minute')));

        $client = new TaxoraClient(
            http: $http,
            requestFactory: $this->requestFactory,
            streamFactory: $this->streamFactory,
            apiKey: 'test-key',
            tokenStorage: $store,
            environment: Environment::SANDBOX
        );

        $this->expectException(AuthenticationException::class);

        try {
            $client->company()->get();
        } finally {
            self::assertCount(2, $http->requests);
            self::assertSame('POST', $http->requests[1]->getMethod(), 'Second request should target refresh endpoint');
        }
    }
}
