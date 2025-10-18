<?php
declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Taxora\Sdk\Exceptions\AuthenticationException;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Endpoints\AuthEndpoint;
use Taxora\Sdk\Http\{ApiKeyMiddleware, AuthMiddleware, InMemoryTokenStorage, Token};

final class AuthTest extends TestCase
{
    public function testLoginStoresTokenAndUsesApiKeyHeader(): void
    {
        $responses = [new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'success' => 1,
            'data' => [
                'access_token' => 'abc123',
                'token_type' => 'Bearer',
                'expires_in' => 120,
            ],
        ], JSON_UNESCAPED_SLASHES))];

        $client = new ArrayHttpClient($responses);
        $requestFactory = new RequestFactory();
        $streamFactory = new StreamFactory();
        $store = new InMemoryTokenStorage();

        $endpoint = new AuthEndpoint(
            $client,
            $requestFactory,
            $streamFactory,
            new ApiKeyMiddleware('test-key'),
            new AuthMiddleware($store),
            $store,
            'https://sandbox.taxora.io'
        );

        $token = $endpoint->login('user@example.com', 'secret', 'unit-test-device');

        self::assertSame('abc123', $token->accessToken);
        self::assertFalse($token->isExpired());
        self::assertSame('abc123', $store->get()?->accessToken);

        $request = $client->requests[0] ?? null;
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(['test-key'], $request->getHeader('x-api-key'));
        self::assertFalse($request->hasHeader('Authorization'));
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame([
            'email' => 'user@example.com',
            'password' => 'secret',
            'device_name' => 'unit-test-device',
        ], $body);
    }

    public function testRefreshUsesStoredTokenAndReplacesIt(): void
    {
        $responses = [new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'success' => true,
            'data' => [
                'access_token' => 'Bearer new-token',
                'token_type' => 'Bearer',
                'expires_in' => 180,
            ],
        ], JSON_UNESCAPED_SLASHES))];

        $client = new ArrayHttpClient($responses);
        $requestFactory = new RequestFactory();
        $streamFactory = new StreamFactory();
        $store = new InMemoryTokenStorage();
        $store->set(new Token('initial', 'Bearer', new \DateTimeImmutable('+10 minutes')));

        $endpoint = new AuthEndpoint(
            $client,
            $requestFactory,
            $streamFactory,
            new ApiKeyMiddleware('test-key'),
            new AuthMiddleware($store),
            $store,
            'https://sandbox.taxora.io'
        );

        $endpoint->refresh();

        $request = $client->requests[0] ?? null;
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(['test-key'], $request->getHeader('x-api-key'));
        self::assertSame(['Bearer initial'], $request->getHeader('Authorization'));
        self::assertSame('', (string) $request->getBody());

        $stored = $store->get();
        self::assertInstanceOf(Token::class, $stored);
        self::assertSame('new-token', $stored->accessToken);
        self::assertSame('Bearer', $stored->tokenType);
    }

    public function testLoginThrowsAuthenticationExceptionOnUnauthorized(): void
    {
        $responses = [new Response(401, ['Content-Type' => 'application/json'], json_encode([
            'success' => 0,
            'message' => 'invalid credentials'
        ], JSON_UNESCAPED_SLASHES))];

        $client = new ArrayHttpClient($responses);
        $requestFactory = new RequestFactory();
        $streamFactory = new StreamFactory();
        $store = new InMemoryTokenStorage();

        $endpoint = new AuthEndpoint(
            $client,
            $requestFactory,
            $streamFactory,
            new ApiKeyMiddleware('test-key'),
            new AuthMiddleware($store),
            $store,
            'https://sandbox.taxora.io'
        );

        try {
            $endpoint->login('user@example.com', 'wrong-secret', 'unit-test-device');
            $this->fail('Expected AuthenticationException to be thrown.');
        } catch (AuthenticationException $exception) {
            self::assertSame('{"success":0,"message":"invalid credentials"}', $exception->getMessage());
        }

        self::assertNull($store->get());

        $request = $client->requests[0] ?? null;
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(['test-key'], $request->getHeader('x-api-key'));
        self::assertFalse($request->hasHeader('Authorization'));
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame('unit-test-device', $body['device_name'] ?? null);
    }

    public function testLoginThrowsHttpExceptionOnUnexpectedStatus(): void
    {
        $responses = [new Response(500, ['Content-Type' => 'application/json'], json_encode([
            'success' => 0,
            'message' => 'server down'
        ], JSON_UNESCAPED_SLASHES))];

        $client = new ArrayHttpClient($responses);
        $requestFactory = new RequestFactory();
        $streamFactory = new StreamFactory();
        $store = new InMemoryTokenStorage();

        $endpoint = new AuthEndpoint(
            $client,
            $requestFactory,
            $streamFactory,
            new ApiKeyMiddleware('test-key'),
            new AuthMiddleware($store),
            $store,
            'https://sandbox.taxora.io'
        );

        try {
            $endpoint->login('user@example.com', 'secret', 'unit-test-device');
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame('{"success":0,"message":"server down"}', $exception->getMessage());
            self::assertSame(500, $exception->getStatusCode());
            self::assertSame('{"success":0,"message":"server down"}', $exception->getResponseBody());
        }

        self::assertNull($store->get());
    }

    public function testLoginGeneratesDeviceIdentifierWhenMissing(): void
    {
        $responses = [new Response(401, ['Content-Type' => 'application/json'], json_encode([
            'success' => 0,
            'message' => 'invalid credentials'
        ], JSON_UNESCAPED_SLASHES))];

        $client = new ArrayHttpClient($responses);
        $requestFactory = new RequestFactory();
        $streamFactory = new StreamFactory();
        $store = new InMemoryTokenStorage();

        $endpoint = new AuthEndpoint(
            $client,
            $requestFactory,
            $streamFactory,
            new ApiKeyMiddleware('test-key'),
            new AuthMiddleware($store),
            $store,
            'https://sandbox.taxora.io'
        );

        try {
            $endpoint->login('user@example.com', 'secret');
        } catch (AuthenticationException) {
            // expected because we mocked a 401 response
        }

        $request = $client->requests[0] ?? null;
        self::assertInstanceOf(RequestInterface::class, $request);
        $body = json_decode((string) $request->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('device_name', $body);
        self::assertNotSame('', trim((string) $body['device_name']));
    }

    public function testRefreshThrowsAuthenticationExceptionOnUnauthorized(): void
    {
        $responses = [new Response(401, ['Content-Type' => 'application/json'], json_encode([
            'success' => 0,
            'message' => 'token expired'
        ], JSON_UNESCAPED_SLASHES))];

        $client = new ArrayHttpClient($responses);
        $requestFactory = new RequestFactory();
        $streamFactory = new StreamFactory();
        $store = new InMemoryTokenStorage();
        $store->set(new Token('initial', 'Bearer', new \DateTimeImmutable('+10 minutes')));

        $endpoint = new AuthEndpoint(
            $client,
            $requestFactory,
            $streamFactory,
            new ApiKeyMiddleware('test-key'),
            new AuthMiddleware($store),
            $store,
            'https://sandbox.taxora.io'
        );

        try {
            $endpoint->refresh();
            $this->fail('Expected AuthenticationException to be thrown.');
        } catch (AuthenticationException $exception) {
            self::assertSame('{"success":0,"message":"token expired"}', $exception->getMessage());
        }

        $stored = $store->get();
        self::assertInstanceOf(Token::class, $stored);
        self::assertSame('initial', $stored->accessToken);
        self::assertSame('Bearer', $stored->tokenType);

        $request = $client->requests[0] ?? null;
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(['test-key'], $request->getHeader('x-api-key'));
        self::assertSame(['Bearer initial'], $request->getHeader('Authorization'));
    }

    public function testRefreshThrowsHttpExceptionOnUnexpectedStatus(): void
    {
        $responses = [new Response(500, ['Content-Type' => 'application/json'], json_encode([
            'success' => 0,
            'message' => 'service unavailable'
        ], JSON_UNESCAPED_SLASHES))];

        $client = new ArrayHttpClient($responses);
        $requestFactory = new RequestFactory();
        $streamFactory = new StreamFactory();
        $store = new InMemoryTokenStorage();
        $store->set(new Token('initial', 'Bearer', new \DateTimeImmutable('+10 minutes')));

        $endpoint = new AuthEndpoint(
            $client,
            $requestFactory,
            $streamFactory,
            new ApiKeyMiddleware('test-key'),
            new AuthMiddleware($store),
            $store,
            'https://sandbox.taxora.io'
        );

        try {
            $endpoint->refresh();
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame('{"success":0,"message":"service unavailable"}', $exception->getMessage());
            self::assertSame(500, $exception->getStatusCode());
            self::assertSame('{"success":0,"message":"service unavailable"}', $exception->getResponseBody());
        }

        $stored = $store->get();
        self::assertInstanceOf(Token::class, $stored);
        self::assertSame('initial', $stored->accessToken);
        self::assertSame('Bearer', $stored->tokenType);
    }
}

if (!class_exists(ArrayHttpClient::class)) {
    final class ArrayHttpClient implements ClientInterface
    {
        /** @var ResponseInterface[] */
        private array $responses;

        /** @var RequestInterface[] */
        public array $requests = [];

        /** @param ResponseInterface[] $responses */
        public function __construct(array $responses)
        {
            $this->responses = $responses;
        }

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            if ($this->responses === []) {
                throw new \RuntimeException('No more responses configured');
            }

            $this->requests[] = $request;

            return array_shift($this->responses);
        }
    }
}
