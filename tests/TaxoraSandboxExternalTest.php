<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Enums\Environment;
use Taxora\Sdk\Enums\VatState;
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

        $client = TaxoraClientFactory::create($apiKey, environment: Environment::SANDBOX);

        $loginResponse = $client->login($username, $password);
        self::assertNotEmpty($loginResponse->accessToken);
        self::assertFalse($loginResponse->isExpired());

        $loginResponse = $client->loginWithClientId('client_123', 'client-secret', device: 'integration-box');
        self::assertNotEmpty($loginResponse->accessToken);
        self::assertFalse($loginResponse->isExpired());

        $vatResponse = $client->vat()->validate('FR99345678901');
        self::assertSame(VatState::VALID, $vatResponse->state);
        self::assertSame('FR99345678901', $vatResponse->vat_uid);
        self::assertSame('FR', $vatResponse->country_code);
        self::assertSame('Gamma Industrie SAS', $vatResponse->company_name);
        self::assertSame('10 Rue de Rivoli', $vatResponse->company_address->street);
        self::assertSame('75001', $vatResponse->company_address->postalCode);
        self::assertSame('Paris', $vatResponse->company_address->city);
        self::assertSame('SANDBOX', $vatResponse->environment);
        self::assertSame(sprintf('https://app.taxora.io/vat-history/%s/%s', $vatResponse->environment, $vatResponse->uuid), $vatResponse->getBackendLink());
        self::assertEquals(['sandbox'], $vatResponse->used_providers);
        self::assertSame('sandbox', $vatResponse->provider);
        self::assertSame(VatState::VALID->value, $vatResponse->provider_vat_state);

        $historyResponse = $client->vat()->history();
        self::assertNotEmpty($historyResponse->all());

        $schemaResponse = $client->vat()->validateSchema('ATU11111111');
        self::assertTrue($schemaResponse['success']);
        self::assertTrue($schemaResponse['data']['valid']);
        self::assertNotEmpty($schemaResponse['data']['message']);
    }
}
