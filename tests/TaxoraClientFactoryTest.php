<?php
declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Enums\Environment;
use Taxora\Sdk\Http\InMemoryTokenStorage;
use Taxora\Sdk\Http\TokenStorageInterface;
use Taxora\Sdk\TaxoraClientFactory;

final class TaxoraClientFactoryTest extends TestCase
{
    public function testCreateWithDiscoveryDefaults(): void
    {
        $client = TaxoraClientFactory::create('test-key');

        self::assertSame('https://sandbox.taxora.io', $this->readProperty($client, 'baseUrl'));
        self::assertSame(ApiVersion::V1, $this->readProperty($client, 'apiVersion'));
        self::assertInstanceOf(InMemoryTokenStorage::class, $this->readProperty($client, 'tokenStore'));
    }

    public function testCreateUsesProvidedOverrides(): void
    {
        $http = new class implements ClientInterface {
            public array $requests = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;

                return new Response(204);
            }
        };

        $requestFactory = new RequestFactory();
        $streamFactory  = new StreamFactory();
        $tokenStorage   = $this->createMock(TokenStorageInterface::class);

        $client = TaxoraClientFactory::create(
            apiKey: 'custom-key',
            tokenStorage: $tokenStorage,
            environment: Environment::PRODUCTION,
            apiVersion: ApiVersion::V1,
            http: $http,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory
        );

        self::assertSame($http, $this->readProperty($client, 'http'));
        self::assertSame($requestFactory, $this->readProperty($client, 'requestFactory'));
        self::assertSame($streamFactory, $this->readProperty($client, 'streamFactory'));
        self::assertSame($tokenStorage, $this->readProperty($client, 'tokenStore'));
        self::assertSame('https://api.taxora.io', $this->readProperty($client, 'baseUrl'));
    }

    private function readProperty(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }
}
