<?php
declare(strict_types=1);

namespace Taxora\Sdk;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface as Psr18Client;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Enums\Environment;
use Taxora\Sdk\Http\TokenStorageInterface;

final class TaxoraClientFactory
{
    /**
     * Create a TaxoraClient using auto-discovered PSR-18/PSR-17 implementations by default.
     */
    public static function create(
        string $apiKey,
        ?TokenStorageInterface $tokenStorage = null,
        Environment $environment = Environment::SANDBOX,
        ApiVersion $apiVersion = ApiVersion::V1,
        ?Psr18Client $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): TaxoraClient {
        $http ??= Psr18ClientDiscovery::find();
        $requestFactory ??= Psr17FactoryDiscovery::findRequestFactory();
        $streamFactory ??= Psr17FactoryDiscovery::findStreamFactory();

        return new TaxoraClient(
            $http,
            $requestFactory,
            $streamFactory,
            $apiKey,
            $tokenStorage,
            $environment,
            $apiVersion
        );
    }
}
