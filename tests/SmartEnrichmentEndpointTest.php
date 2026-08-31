<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Endpoints\SmartEnrichmentEndpoint;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Enums\SmartEnrichmentMode;
use Taxora\Sdk\Enums\SmartEnrichmentStatus;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Exceptions\TimeoutException;
use Taxora\Sdk\Exceptions\ValidationException;
use Taxora\Sdk\Http\ApiKeyMiddleware;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\InMemoryTokenStorage;
use Taxora\Sdk\Http\Token;
use Taxora\Sdk\Tests\Fixtures\SequenceHttpClient;
use Taxora\Sdk\ValueObjects\SmartEnrichmentJob;
use Taxora\Sdk\ValueObjects\SmartEnrichmentStatistics;

final class SmartEnrichmentEndpointTest extends TestCase
{
    private RequestFactory $requestFactory;
    private StreamFactory $streamFactory;

    protected function setUp(): void
    {
        $this->requestFactory = new RequestFactory();
        $this->streamFactory = new StreamFactory();
    }

    public function testLookupReturnsSynchronousFoundResult(): void
    {
        // Matches the backend's flat single response (camelCase, no data wrapper).
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jobId' => '11111111-1111-1111-1111-111111111111',
                'status' => 'found',
                'vatNumber' => 'ATU12345678',
                'vatType' => 'vat_id',
                'confidence' => 96.0,
                'matchedCompanyName' => 'Example Company GmbH',
                'matchedAddress' => 'Hauptstraße 10, 1010 Wien, AT',
                'country' => 'AT',
                'source' => 'corpus',
            ])),
        ]);

        $job = $this->createEndpoint($http)->lookup('Example Company GmbH', 'AT', street: 'Main Street 10', city: 'Vienna');

        self::assertInstanceOf(SmartEnrichmentJob::class, $job);
        self::assertSame(SmartEnrichmentStatus::FOUND, $job->status);
        self::assertFalse($job->isProcessing());
        self::assertNotNull($job->result());
        self::assertSame('ATU12345678', $job->result()->vatNumber);
        self::assertSame(96.0, $job->result()->confidence);
        self::assertSame('Hauptstraße 10, 1010 Wien, AT', $job->result()->matchedAddress);
        self::assertSame('Hauptstraße 10, 1010 Wien, AT', $job->result()->toArray()['matchedAddress']);

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v1/smart-enrichment', $request->getUri()->getPath());

        $payload = json_decode((string) $request->getBody(), true);
        self::assertSame('Example Company GmbH', $payload['companyName']);
        self::assertSame('AT', $payload['country']);
        self::assertSame('Main Street 10', $payload['street']);
        self::assertArrayNotHasKey('postalCode', $payload, 'null fields are not sent');
    }

    public function testLookupReturnsProcessingJobWhenAsync(): void
    {
        $http = new SequenceHttpClient([
            new Response(202, ['Content-Type' => 'application/json'], json_encode([
                'jobId' => '22222222-2222-2222-2222-222222222222',
                'status' => 'processing',
            ])),
        ]);

        $job = $this->createEndpoint($http)->lookup('Unknown Co', 'DE');

        self::assertSame(SmartEnrichmentStatus::PROCESSING, $job->status);
        self::assertTrue($job->isProcessing());
        self::assertNull($job->result());
        self::assertSame('22222222-2222-2222-2222-222222222222', $job->jobId);
    }

    public function testLookupUnwrapsDataEnvelopeResponse(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'jobId' => '44444444-4444-4444-4444-444444444444',
                    'status' => 'found',
                    'vatNumber' => 'DE123456789',
                    'confidence' => 92.0,
                ],
            ])),
        ]);

        $job = $this->createEndpoint($http)->lookup('Example GmbH', 'DE');

        self::assertSame('44444444-4444-4444-4444-444444444444', $job->jobId);
        self::assertSame(SmartEnrichmentStatus::FOUND, $job->status);
        self::assertSame('DE123456789', $job->result()?->vatNumber);
    }

    public function testLookupRejectsEmptyCompanyName(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('companyName must not be empty.');

        $endpoint->lookup('   ', 'AT');
        self::assertCount(0, $http->requests, 'No HTTP request should be made when validation fails.');
    }

    public function testLookupRejectsInvalidCountryCode(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('country must be an ISO 3166-1 alpha-2 code (exactly 2 characters).');

        $endpoint->lookup('Example GmbH', 'AUT');
        self::assertCount(0, $http->requests);
    }

    public function testBulkLookupPostsItemsAndReturnsProcessingJob(): void
    {
        $http = new SequenceHttpClient([
            new Response(202, ['Content-Type' => 'application/json'], json_encode([
                'jobId' => '33333333-3333-3333-3333-333333333333',
                'status' => 'processing',
                'itemCount' => 2,
            ])),
        ]);

        $job = $this->createEndpoint($http)->bulkLookup([
            ['companyName' => 'A GmbH', 'country' => 'DE'],
            ['companyName' => 'B SARL', 'country' => 'FR'],
        ]);

        self::assertSame(2, $job->itemCount);
        self::assertTrue($job->isProcessing());

        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v1/smart-enrichment/bulk', $request->getUri()->getPath());
        $payload = json_decode((string) $request->getBody(), true);
        self::assertCount(2, $payload['items']);
        self::assertSame('A GmbH', $payload['items'][0]['companyName']);
    }

    public function testBulkLookupRejectsEmptyItems(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bulkLookup requires at least one item.');

        $endpoint->bulkLookup([]);
        self::assertCount(0, $http->requests);
    }

    public function testBulkLookupRejectsInvalidItemWithIndexInMessage(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('items[1]: country must be an ISO 3166-1 alpha-2 code (exactly 2 characters).');

        $endpoint->bulkLookup([
            ['companyName' => 'A GmbH', 'country' => 'DE'],
            ['companyName' => 'B SARL', 'country' => 'FRA'],
        ]);
        self::assertCount(0, $http->requests);
    }

    public function testBulkLookupRejectsItemWithoutCompanyName(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('items[0]: companyName must not be empty.');

        $endpoint->bulkLookup([
            ['country' => 'DE'],
        ]);
        self::assertCount(0, $http->requests);
    }

    public function testGetParsesBulkJobWithPerItemResults(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jobId' => '33333333-3333-3333-3333-333333333333',
                'status' => 'completed',
                'itemCount' => 2,
                'completedCount' => 2,
                'foundCount' => 1,
                'results' => [
                    ['status' => 'found', 'vatNumber' => 'DE123456789', 'confidence' => 91.0, 'country' => 'DE', 'source' => 'ai_web'],
                    ['status' => 'no_vat_exists', 'vatNumber' => null, 'confidence' => 0, 'country' => 'FR', 'source' => 'registry:fr'],
                ],
            ])),
        ]);

        $job = $this->createEndpoint($http)->get('33333333-3333-3333-3333-333333333333');

        self::assertSame(SmartEnrichmentStatus::COMPLETED, $job->status);
        self::assertSame(2, $job->itemCount);
        self::assertSame(1, $job->foundCount);
        self::assertCount(2, $job->results);
        self::assertSame(SmartEnrichmentStatus::FOUND, $job->results[0]->status);
        self::assertSame('DE123456789', $job->results[0]->vatNumber);
        self::assertSame(SmartEnrichmentStatus::NO_VAT_EXISTS, $job->results[1]->status);

        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v1/smart-enrichment/33333333-3333-3333-3333-333333333333', $request->getUri()->getPath());
    }

    public function testGetRejectsEmptyJobId(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('jobId must not be empty.');

        $endpoint->get('  ');
        self::assertCount(0, $http->requests);
    }

    public function testGetUrlEncodesJobId(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jobId' => 'job id/1',
                'status' => 'completed',
            ])),
        ]);

        $this->createEndpoint($http)->get('job id/1');

        self::assertSame('/v1/smart-enrichment/job%20id%2F1', $http->requests[0]->getUri()->getPath());
    }

    public function testGetMapsUnknownStatusToUnknown(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jobId' => '55555555-5555-5555-5555-555555555555',
                'status' => 'something_new',
            ])),
        ]);

        $job = $this->createEndpoint($http)->get('55555555-5555-5555-5555-555555555555');

        self::assertSame(SmartEnrichmentStatus::UNKNOWN, $job->status);
        self::assertFalse($job->isProcessing());
    }

    public function testGetTreatsQueuedJobAsProcessing(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jobId' => '66666666-6666-6666-6666-666666666666',
                'status' => 'queued',
            ])),
        ]);

        $job = $this->createEndpoint($http)->get('66666666-6666-6666-6666-666666666666');

        self::assertSame(SmartEnrichmentStatus::QUEUED, $job->status);
        self::assertTrue($job->isProcessing());
    }

    public function testWaitUntilCompletePollsUntilJobLeavesProcessing(): void
    {
        $processing = json_encode(['jobId' => 'wait-1', 'status' => 'processing']);
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], $processing),
            new Response(200, ['Content-Type' => 'application/json'], $processing),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jobId' => 'wait-1',
                'status' => 'completed',
                'itemCount' => 1,
                'foundCount' => 1,
                'results' => [
                    ['status' => 'found', 'vatNumber' => 'ATU12345678', 'confidence' => 95.0],
                ],
            ])),
        ]);

        $sleeps = [];
        $endpoint = $this->createEndpoint($http, sleep: static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $job = $endpoint->waitUntilComplete('wait-1', timeoutSeconds: 30.0, pollIntervalSeconds: 1.5);

        self::assertSame(SmartEnrichmentStatus::COMPLETED, $job->status);
        self::assertSame('ATU12345678', $job->results[0]->vatNumber);
        self::assertCount(3, $http->requests, 'One initial poll plus two follow-ups');
        self::assertSame([1.5, 1.5], $sleeps, 'Sleeps exactly the poll interval between polls');
    }

    public function testWaitUntilCompleteReturnsImmediatelyWhenJobIsAlreadyDone(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jobId' => 'wait-2',
                'status' => 'found',
                'vatNumber' => 'ATU12345678',
            ])),
        ]);

        $sleeps = [];
        $endpoint = $this->createEndpoint($http, sleep: static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $job = $endpoint->waitUntilComplete('wait-2');

        self::assertSame(SmartEnrichmentStatus::FOUND, $job->status);
        self::assertCount(1, $http->requests);
        self::assertSame([], $sleeps, 'Never sleeps when the first poll is already final');
    }

    public function testWaitUntilCompleteThrowsTimeoutExceptionWhenJobStaysProcessing(): void
    {
        $processing = json_encode(['jobId' => 'wait-3', 'status' => 'processing']);
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], $processing),
            new Response(200, ['Content-Type' => 'application/json'], $processing),
            new Response(200, ['Content-Type' => 'application/json'], $processing),
        ]);

        $sleeps = [];
        $endpoint = $this->createEndpoint($http, sleep: static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        try {
            $endpoint->waitUntilComplete('wait-3', timeoutSeconds: 5.0, pollIntervalSeconds: 2.0);
            $this->fail('Expected TimeoutException to be thrown.');
        } catch (TimeoutException $exception) {
            self::assertStringContainsString('wait-3', $exception->getMessage());
            self::assertStringContainsString('5.0 seconds', $exception->getMessage());
            self::assertCount(3, $http->requests, 'Polls at 0s, 2s and 4s — the next poll would exceed the timeout');
            self::assertSame([2.0, 2.0], $sleeps);
        }
    }

    public function testWaitUntilCompleteRejectsNonPositiveTimeout(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('timeoutSeconds must be greater than 0.');

        $endpoint->waitUntilComplete('job-1', timeoutSeconds: 0.0);
        self::assertCount(0, $http->requests);
    }

    public function testWaitUntilCompleteRejectsNonPositivePollInterval(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pollIntervalSeconds must be greater than 0.');

        $endpoint->waitUntilComplete('job-1', pollIntervalSeconds: -1.0);
        self::assertCount(0, $http->requests);
    }

    public function testHistoryReturnsPaginatedRows(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    [
                        'jobId' => 'aaaa1111-1111-1111-1111-111111111111',
                        'status' => 'found',
                        'isBulk' => false,
                        'itemCount' => 1,
                        'foundCount' => 1,
                        'query' => ['companyName' => 'Example GmbH', 'country' => 'AT', 'city' => 'Vienna'],
                        'input' => [
                            'companyName' => 'Example GmbH',
                            'street' => 'Main Street 10',
                            'postalCode' => '1010',
                            'city' => 'Vienna',
                            'country' => 'AT',
                        ],
                        'result' => ['status' => 'found', 'vatNumber' => 'ATU12345678', 'confidence' => 96],
                        'createdAt' => '2026-06-23T10:00:00+00:00',
                        'finishedAt' => '2026-06-23T10:00:01+00:00',
                    ],
                ],
                'meta' => ['currentPage' => 1, 'perPage' => 25, 'total' => 1, 'lastPage' => 1],
                'stats' => ['total' => 42, 'found' => 30],
            ])),
        ]);

        $page = $this->createEndpoint($http)->history();

        self::assertCount(1, $page);
        self::assertSame(1, $page->total);
        self::assertSame(SmartEnrichmentStatus::FOUND, $page->rows[0]->status);
        self::assertSame('Example GmbH', $page->rows[0]->queryCompanyName);
        self::assertSame('ATU12345678', $page->rows[0]->result?->vatNumber);
        self::assertNotNull($page->rows[0]->input);
        self::assertSame('Main Street 10', $page->rows[0]->input->street);
        self::assertSame('1010', $page->rows[0]->input->postalCode);
        self::assertSame('AT', $page->rows[0]->input->country);
        self::assertNotNull($page->stats);
        self::assertSame(42, $page->stats->total);
        self::assertSame(30, $page->stats->found);

        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v1/smart-enrichment/history', $request->getUri()->getPath());
        self::assertSame('page=1&perPage=25', $request->getUri()->getQuery());
    }

    public function testHistorySendsSearchAndCustomPagination(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [],
                'meta' => ['currentPage' => 3, 'perPage' => 50, 'total' => 120, 'lastPage' => 3],
            ])),
        ]);

        $page = $this->createEndpoint($http)->history(page: 3, perPage: 50, search: 'Example GmbH');

        self::assertSame(3, $page->currentPage);
        self::assertSame(50, $page->perPage);
        self::assertSame('page=3&perPage=50&search=Example%20GmbH', $http->requests[0]->getUri()->getQuery());
    }

    public function testHistoryHandlesEmptyPageWithoutMetaOrStats(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => []])),
        ]);

        $page = $this->createEndpoint($http)->history();

        self::assertCount(0, $page);
        self::assertSame(1, $page->currentPage);
        self::assertSame(0, $page->perPage);
        self::assertSame(0, $page->total);
        self::assertSame(1, $page->lastPage);
        self::assertNull($page->stats);
    }

    public function testUsageReturnsQuotaInfo(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'period' => '2026-06', 'used' => 312, 'included' => 300, 'remaining' => 0,
                'overageCount' => 12, 'overagePrice' => 0.08, 'hardCap' => null,
            ])),
        ]);

        $usage = $this->createEndpoint($http)->usage();

        self::assertSame(312, $usage->used);
        self::assertSame(300, $usage->included);
        self::assertSame(12, $usage->overageCount);
        self::assertSame(0.08, $usage->overagePrice);
        self::assertNull($usage->hardCap);
        self::assertSame([], $usage->history, 'Missing history defaults to an empty list');
        self::assertSame('/v1/smart-enrichment/usage', $http->requests[0]->getUri()->getPath());
    }

    public function testUsageParsesBillingHistoryAndHardCap(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'period' => '2026-06', 'used' => 420, 'included' => 300, 'remaining' => 0,
                'overageCount' => 120, 'overagePrice' => 0.08, 'overageAmount' => 9.60, 'hardCap' => 500,
                'history' => [
                    ['period' => '2026-05', 'matches' => 420, 'amount' => '9.60', 'state' => 'billed', 'billingType' => 'manual', 'manualReference' => 'INV-2026-0042'],
                    ['period' => '2026-04', 'matches' => 280, 'amount' => '0.00', 'state' => 'pending'],
                ],
            ])),
        ]);

        $usage = $this->createEndpoint($http)->usage();

        self::assertSame(500, $usage->hardCap);
        self::assertSame(9.60, $usage->overageAmount);
        self::assertCount(2, $usage->history);
        self::assertSame('2026-05', $usage->history[0]->period);
        self::assertSame(420, $usage->history[0]->matches);
        self::assertSame('9.60', $usage->history[0]->amount);
        self::assertSame('billed', $usage->history[0]->state);
        self::assertSame('manual', $usage->history[0]->billingType);
        self::assertSame('INV-2026-0042', $usage->history[0]->manualReference);
        self::assertSame('pending', $usage->history[1]->state);
        self::assertNull($usage->history[1]->billingType);
        self::assertNull($usage->history[1]->manualReference);
    }

    public function testExportBuildsFilterQueryAndReturnsCsvVerbatim(): void
    {
        $csv = "\xEF\xBB\xBF" . "companyName,street,postalCode,city,country,vatNumber\nExample GmbH,Main Street 10,1010,Vienna,AT,ATU12345678\n";
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'text/csv; charset=UTF-8'], $csv),
        ]);

        $body = $this->createEndpoint($http)->export(
            dateFrom: '2026-01-01',
            dateTo: '2026-06-30',
            minConfidence: 80.0,
            status: ['found', SmartEnrichmentStatus::NOT_FOUND],
            onlyFound: true,
        );

        self::assertSame($csv, $body, 'CSV body is returned verbatim (incl. UTF-8 BOM)');

        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v1/smart-enrichment/export', $request->getUri()->getPath());
        self::assertSame(
            'date_from=2026-01-01&date_to=2026-06-30&min_confidence=80&status%5B%5D=found&status%5B%5D=not_found&only_found=1',
            $request->getUri()->getQuery()
        );
        self::assertSame('text/csv, application/json', $request->getHeaderLine('Accept'));
    }

    public function testExportWithoutFiltersSendsNoQuery(): void
    {
        $csv = "\xEF\xBB\xBF" . "companyName,street,postalCode,city,country,vatNumber\n";
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'text/csv; charset=UTF-8'], $csv),
        ]);

        $body = $this->createEndpoint($http)->export();

        self::assertSame($csv, $body);
        self::assertSame('', $http->requests[0]->getUri()->getQuery());
    }

    public function testExportSerializesOnlyFoundFalseAsZero(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'text/csv; charset=UTF-8'], 'header-only'),
        ]);

        $this->createEndpoint($http)->export(onlyFound: false);

        self::assertSame('only_found=0', $http->requests[0]->getUri()->getQuery());
    }

    public function testExportThrowsValidationExceptionOn422(): void
    {
        $http = new SequenceHttpClient([
            new Response(422, ['Content-Type' => 'application/json'], '{"message":"date_to must be after or equal to date_from"}'),
        ]);

        $endpoint = $this->createEndpoint($http);

        try {
            $endpoint->export(dateFrom: '2026-06-30', dateTo: '2026-01-01');
            $this->fail('Expected ValidationException to be thrown.');
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertCount(1, $http->requests);
        }
    }

    public function testStatisticsMapsFullPayload(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'range' => ['date_from' => '2026-01-01', 'date_to' => '2026-06-30', 'interval' => 'day'],
                'totals' => ['jobs' => 42, 'items' => 120, 'found' => 96, 'found_rate' => 80, 'avg_confidence' => 87.4],
                'time_series' => [
                    ['bucket' => '2026-06-01', 'jobs' => 3, 'items' => 10, 'found' => 8],
                    ['bucket' => '2026-06-02', 'jobs' => 1, 'items' => 2, 'found' => 1],
                ],
                'by_source' => [
                    ['source' => 'api', 'jobs' => 30, 'found' => 70],
                    ['source' => 'dashboard', 'jobs' => 12, 'found' => 26],
                ],
                'by_outcome' => [
                    ['outcome' => 'found', 'count' => 96],
                    ['outcome' => 'not_found', 'count' => 20],
                ],
                'confidence_buckets' => [
                    ['bucket' => 'high', 'count' => 80],
                    ['bucket' => 'medium', 'count' => 12],
                    ['bucket' => 'low', 'count' => 4],
                ],
            ])),
        ]);

        $stats = $this->createEndpoint($http)->statistics(dateFrom: '2026-01-01', dateTo: '2026-06-30', interval: 'day');

        self::assertInstanceOf(SmartEnrichmentStatistics::class, $stats);
        self::assertSame('2026-01-01', $stats->dateFrom);
        self::assertSame('2026-06-30', $stats->dateTo);
        self::assertSame('day', $stats->interval);
        self::assertSame(42, $stats->totals->jobs);
        self::assertSame(120, $stats->totals->items);
        self::assertSame(96, $stats->totals->found);
        self::assertSame(80.0, $stats->totals->foundRate);
        self::assertSame(87.4, $stats->totals->avgConfidence);
        self::assertCount(2, $stats->timeSeries);
        self::assertSame('2026-06-01', $stats->timeSeries[0]->bucket);
        self::assertSame(3, $stats->timeSeries[0]->jobs);
        self::assertSame(10, $stats->timeSeries[0]->items);
        self::assertSame(8, $stats->timeSeries[0]->found);
        self::assertSame([['source' => 'api', 'jobs' => 30, 'found' => 70], ['source' => 'dashboard', 'jobs' => 12, 'found' => 26]], $stats->bySource);
        self::assertSame([['outcome' => 'found', 'count' => 96], ['outcome' => 'not_found', 'count' => 20]], $stats->byOutcome);
        self::assertSame('high', $stats->confidenceBuckets[0]['bucket']);
        self::assertSame(80, $stats->confidenceBuckets[0]['count']);

        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v1/smart-enrichment/statistics', $request->getUri()->getPath());
        self::assertSame('date_from=2026-01-01&date_to=2026-06-30&interval=day', $request->getUri()->getQuery());
    }

    public function testStatisticsOmitsNullParamsFromQuery(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'range' => ['date_from' => '2025-07-06', 'date_to' => '2026-07-06', 'interval' => 'month'],
                'totals' => ['jobs' => 0, 'items' => 0, 'found' => 0, 'found_rate' => 0, 'avg_confidence' => 0],
                'time_series' => [],
                'by_source' => [],
                'by_outcome' => [],
                'confidence_buckets' => [],
            ])),
        ]);

        $stats = $this->createEndpoint($http)->statistics();

        self::assertSame('month', $stats->interval);
        self::assertSame(0, $stats->totals->jobs);
        self::assertSame([], $stats->timeSeries);
        self::assertSame('', $http->requests[0]->getUri()->getQuery());
    }

    public function testStatisticsRejectsInvalidInterval(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('interval must be one of "day", "week", "month", got "year".');

        $endpoint->statistics(interval: 'year');
        self::assertCount(0, $http->requests);
    }

    public function testValidationErrorThrowsValidationException(): void
    {
        $http = new SequenceHttpClient([
            new Response(422, ['Content-Type' => 'application/json'], '{"message":"The country field must be 2 characters."}'),
        ]);

        $endpoint = $this->createEndpoint($http);

        try {
            $endpoint->lookup('Example GmbH', 'XX');
            $this->fail('Expected ValidationException to be thrown.');
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertCount(1, $http->requests);
        }
    }

    public function testServerErrorThrowsHttpException(): void
    {
        $http = new SequenceHttpClient([
            new Response(500, ['Content-Type' => 'text/plain'], 'server error'),
        ]);

        $endpoint = $this->createEndpoint($http);

        try {
            $endpoint->get('job-1');
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame(500, $exception->getStatusCode());
            self::assertSame('server error', $exception->getMessage());
            self::assertCount(1, $http->requests);
        }
    }

    public function testFailedSuccessEnvelopeThrowsHttpException(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => false,
                'message' => 'Smart Enrichment access not active.',
            ])),
        ]);

        $endpoint = $this->createEndpoint($http);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Smart Enrichment access not active.');

        $endpoint->usage();
    }

    public function testInvalidJsonBodyThrowsHttpException(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], 'not-json'),
        ]);

        $endpoint = $this->createEndpoint($http);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Failed to decode JSON response from Taxora API.');

        $endpoint->get('job-1');
    }

    public function testUnauthorizedResponseTriggersRefreshAndRetry(): void
    {
        $http = new SequenceHttpClient([
            new Response(401, ['Content-Type' => 'application/json'], '{"message":"expired"}'),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'period' => '2026-06', 'used' => 1, 'included' => 300, 'remaining' => 299,
                'overageCount' => 0, 'overagePrice' => 0.08,
            ])),
        ]);

        $tokenStorage = new InMemoryTokenStorage();
        $tokenStorage->set(new Token('stale-token', 'Bearer', new DateTimeImmutable('+10 minutes')));

        $refreshes = 0;
        $endpoint = $this->createEndpoint(
            $http,
            tokenStorage: $tokenStorage,
            refreshCallback: static function () use ($tokenStorage, &$refreshes): void {
                $refreshes++;
                $tokenStorage->set(new Token('refreshed-token', 'Bearer', new DateTimeImmutable('+10 minutes')));
            },
        );

        $usage = $endpoint->usage();

        self::assertSame(1, $usage->used);
        self::assertSame(1, $refreshes);
        self::assertCount(2, $http->requests);
        self::assertSame(['Bearer stale-token'], $http->requests[0]->getHeader('Authorization'));
        self::assertSame(['Bearer refreshed-token'], $http->requests[1]->getHeader('Authorization'));
    }

    // ---------------------------------------------------------------------
    // Search mode
    // ---------------------------------------------------------------------

    private function processingResponse(string $jobId = 'job-1'): Response
    {
        return new Response(202, ['Content-Type' => 'application/json'], json_encode([
            'jobId' => $jobId,
            'status' => 'processing',
        ]));
    }

    /** @return array<string,mixed> */
    private function decodeBody(SequenceHttpClient $http, int $index = 0): array
    {
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $http->requests[$index]->getBody(), true);

        return $decoded;
    }

    public function testLookupOmitsModeWhenTheCallerDoesNotPickOne(): void
    {
        $http = new SequenceHttpClient([$this->processingResponse()]);

        $this->createEndpoint($http)->lookup('Example GmbH', 'DE');

        // The server default must stay the server's decision — sending an explicit 'default'
        // would pin the SDK to today's default if that ever changes.
        self::assertArrayNotHasKey('mode', $this->decodeBody($http));
    }

    public function testLookupSendsTheRequestedMode(): void
    {
        $http = new SequenceHttpClient([$this->processingResponse()]);

        $this->createEndpoint($http)->lookup(
            'Vodafone D2 GmbH',
            'DE',
            postalCode: '40547',
            mode: SmartEnrichmentMode::COMPLEX,
        );

        $body = $this->decodeBody($http);
        self::assertSame('complex', $body['mode']);
        self::assertSame('40547', $body['postalCode']);
    }

    public function testLookupAcceptsTheModeAsAString(): void
    {
        $http = new SequenceHttpClient([$this->processingResponse()]);

        $this->createEndpoint($http)->lookup('Example GmbH', 'DE', mode: 'COMPLEX');

        self::assertSame('complex', $this->decodeBody($http)['mode']);
    }

    public function testLookupRejectsAnUnknownModeBeforeCallingTheApi(): void
    {
        $http = new SequenceHttpClient([$this->processingResponse()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mode must be one of');

        $this->createEndpoint($http)->lookup('Example GmbH', 'DE', mode: 'thorough');
    }

    public function testBulkLookupSendsABatchLevelMode(): void
    {
        $http = new SequenceHttpClient([$this->processingResponse('bulk-1')]);

        $this->createEndpoint($http)->bulkLookup(
            [
                ['companyName' => 'A GmbH', 'country' => 'DE'],
                ['companyName' => 'B GmbH', 'country' => 'AT'],
            ],
            SmartEnrichmentMode::COMPLEX,
        );

        $body = $this->decodeBody($http);
        self::assertSame('complex', $body['mode']);
        self::assertCount(2, $body['items']);
    }

    public function testBulkLookupLetsAPerRowModeRideAlong(): void
    {
        $http = new SequenceHttpClient([$this->processingResponse('bulk-2')]);

        $this->createEndpoint($http)->bulkLookup([
            ['companyName' => 'Cheap GmbH', 'country' => 'DE'],
            ['companyName' => 'Hard GmbH', 'country' => 'DE', 'mode' => 'complex'],
        ]);

        $body = $this->decodeBody($http);
        self::assertArrayNotHasKey('mode', $body);
        self::assertSame('complex', $body['items'][1]['mode']);
    }

    public function testResultExposesModeAndCrossCheckDiagnostics(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jobId' => 'job-verdicts',
                'status' => 'found',
                'vatNumber' => 'DE811704788',
                'confidence' => 92.0,
                'country' => 'DE',
                'source' => 'ai_web:consensus',
                'mode' => 'complex',
                'providerVerdicts' => [
                    ['provider' => 'gemini', 'model' => 'gemini-3.1-flash-lite', 'status' => 'not_found', 'vatNumber' => null, 'confidence' => 0, 'grounded' => true, 'sourceUrl' => null],
                    ['provider' => 'perplexity', 'model' => 'sonar-pro', 'status' => 'found', 'vatNumber' => 'DE811704788', 'confidence' => 92, 'grounded' => true, 'sourceUrl' => 'https://example.test/impressum'],
                ],
                'addressQuality' => [
                    'providedPostalCode' => '40547',
                    'providedCity' => null,
                    'postalCodeTrusted' => true,
                    'derivedPlace' => 'Düsseldorf',
                    'warnings' => ['city_derived_from_postal_code'],
                ],
            ])),
        ]);

        $result = $this->createEndpoint($http)->lookup('Vodafone D2 GmbH', 'DE')->result();

        self::assertNotNull($result);
        self::assertSame(SmartEnrichmentMode::COMPLEX, $result->mode);
        self::assertCount(2, $result->providerVerdicts);
        self::assertSame('perplexity', $result->providerVerdicts[1]['provider']);
        self::assertSame('Düsseldorf', $result->addressQuality['derivedPlace'] ?? null);
        self::assertSame('complex', $result->toArray()['mode']);
    }

    public function testResultLeavesModeNullWhenTheApiDoesNotReportOne(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jobId' => 'job-plain',
                'status' => 'not_found',
                'country' => 'DE',
            ])),
        ]);

        $result = $this->createEndpoint($http)->lookup('Example GmbH', 'DE')->result();

        self::assertNotNull($result);
        self::assertNull($result->mode);
        self::assertSame([], $result->providerVerdicts);
        self::assertNull($result->addressQuality);
    }

    private function createEndpoint(
        SequenceHttpClient $http,
        ?callable $sleep = null,
        ?InMemoryTokenStorage $tokenStorage = null,
        ?callable $refreshCallback = null
    ): SmartEnrichmentEndpoint {
        $tokenStorage ??= new InMemoryTokenStorage();

        return new SmartEnrichmentEndpoint(
            http: $http,
            req: $this->requestFactory,
            stream: $this->streamFactory,
            apiKey: new ApiKeyMiddleware('test-key'),
            auth: new AuthMiddleware($tokenStorage),
            tokens: $tokenStorage,
            refreshCallback: $refreshCallback ?? static function (): void {
            },
            baseUrl: 'https://sandbox.taxora.io',
            apiVersion: ApiVersion::V1,
            sleep: $sleep
        );
    }
}
