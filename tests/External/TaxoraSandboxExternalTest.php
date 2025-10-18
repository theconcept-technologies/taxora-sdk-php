<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Enums\Environment;
use Taxora\Sdk\TaxoraClient;

#[Group("external")]
final class TaxoraSandboxExternalTest extends TestCase
{
    public function testLoginAndCompanyFetchAgainstSandbox(): void
    {
        $apiKey = getenv('TAXORA_API_KEY') ?: '';
        $username = getenv('TAXORA_USERNAME') ?: '';
        $password = getenv('TAXORA_PASSWORD') ?: '';

        $defaultApiKey = 'test_api_key';
        $defaultUsername = 'user@example.com';
        $defaultPassword = 'secret';

        if ($apiKey === '' || $username === '' || $password === '' || $apiKey === $defaultApiKey || $username === $defaultUsername || $password === $defaultPassword) {
            self::markTestSkipped('Real sandbox credentials not provided.');
        }

        $http = new Client(['timeout' => 15]);
        $client = new TaxoraClient(
            http: $http,
            requestFactory: new RequestFactory(),
            streamFactory: new StreamFactory(),
            apiKey: $apiKey,
            tokenStorage: null,
            environment: !empty(getenv('TAXORA_ENV')) ? Environment::tryFrom(getenv('TAXORA_ENV')) : Environment::SANDBOX
        );

        $client->login($username, $password);
        $company = $client->company()->get();

        self::assertIsArray($company);
        self::assertNotEmpty($company);
        self::assertArrayHasKey('data', $company, 'Expected company payload to expose data key');
    }
}
