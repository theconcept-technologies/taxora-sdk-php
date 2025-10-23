<?php
declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Endpoints\VatEndpoint;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Enums\Language;
use Taxora\Sdk\Http\ApiKeyMiddleware;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\InMemoryTokenStorage;
use Taxora\Sdk\Tests\Fixtures\SequenceHttpClient;
use Taxora\Sdk\ValueObjects\VatCertificateExport;

final class VatEndpointTest extends TestCase
{
    private RequestFactory $requestFactory;
    private StreamFactory $streamFactory;

    protected function setUp(): void
    {
        $this->requestFactory = new RequestFactory();
        $this->streamFactory = new StreamFactory();
    }

    public function testCertificatesBulkExportFormatsDateTimeParameters(): void
    {
        $http = new SequenceHttpClient([
            new Response(202, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'export_id' => 'exp_123',
                    'message' => 'VAT certificates export initiated. You will receive an email with a download link when the export is complete.',
                ],
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $endpoint = $this->createEndpoint($http);

        $result = $endpoint->certificatesBulkExport(
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-31'),
            ['AT', 'DE'],
            Language::ENGLISH
        );

        self::assertInstanceOf(VatCertificateExport::class, $result);
        self::assertSame('exp_123', $result->exportId);
        self::assertSame(
            'VAT certificates export initiated. You will receive an email with a download link when the export is complete.',
            $result->message
        );

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v1/vat/certificates/bulk-export', $request->getUri()->getPath());

        $payload = json_decode((string) $request->getBody(), true);
        self::assertSame('2024-01-01', $payload['from_date']);
        self::assertSame('2024-01-31', $payload['to_date']);
        self::assertSame(['AT', 'DE'], $payload['countries']);
        self::assertSame('en', $payload['lang']);
    }

    public function testCertificatesBulkExportAllowsStringDates(): void
    {
        $http = new SequenceHttpClient([
            new Response(202, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => ['export_id' => 'exp_456'],
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $endpoint = $this->createEndpoint($http);

        $result = $endpoint->certificatesBulkExport('2024-02-01', '2024-02-29');

        self::assertInstanceOf(VatCertificateExport::class, $result);
        self::assertSame('exp_456', $result->exportId);
        self::assertNull($result->message);

        $payload = json_decode((string) $http->requests[0]->getBody(), true);
        self::assertSame('2024-02-01', $payload['from_date']);
        self::assertSame('2024-02-29', $payload['to_date']);
    }

    public function testCertificatesBulkExportRejectsInvalidDateString(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Date string must be in Y-m-d format.');

        $endpoint->certificatesBulkExport('2024/01/01', '2024-01-31');
        self::assertCount(0, $http->requests, 'No HTTP request should be made when validation fails.');
    }

    public function testCertificatesBulkExportRequiresExportId(): void
    {
        $http = new SequenceHttpClient([
            new Response(202, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => ['message' => 'missing id'],
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $endpoint = $this->createEndpoint($http);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Export response is missing export_id.');

        $endpoint->certificatesBulkExport('2024-03-01', '2024-03-31');
    }

    private function createEndpoint(SequenceHttpClient $http): VatEndpoint
    {
        $tokenStorage = new InMemoryTokenStorage();

        return new VatEndpoint(
            http: $http,
            req: $this->requestFactory,
            stream: $this->streamFactory,
            apiKey: new ApiKeyMiddleware('test-key'),
            auth: new AuthMiddleware($tokenStorage),
            tokens: $tokenStorage,
            refreshCallback: static function (): void {},
            baseUrl: 'https://sandbox.taxora.io',
            apiVersion: ApiVersion::V1
        );
    }
}
