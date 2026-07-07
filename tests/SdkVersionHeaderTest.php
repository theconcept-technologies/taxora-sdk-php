<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Enums\Environment;
use Taxora\Sdk\TaxoraClient;
use Taxora\Sdk\Tests\Fixtures\SequenceHttpClient;
use Taxora\Sdk\Version;

final class SdkVersionHeaderTest extends TestCase
{
    public function testEveryRequestCarriesTheSdkVersionHeader(): void
    {
        $client = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => 1,
                'data' => [
                    'access_token' => 'abc123',
                    'token_type' => 'Bearer',
                    'expires_in' => 120,
                ],
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $taxora = new TaxoraClient(
            $client,
            new RequestFactory(),
            new StreamFactory(),
            'test-key',
            null,
            Environment::SANDBOX,
        );

        $taxora->login('user@example.com', 'secret', 'unit-test-device');

        $request = $client->requests[0] ?? null;
        self::assertNotNull($request);
        self::assertSame(
            ['taxora-php/' . Version::SDK],
            $request->getHeader('X-Taxora-SDK-Version'),
        );
    }
}
