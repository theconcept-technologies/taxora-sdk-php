<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Enums\Environment;
use Taxora\Sdk\TaxoraClient;
use Taxora\Sdk\TaxoraClientFactory;

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

        $client = TaxoraClientFactory::create($apiKey, environment: Environment::SANDBOX);

        $loginResponse = $client->login($username, $password);
        self::assertNotEmpty($loginResponse->accessToken);
        self::assertFalse($loginResponse->isExpired());
        $vatResponse = $client->vat()->validate('FR99345678901');
        self::assertSame(\Taxora\Sdk\Enums\VatState::VALID, $vatResponse->state);
        self::assertSame('FR99345678901', $vatResponse->vat_uid);
        self::assertSame('FR', $vatResponse->country_code);
        self::assertSame('Gamma Industrie SAS', $vatResponse->company_name);
        self::assertSame('10 Rue de Rivoli', $vatResponse->company_address->street);
        self::assertSame('75001', $vatResponse->company_address->postalCode);
        self::assertSame('Paris', $vatResponse->company_address->city);

        $historyResponse = $client->vat()->history();
        self::assertNotEmpty($historyResponse->all());

        $schemaResponse = $client->vat()->validateSchema('ATU11111111');
        self::assertTrue($schemaResponse['success']);
        self::assertTrue($schemaResponse['data']['valid']);
        self::assertNotEmpty($schemaResponse['data']['message']);
    }
}
