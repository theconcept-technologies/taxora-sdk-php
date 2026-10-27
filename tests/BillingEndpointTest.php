<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Http\Factory\Guzzle\RequestFactory;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Endpoints\BillingEndpoint;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Http\ApiKeyMiddleware;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\InMemoryTokenStorage;
use Taxora\Sdk\Http\RetryPolicy;
use Taxora\Sdk\Tests\Fixtures\SequenceHttpClient;

final class BillingEndpointTest extends TestCase
{
    public function testListUsageSummariesSendsPaginationAndParsesPage(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'data' => [self::summaryPayload()],
                    'meta' => ['current_page' => 2, 'per_page' => 10, 'total' => 11, 'last_page' => 2],
                ],
            ])),
        ]);

        $page = $this->createEndpoint($http)->listUsageSummaries(page: 2, perPage: 10);

        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v1/billing/usage-summaries', $request->getUri()->getPath());
        self::assertSame('page=2&per_page=10', $request->getUri()->getQuery());
        self::assertSame('test-key', $request->getHeaderLine('x-api-key'));

        self::assertCount(1, $page);
        self::assertSame(2, $page->currentPage);
        self::assertSame(10, $page->perPage);
        self::assertSame(11, $page->total);
        self::assertSame(2, $page->lastPage);

        $summary = $page->rows[0];
        self::assertSame(123, $summary->id);
        self::assertSame('ereporting_transaction', $summary->feature);
        self::assertSame('FR', $summary->country);
        self::assertSame('2026-09', $summary->billingPeriod);
        self::assertSame(500, $summary->eventCount);
        self::assertSame('49.00', $summary->billableAmount);
        self::assertSame('open', $summary->state);
        self::assertSame([], $summary->events);
    }

    public function testGetUsageSummariesForPeriodIncludesEvents(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [self::summaryPayload([
                    'events' => [[
                        'id' => 9,
                        'company_id' => 1,
                        'usage_summary_id' => 123,
                        'feature' => 'ereporting_transaction',
                        'country' => 'FR',
                        'regime' => 'dgfip_flux10',
                        'billing_period' => '2026-09',
                        'resource_type' => 'compliance_transaction',
                        'resource_id' => 77,
                        'stripe_usage_record_id' => null,
                        'stripe_reported_at' => null,
                        'created_at' => '2026-09-02T10:00:00+00:00',
                    ]],
                ])],
            ])),
        ]);

        $summaries = $this->createEndpoint($http)->getUsageSummaries('2026-09');

        self::assertSame('/v1/billing/usage-summaries/2026-09', $http->requests[0]->getUri()->getPath());
        self::assertCount(1, $summaries);
        self::assertCount(1, $summaries[0]->events);
        $event = $summaries[0]->events[0];
        self::assertSame(9, $event->id);
        self::assertSame(123, $event->usageSummaryId);
        self::assertSame('compliance_transaction', $event->resourceType);
        self::assertSame(77, $event->resourceId);
        self::assertNull($event->stripeUsageRecordId);
    }

    public function testGetUsageSummariesRejectsMalformedPeriodBeforeHttp(): void
    {
        $http = new SequenceHttpClient([]);

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->createEndpoint($http)->getUsageSummaries('2026-13');
        } finally {
            self::assertCount(0, $http->requests);
        }
    }

    public function testPeriodWithoutUsageThrows404(): void
    {
        $http = new SequenceHttpClient([
            new Response(404, ['Content-Type' => 'application/json'], '{"error":"No usage summaries found for this period"}'),
        ]);

        try {
            $this->createEndpoint($http)->getUsageSummaries('2026-01');
            self::fail('Expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(404, $e->getStatusCode());
        }
    }

    public function testTransientGatewayErrorIsRetried(): void
    {
        $http = new SequenceHttpClient([
            new Response(503, [], 'Service Unavailable'),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => ['data' => [], 'meta' => ['current_page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1]],
            ])),
        ]);

        $page = $this->createEndpoint($http, new RetryPolicy(maxAttempts: 2, initialDelayMs: 0))->listUsageSummaries();

        self::assertCount(0, $page);
        self::assertCount(2, $http->requests);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function summaryPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 123,
            'company_id' => 1,
            'feature' => 'ereporting_transaction',
            'country' => 'FR',
            'regime' => 'dgfip_flux10',
            'billing_period' => '2026-09',
            'event_count' => 500,
            'billable_amount' => '49.00',
            'state' => 'open',
            'state_label' => 'Open',
            'period_closed_at' => null,
            'billing_triggered_at' => null,
            'billed_at' => null,
            'stripe_invoice_id' => null,
            'stripe_subscription_item_id' => null,
            'billing_notes' => null,
            'created_at' => '2026-09-01T00:00:00+00:00',
            'updated_at' => '2026-09-30T00:00:00+00:00',
        ], $overrides);
    }

    private function createEndpoint(SequenceHttpClient $http, ?RetryPolicy $retryPolicy = null): BillingEndpoint
    {
        $tokenStorage = new InMemoryTokenStorage();

        return new BillingEndpoint(
            http: $http,
            req: new RequestFactory(),
            apiKey: new ApiKeyMiddleware('test-key'),
            auth: new AuthMiddleware($tokenStorage),
            tokens: $tokenStorage,
            refreshCallback: static function (): void {
            },
            baseUrl: 'https://sandbox.taxora.io',
            apiVersion: ApiVersion::V1,
            retryPolicy: $retryPolicy,
        );
    }
}
