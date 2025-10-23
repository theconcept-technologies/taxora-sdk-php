<?php
declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Http\Factory\Guzzle\RequestFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Taxora\Sdk\Endpoints\CompanyEndpoint;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Http\{ApiKeyMiddleware, AuthMiddleware, InMemoryTokenStorage, Token};

final class CompanyTest extends TestCase
{
    public function testGetReturnsCompanyPayloadWithHeaders(): void
    {
        $payload = [
            'name' => 'Taxora GmbH',
            'id' => 'company-1',
        ];

        $responses = [new Response(200, ['Content-Type' => 'application/json'], json_encode($payload, JSON_UNESCAPED_SLASHES))];
        $client = new ArrayHttpClient($responses);
        $requestFactory = new RequestFactory();
        $store = new InMemoryTokenStorage();
        $store->set(new Token('token-abc', 'Bearer', new \DateTimeImmutable('+10 minutes')));

        $endpoint = new CompanyEndpoint(
            $client,
            $requestFactory,
            new ApiKeyMiddleware('api-key'),
            new AuthMiddleware($store),
            $store,
            static function (): void {},
            'https://sandbox.taxora.io'
        );

        $result = $endpoint->get();

        self::assertSame($payload, $result);

        $request = $client->requests[0] ?? null;
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://sandbox.taxora.io/v1/company', (string) $request->getUri());
        self::assertSame(['api-key'], $request->getHeader('x-api-key'));
        self::assertSame(['Bearer token-abc'], $request->getHeader('Authorization'));
    }

    public function testGetThrowsHttpExceptionOnError(): void
    {
        $responses = [new Response(500, ['Content-Type' => 'application/json'], 'server error')];
        $client = new ArrayHttpClient($responses);
        $requestFactory = new RequestFactory();

        $store = new InMemoryTokenStorage();

        $endpoint = new CompanyEndpoint(
            $client,
            $requestFactory,
            new ApiKeyMiddleware('api-key'),
            new AuthMiddleware($store),
            $store,
            static function (): void {},
            'https://sandbox.taxora.io'
        );

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('server error');

        $endpoint->get();
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
