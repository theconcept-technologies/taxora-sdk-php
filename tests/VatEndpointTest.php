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
use Taxora\Sdk\ValueObjects\VatValidationAddressInput;

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

    public function testCertificatesListExportFormatsDateTimeParameters(): void
    {
        $http = new SequenceHttpClient([
            new Response(202, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'export_id' => 'exp_list_123',
                    'message' => 'VAT certificates list export initiated.',
                ],
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $endpoint = $this->createEndpoint($http);

        $result = $endpoint->certificatesListExport(
            new \DateTimeImmutable('2024-04-01'),
            new \DateTimeImmutable('2024-04-30'),
            ['AT'],
            Language::GERMAN
        );

        self::assertInstanceOf(VatCertificateExport::class, $result);
        self::assertSame('exp_list_123', $result->exportId);
        self::assertSame('VAT certificates list export initiated.', $result->message);

        $request = $http->requests[0];
        self::assertSame('/v1/vat/certificates/list-export', $request->getUri()->getPath());
        $payload = json_decode((string) $request->getBody(), true);
        self::assertSame('2024-04-01', $payload['from_date']);
        self::assertSame('2024-04-30', $payload['to_date']);
        self::assertSame(['AT'], $payload['countries']);
        self::assertSame('de', $payload['lang']);
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

    public function testDownloadBulkExportSupportsPdfOrZip(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/pdf'], 'PDF_BYTES'),
            new Response(200, ['Content-Type' => 'application/zip'], 'ZIP_BYTES'),
        ]);

        $endpoint = $this->createEndpoint($http);

        $pdf = $endpoint->downloadBulkExport('exp_pdf');
        $zip = $endpoint->downloadBulkExport('exp_zip');

        self::assertSame('PDF_BYTES', $pdf);
        self::assertSame('ZIP_BYTES', $zip);
        self::assertSame('/v1/vat/certificates/download/exp_pdf', $http->requests[0]->getUri()->getPath());
        self::assertSame('/v1/vat/certificates/download/exp_zip', $http->requests[1]->getUri()->getPath());
    }

    public function testValidateIncludesOptionalAddressInputFields(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'vat_uid' => 'ATU12345678',
                    'state' => 'valid',
                    'requested_company_name' => 'Example Company GmbH',
                ],
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $endpoint = $this->createEndpoint($http);

        $endpoint->validate(
            'ATU12345678',
            'John Doe',
            'vies',
            [
                'addressLine1' => ' Example Company GmbH ',
                'addressLine2' => 'Second Floor',
                'postalCode' => '1010',
                'city' => 'Vienna',
                'countryCode' => 'at',
            ]
        );

        self::assertCount(1, $http->requests);
        $payload = json_decode((string) $http->requests[0]->getBody(), true);

        self::assertSame('ATU12345678', $payload['vat_uid']);
        self::assertSame('John Doe', $payload['company_name']);
        self::assertSame('vies', $payload['provider']);
        self::assertSame('Example Company GmbH', $payload['addressLine1']);
        self::assertSame('Second Floor', $payload['addressLine2']);
        self::assertSame('1010', $payload['postalCode']);
        self::assertSame('Vienna', $payload['city']);
        self::assertSame('AT', $payload['countryCode']);
    }

    public function testValidateAcceptsVatValidationAddressInputObject(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'vat_uid' => 'ATU12345678',
                    'state' => 'valid',
                ],
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $endpoint = $this->createEndpoint($http);
        $addressInput = new VatValidationAddressInput(
            addressLine1: 'Example Company GmbH',
            addressLine2: 'Second Floor',
            postalCode: '1010',
            city: 'Vienna',
            countryCode: 'at'
        );

        $endpoint->validate('ATU12345678', 'John Doe', 'vies', $addressInput);

        $payload = json_decode((string) $http->requests[0]->getBody(), true);
        self::assertSame('Example Company GmbH', $payload['addressLine1']);
        self::assertSame('Second Floor', $payload['addressLine2']);
        self::assertSame('1010', $payload['postalCode']);
        self::assertSame('Vienna', $payload['city']);
        self::assertSame('AT', $payload['countryCode']);
    }

    public function testValidateRejectsUnsupportedAddressInputField(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported address input field "line1".');

        $endpoint->validate('ATU12345678', 'Example', null, ['line1' => 'x']);
        self::assertCount(0, $http->requests);
    }

    public function testValidateRejectsAddressLine3InputField(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported address input field "addressLine3".');

        $endpoint->validate('ATU12345678', 'Example', null, ['addressLine3' => 'x']);
        self::assertCount(0, $http->requests);
    }

    public function testValidateRejectsAddressLineLongerThan255Characters(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('addressLine1 exceeds maximum length of 255 characters.');

        $endpoint->validate('ATU12345678', 'Example', null, ['addressLine1' => str_repeat('x', 256)]);
        self::assertCount(0, $http->requests);
    }

    public function testValidateRejectsInvalidCountryCodeLength(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('countryCode must be exactly 2 characters.');

        $endpoint->validate('ATU12345678', 'Example', null, ['countryCode' => 'AUT']);
        self::assertCount(0, $http->requests);
    }

    public function testValidateThrowsForInvalidUtf8RequestData(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to encode request body as JSON:');

        $endpoint->validate('ATU12345678', "\xB1\x31");
        self::assertCount(0, $http->requests);
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
