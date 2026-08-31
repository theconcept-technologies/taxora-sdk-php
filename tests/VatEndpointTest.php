<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Endpoints\VatEndpoint;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Enums\Language;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Exceptions\ValidationException;
use Taxora\Sdk\Http\ApiKeyMiddleware;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\InMemoryTokenStorage;
use Taxora\Sdk\Http\RetryPolicy;
use Taxora\Sdk\Tests\Fixtures\FakeNetworkException;
use Taxora\Sdk\Tests\Fixtures\SequenceHttpClient;
use Taxora\Sdk\ValueObjects\VatCertificateExport;
use Taxora\Sdk\ValueObjects\VatResource;
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
                    'has_api_error' => true,
                    'error_message' => 'Official registry temporarily unavailable.',
                    'next_api_recheck_at' => '2026-04-24T14:00:00Z',
                ],
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $endpoint = $this->createEndpoint($http);

        $result = $endpoint->validate(
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

        self::assertTrue($result->has_api_error);
        self::assertSame('Official registry temporarily unavailable.', $result->error_message);
        self::assertSame('2026-04-24T14:00:00Z', $result->next_api_recheck_at);

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

    public function testValidateRetriesOnGatewayTimeoutAndReturnsVatResource(): void
    {
        $html504 = '<html><body>via _upstream (504 -)</body></html>';
        $http = new SequenceHttpClient([
            new Response(504, ['Content-Type' => 'text/html'], $html504),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'vat_uid' => 'ATU12345678',
                    'state' => 'valid',
                ],
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $endpoint = $this->createEndpoint($http);

        $result = $endpoint->validate('ATU12345678', 'Example GmbH');

        self::assertInstanceOf(VatResource::class, $result);
        self::assertSame('ATU12345678', $result->vat_uid);
        self::assertCount(2, $http->requests);
    }

    public function testValidateThrowsCleanMessageAfterExhaustingRetries(): void
    {
        $html504 = '<html><body>via _upstream (504 -)</body></html>';
        $http = new SequenceHttpClient([
            new Response(504, ['Content-Type' => 'text/html'], $html504),
            new Response(504, ['Content-Type' => 'text/html'], $html504),
            new Response(504, ['Content-Type' => 'text/html'], $html504),
        ]);

        $endpoint = $this->createEndpoint($http);

        try {
            $endpoint->validate('ATU12345678');
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame(504, $exception->getStatusCode());
            self::assertSame(
                'Taxora VAT validation failed after 3 attempts (HTTP 504 Gateway Timeout).',
                $exception->getMessage()
            );
            self::assertSame($html504, $exception->getResponseBody());
            self::assertCount(3, $http->requests);

            // The gateway error page must not leak into the chained messages
            // either -- integrators log the whole chain, not just the top one.
            for ($previous = $exception->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
                self::assertStringNotContainsString('<', $previous->getMessage());
                self::assertSame('Taxora API request failed (HTTP 504 Gateway Timeout).', $previous->getMessage());
            }
        }
    }

    public function testHtmlErrorPageNeverBecomesTheExceptionMessage(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html>
            <html>
            <head><meta name="robots" content="noindex"></head>
            <body><p class="code">Error code: 502</p></body>
            </html>
            HTML;

        $http = new SequenceHttpClient([
            new Response(502, ['Content-Type' => 'text/html'], $html),
        ]);

        $endpoint = $this->createEndpoint($http, RetryPolicy::disabled());

        try {
            $endpoint->validate('ATU12345678');
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame('Taxora API request failed (HTTP 502 Bad Gateway).', $exception->getMessage());
            self::assertStringNotContainsString('<', $exception->getMessage());
            // The raw page stays available for debugging.
            self::assertSame($html, $exception->getResponseBody());
        }
    }

    public function testRateLimitIsRetriedOnlyWhenTheApiSaysHowLongToWait(): void
    {
        $slept = [];
        $policy = new RetryPolicy(sleeper: static function (int $ms) use (&$slept): void {
            $slept[] = $ms;
        });

        $http = new SequenceHttpClient([
            new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '2'], '{"message":"Too Many Requests"}'),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => ['vat_uid' => 'ATU12345678', 'state' => 'valid'],
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $result = $this->createEndpoint($http, $policy)->validate('ATU12345678');

        self::assertInstanceOf(VatResource::class, $result);
        self::assertCount(2, $http->requests);
        self::assertSame([2000], $slept, 'waits exactly as long as the API asked');
    }

    public function testRateLimitWithoutRetryAfterFailsImmediately(): void
    {
        $http = new SequenceHttpClient([
            new Response(429, ['Content-Type' => 'application/json'], '{"message":"Too Many Requests"}'),
        ]);

        try {
            $this->createEndpoint($http)->validate('ATU12345678');
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame(429, $exception->getStatusCode());
            self::assertSame('Too Many Requests', $exception->getMessage());
            self::assertCount(1, $http->requests);
        }
    }

    public function testRetryAfterLongerThanTheCapFailsImmediately(): void
    {
        $http = new SequenceHttpClient([
            new Response(504, ['Content-Type' => 'text/html', 'Retry-After' => '120'], '<html><body>504</body></html>'),
        ]);

        try {
            $this->createEndpoint($http)->validate('ATU12345678');
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame('120', $exception->getRetryAfter());
            self::assertCount(1, $http->requests, 'blocking the caller for two minutes is worse than failing');
        }
    }

    public function testTransportFailureIsRetried(): void
    {
        $request = new Request('POST', 'https://sandbox.taxora.io/v1/vat/validate');
        $http = new SequenceHttpClient([
            new FakeNetworkException($request),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => ['vat_uid' => 'ATU12345678', 'state' => 'valid'],
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $result = $this->createEndpoint($http)->validate('ATU12345678');

        self::assertInstanceOf(VatResource::class, $result);
        self::assertCount(2, $http->requests);
    }

    public function testTransportFailureIsRethrownUnchangedWhenRetriesAreUsedUp(): void
    {
        $request = new Request('POST', 'https://sandbox.taxora.io/v1/vat/validate');
        $http = new SequenceHttpClient([
            new FakeNetworkException($request),
            new FakeNetworkException($request),
            new FakeNetworkException($request),
        ]);

        try {
            $this->createEndpoint($http)->validate('ATU12345678');
            $this->fail('Expected FakeNetworkException to be thrown.');
        } catch (FakeNetworkException $exception) {
            // Callers keep seeing their own HTTP client's error type and message.
            self::assertSame('cURL error 28: Operation timed out', $exception->getMessage());
            self::assertCount(3, $http->requests);
        }
    }

    public function testTransportFailureIsNotRetriedWhenDisabled(): void
    {
        $request = new Request('POST', 'https://sandbox.taxora.io/v1/vat/validate');
        $http = new SequenceHttpClient([
            new FakeNetworkException($request),
            new FakeNetworkException($request),
        ]);

        $endpoint = $this->createEndpoint($http, new RetryPolicy(initialDelayMs: 0, retryOnNetworkErrors: false));

        try {
            $endpoint->validate('ATU12345678');
            $this->fail('Expected FakeNetworkException to be thrown.');
        } catch (FakeNetworkException) {
            self::assertCount(1, $http->requests);
        }
    }

    public function testRetriesCanBeTurnedOffCompletely(): void
    {
        // Callers that do their own retrying (queue workers, cron jobs) opt out
        // with RetryPolicy::disabled() and get the failure on the first attempt.
        $http = new SequenceHttpClient([
            new Response(504, ['Content-Type' => 'text/html'], '<html><body>504</body></html>'),
        ]);

        $endpoint = $this->createEndpoint($http, RetryPolicy::disabled());

        try {
            $endpoint->validate('ATU12345678');
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame(504, $exception->getStatusCode());
            self::assertSame('Taxora API request failed (HTTP 504 Gateway Timeout).', $exception->getMessage());
            self::assertCount(1, $http->requests, 'no second attempt when retrying is disabled');
        }
    }

    public function testRetryAttemptsAreConfigurable(): void
    {
        $http = new SequenceHttpClient([
            new Response(504, ['Content-Type' => 'text/html'], '<html><body>504</body></html>'),
            new Response(504, ['Content-Type' => 'text/html'], '<html><body>504</body></html>'),
        ]);

        $endpoint = $this->createEndpoint($http, RetryPolicy::withoutDelay(maxAttempts: 2));

        try {
            $endpoint->validate('ATU12345678');
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame(
                'Taxora VAT validation failed after 2 attempts (HTTP 504 Gateway Timeout).',
                $exception->getMessage()
            );
            self::assertCount(2, $http->requests);
        }
    }

    public function testValidateRetriesOn502And503AndWaitsBetweenAttempts(): void
    {
        $slept = [];
        $policy = new RetryPolicy(sleeper: static function (int $ms) use (&$slept): void {
            $slept[] = $ms;
        });

        $http = new SequenceHttpClient([
            new Response(502, ['Content-Type' => 'text/html'], '<html><body>bad gateway</body></html>'),
            new Response(503, ['Content-Type' => 'text/html'], '<html><body>unavailable</body></html>'),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => ['vat_uid' => 'ATU12345678', 'state' => 'valid'],
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $result = $this->createEndpoint($http, $policy)->validate('ATU12345678');

        self::assertInstanceOf(VatResource::class, $result);
        self::assertCount(3, $http->requests);
        self::assertSame([500, 1000], $slept, 'exponential backoff between attempts');
    }

    public function testStateLookupIsRetried(): void
    {
        $http = new SequenceHttpClient([
            new Response(504, ['Content-Type' => 'text/html'], '<html><body>504</body></html>'),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => ['vat_uid' => 'ATU12345678', 'state' => 'valid'],
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $result = $this->createEndpoint($http)->state('ATU12345678');

        self::assertInstanceOf(VatResource::class, $result);
        self::assertCount(2, $http->requests);
    }

    public function testCertificateExportIsNotRetried(): void
    {
        // Triggers a server-side job and an e-mail: a gateway timeout does not tell
        // us whether it already ran, so this must fail fast instead of duplicating it.
        $http = new SequenceHttpClient([
            new Response(504, ['Content-Type' => 'text/html'], '<html><body>504</body></html>'),
        ]);

        $endpoint = $this->createEndpoint($http);

        try {
            $endpoint->certificatesBulkExport('2024-01-01', '2024-01-31');
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame(504, $exception->getStatusCode());
            self::assertSame('Taxora API request failed (HTTP 504 Gateway Timeout).', $exception->getMessage());
            self::assertCount(1, $http->requests);
        }
    }

    public function testValidateDoesNotRetryOnNonGatewayHttpError(): void
    {
        $http = new SequenceHttpClient([
            new Response(500, ['Content-Type' => 'text/plain'], 'server error'),
        ]);

        $endpoint = $this->createEndpoint($http);

        try {
            $endpoint->validate('ATU12345678');
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame(500, $exception->getStatusCode());
            self::assertSame('server error', $exception->getMessage());
            self::assertCount(1, $http->requests);
        }
    }

    public function testValidateDoesNotRetryOnValidationError(): void
    {
        $http = new SequenceHttpClient([
            new Response(422, ['Content-Type' => 'application/json'], '{"message":"invalid vat"}'),
        ]);

        $endpoint = $this->createEndpoint($http);

        try {
            $endpoint->validate('ATU12345678');
            $this->fail('Expected ValidationException to be thrown.');
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertCount(1, $http->requests);
        }
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

    private function createEndpoint(SequenceHttpClient $http, ?RetryPolicy $retryPolicy = null): VatEndpoint
    {
        $tokenStorage = new InMemoryTokenStorage();

        return new VatEndpoint(
            http: $http,
            req: $this->requestFactory,
            stream: $this->streamFactory,
            apiKey: new ApiKeyMiddleware('test-key'),
            auth: new AuthMiddleware($tokenStorage),
            tokens: $tokenStorage,
            refreshCallback: static function (): void {
            },
            baseUrl: 'https://sandbox.taxora.io',
            apiVersion: ApiVersion::V1,
            // No waiting between attempts: the backoff itself is covered in RetryPolicyTest.
            retryPolicy: $retryPolicy ?? RetryPolicy::withoutDelay()
        );
    }
}
