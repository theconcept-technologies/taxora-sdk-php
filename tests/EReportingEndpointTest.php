<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Endpoints\EReportingEndpoint;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Enums\ComplianceEnrollmentStatus;
use Taxora\Sdk\Enums\ComplianceTaxReportState;
use Taxora\Sdk\Enums\ComplianceTransactionState;
use Taxora\Sdk\Enums\ComplianceTransactionType;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Exceptions\ValidationException;
use Taxora\Sdk\Http\ApiKeyMiddleware;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\InMemoryTokenStorage;
use Taxora\Sdk\Http\Token;
use Taxora\Sdk\Tests\Fixtures\SequenceHttpClient;
use Taxora\Sdk\ValueObjects\ComplianceInvoiceLine;
use Taxora\Sdk\ValueObjects\ComplianceLineTax;
use Taxora\Sdk\ValueObjects\ComplianceTransaction;
use Taxora\Sdk\ValueObjects\RevenueStatistics;
use Taxora\Sdk\ValueObjects\RevenueTimeBucket;
use Taxora\Sdk\ValueObjects\RevenueTotals;

final class EReportingEndpointTest extends TestCase
{
    private RequestFactory $requestFactory;
    private StreamFactory $streamFactory;

    protected function setUp(): void
    {
        $this->requestFactory = new RequestFactory();
        $this->streamFactory = new StreamFactory();
    }

    /* ---------- enrollments ---------- */

    public function testCreateEnrollmentPostsSnakeCaseBodyAndReturnsEnrollment(): void
    {
        $http = new SequenceHttpClient([
            new Response(201, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => self::enrollmentPayload(),
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $enrollment = $this->createEndpoint($http)->createEnrollment(
            email: 'tax@acme.fr',
            nafCode: '47',
            enterpriseSize: 'pme',
            typeOperation: 'mixed',
            reportingStartDate: new DateTimeImmutable('2026-09-01'),
            siret: '12345678900012',
            vatNumber: 'FR32123456789',
            companyName: 'Acme SARL',
            address: '10 Rue de la Paix',
            city: 'Paris',
            postalcode: '75001',
            autoActivate: false,
        );

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v1/compliance/enrollments', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $request->getBody(), true);
        self::assertSame('12345678900012', $payload['siret']);
        self::assertSame('FR32123456789', $payload['vat_number']);
        self::assertSame('Acme SARL', $payload['company_name']);
        self::assertSame('tax@acme.fr', $payload['email']);
        self::assertSame('47', $payload['naf_code']);
        self::assertSame('pme', $payload['enterprise_size']);
        self::assertSame('mixed', $payload['type_operation']);
        self::assertSame('2026-09-01', $payload['reporting_start_date']);
        self::assertFalse($payload['auto_activate'], 'auto_activate=false must survive the null filter');
        self::assertArrayNotHasKey('siren', $payload, 'null fields are not sent');
        self::assertArrayNotHasKey('province', $payload);

        self::assertSame(12, $enrollment->id);
        self::assertSame(42, $enrollment->companyId);
        self::assertSame(ComplianceEnrollmentStatus::REGIME_ACTIVATED, $enrollment->status);
        self::assertSame('Regime activated', $enrollment->statusLabel);
        self::assertSame('83428', $enrollment->providerAccountId);
        self::assertSame('FR32123456789', $enrollment->taxId);
        self::assertSame('12345678900012', $enrollment->companyRegisterId);
        self::assertSame(['naf_code' => '47', 'enterprise_size' => 'pme', 'type_operation' => 'mixed'], $enrollment->regimeConfig);
        self::assertSame('2026-09-01', $enrollment->reportingStartDate);
        self::assertSame('tax@acme.fr', $enrollment->notificationEmail);
        self::assertTrue($enrollment->autoSend);
    }

    public function testCreateEnrollmentWithSirenOnlyOmitsNullFields(): void
    {
        $http = new SequenceHttpClient([
            new Response(201, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => self::enrollmentPayload(),
            ])),
        ]);

        $this->createEndpoint($http)->createEnrollment(
            email: 'tax@acme.fr',
            nafCode: '62',
            enterpriseSize: 'micro',
            typeOperation: 'services',
            reportingStartDate: '2026-09-01',
            siren: '123456789',
        );

        $payload = json_decode((string) $http->requests[0]->getBody(), true);
        self::assertSame('123456789', $payload['siren']);
        self::assertArrayNotHasKey('siret', $payload);
        self::assertArrayNotHasKey('auto_activate', $payload, 'unset auto_activate defers to the server default (true)');
        self::assertSame(
            ['siren', 'email', 'naf_code', 'enterprise_size', 'type_operation', 'reporting_start_date'],
            array_keys($payload),
        );
    }

    public function testCreateEnrollmentRequiresSiretOrSiren(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        try {
            $endpoint->createEnrollment(
                email: 'tax@acme.fr',
                nafCode: '47',
                enterpriseSize: 'pme',
                typeOperation: 'mixed',
                reportingStartDate: '2026-09-01',
            );
            self::fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('At least one of siret or siren is required.', $exception->getMessage());
        }

        self::assertCount(0, $http->requests, 'No HTTP request should be made when validation fails.');
    }

    public function testCreateEnrollmentRejectsWrongSiretLength(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('siret must be exactly 14 characters.');

        $endpoint->createEnrollment(
            email: 'tax@acme.fr',
            nafCode: '47',
            enterpriseSize: 'pme',
            typeOperation: 'mixed',
            reportingStartDate: '2026-09-01',
            siret: '123456789',
        );
    }

    public function testCreateEnrollmentRejectsWrongSirenLength(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('siren must be exactly 9 characters.');

        $endpoint->createEnrollment(
            email: 'tax@acme.fr',
            nafCode: '47',
            enterpriseSize: 'pme',
            typeOperation: 'mixed',
            reportingStartDate: '2026-09-01',
            siren: '12345678900012',
        );
    }

    public function testCreateEnrollmentRejectsEmailWithoutAtSign(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('email must be a valid e-mail address.');

        $endpoint->createEnrollment(
            email: 'not-an-email',
            nafCode: '47',
            enterpriseSize: 'pme',
            typeOperation: 'mixed',
            reportingStartDate: '2026-09-01',
            siret: '12345678900012',
        );
    }

    public function testCreateEnrollmentRejectsBlankEmail(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('email must be a valid e-mail address.');

        $endpoint->createEnrollment(
            email: '   ',
            nafCode: '47',
            enterpriseSize: 'pme',
            typeOperation: 'mixed',
            reportingStartDate: '2026-09-01',
            siret: '12345678900012',
        );
    }

    public function testCreateEnrollmentRejectsNonTwoDigitNafCode(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nafCode must be exactly 2 digits');

        $endpoint->createEnrollment(
            email: 'tax@acme.fr',
            nafCode: '47.91Z',
            enterpriseSize: 'pme',
            typeOperation: 'mixed',
            reportingStartDate: '2026-09-01',
            siret: '12345678900012',
        );
    }

    public function testCreateEnrollmentRejectsUnknownEnterpriseSize(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('enterpriseSize must be one of "micro", "pme", "eti", "ge", got "huge".');

        $endpoint->createEnrollment(
            email: 'tax@acme.fr',
            nafCode: '47',
            enterpriseSize: 'huge',
            typeOperation: 'mixed',
            reportingStartDate: '2026-09-01',
            siret: '12345678900012',
        );
    }

    public function testCreateEnrollmentRejectsUnknownTypeOperation(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('typeOperation must be one of "services", "goods", "mixed", got "consulting".');

        $endpoint->createEnrollment(
            email: 'tax@acme.fr',
            nafCode: '47',
            enterpriseSize: 'pme',
            typeOperation: 'consulting',
            reportingStartDate: '2026-09-01',
            siret: '12345678900012',
        );
    }

    public function testCreateEnrollmentRejectsMalformedReportingStartDate(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Date string must be in Y-m-d format.');

        $endpoint->createEnrollment(
            email: 'tax@acme.fr',
            nafCode: '47',
            enterpriseSize: 'pme',
            typeOperation: 'mixed',
            reportingStartDate: '01.09.2026',
            siret: '12345678900012',
        );
    }

    public function testCreateEnrollmentSurfacesProviderErrorAs502HttpException(): void
    {
        $http = new SequenceHttpClient([
            new Response(502, ['Content-Type' => 'application/json'], json_encode([
                'success' => false,
                'message' => 'Provider error: B2Brouter account creation failed',
            ])),
        ]);

        try {
            $this->createEndpoint($http)->createEnrollment(
                email: 'tax@acme.fr',
                nafCode: '47',
                enterpriseSize: 'pme',
                typeOperation: 'mixed',
                reportingStartDate: '2026-09-01',
                siret: '12345678900012',
            );
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame(502, $exception->getStatusCode());
            self::assertStringContainsString('Provider error', $exception->getMessage());
            self::assertCount(1, $http->requests);
        }
    }

    public function testCreateEnrollmentThrowsValidationExceptionOn422(): void
    {
        $http = new SequenceHttpClient([
            new Response(422, ['Content-Type' => 'application/json'], '{"message":"The siret has already been taken.","errors":{"siret":["The siret has already been taken."]}}'),
        ]);

        try {
            $this->createEndpoint($http)->createEnrollment(
                email: 'tax@acme.fr',
                nafCode: '47',
                enterpriseSize: 'pme',
                typeOperation: 'mixed',
                reportingStartDate: '2026-09-01',
                siret: '12345678900012',
            );
            $this->fail('Expected ValidationException to be thrown.');
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame('The siret has already been taken.', $exception->getMessage());
            self::assertSame(['siret' => ['The siret has already been taken.']], $exception->getErrors());
            self::assertCount(1, $http->requests);
        }
    }

    public function testGetEnrollmentFetchesByIdAndMapsUnknownStatusToUnknown(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => self::enrollmentPayload(['status' => 'something_new', 'status_label' => 'Something new']),
            ])),
        ]);

        $enrollment = $this->createEndpoint($http)->getEnrollment(12);

        self::assertSame('GET', $http->requests[0]->getMethod());
        self::assertSame('/v1/compliance/enrollments/12', $http->requests[0]->getUri()->getPath());
        self::assertSame(ComplianceEnrollmentStatus::UNKNOWN, $enrollment->status);
        self::assertSame('Something new', $enrollment->statusLabel, 'The server label is still available for unknown statuses');
    }

    public function testGetEnrollmentRejectsNonPositiveId(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('id must be a positive integer.');

        $endpoint->getEnrollment(0);
    }

    public function testListEnrollmentsUnwrapsDoubleEnvelope(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'data' => [self::enrollmentPayload(), self::enrollmentPayload(['id' => 13, 'status' => 'error_activation'])],
                    'meta' => ['current_page' => 2, 'per_page' => 2, 'total' => 5, 'last_page' => 3],
                ],
            ])),
        ]);

        $page = $this->createEndpoint($http)->listEnrollments(page: 2, perPage: 2);

        self::assertCount(2, $page);
        self::assertSame(2, $page->currentPage);
        self::assertSame(2, $page->perPage);
        self::assertSame(5, $page->total);
        self::assertSame(3, $page->lastPage);
        self::assertSame(12, $page->rows[0]->id);
        self::assertSame(ComplianceEnrollmentStatus::ERROR_ACTIVATION, $page->rows[1]->status);
        self::assertTrue($page->rows[1]->status->isError());

        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v1/compliance/enrollments', $request->getUri()->getPath());
        self::assertSame('page=2&per_page=2', $request->getUri()->getQuery());
    }

    public function testListEnrollmentsDefaultsMetaWhenMissing(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => ['data' => [self::enrollmentPayload()]],
            ])),
        ]);

        $page = $this->createEndpoint($http)->listEnrollments();

        self::assertCount(1, $page);
        self::assertSame(1, $page->currentPage);
        self::assertSame(1, $page->perPage, 'Missing per_page falls back to the row count');
        self::assertSame(1, $page->total);
        self::assertSame(1, $page->lastPage);
        self::assertSame('page=1&per_page=25', $http->requests[0]->getUri()->getQuery());
    }

    public function testSireneLookupSendsEncodedQueryAndParsesResult(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'company_name' => 'ACME SARL',
                    'siren' => '123456789',
                    'siret' => '12345678900012',
                    'naf_code' => '47',
                    'naf_full' => '47.91B',
                    'enterprise_size' => 'pme',
                    'address' => '10 Rue de la Paix',
                    'city' => 'Paris',
                    'postalcode' => '75001',
                ],
            ])),
        ]);

        $result = $this->createEndpoint($http)->sireneLookup('123 456 789');

        self::assertSame('ACME SARL', $result->companyName);
        self::assertSame('123456789', $result->siren);
        self::assertSame('12345678900012', $result->siret);
        self::assertSame('47', $result->nafCode);
        self::assertSame('47.91B', $result->nafFull);
        self::assertSame('pme', $result->enterpriseSize);
        self::assertSame('Paris', $result->city);
        self::assertSame('75001', $result->postalcode);

        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v1/compliance/sirene-lookup', $request->getUri()->getPath());
        self::assertSame('q=123%20456%20789', $request->getUri()->getQuery());
    }

    public function testSireneLookupRejectsEmptyQuery(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('q must not be empty.');

        $endpoint->sireneLookup('   ');
    }

    /* ---------- transactions ---------- */

    public function testCreateTransactionPostsWireBodyAndReturnsTransaction(): void
    {
        $http = new SequenceHttpClient([
            new Response(201, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => self::transactionPayload(),
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $tx = $this->createEndpoint($http)->createTransaction(
            enrollmentId: 12,
            transactionType: ComplianceTransactionType::B2C_OUTBOUND,
            invoiceNumber: 'TKT-2026-00001',
            invoiceDate: '2026-06-15',
            subtotal: 100,
            total: 120,
            invoiceLines: [
                new ComplianceInvoiceLine(
                    description: 'Museum ticket',
                    quantity: 2,
                    price: 50,
                    taxes: [new ComplianceLineTax(name: 'TVA', percent: 20, category: 'S')],
                    unit: 9,
                ),
            ],
            currency: 'EUR',
            taxAmount: 20,
            submitNow: false,
        );

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v1/compliance/transactions', $request->getUri()->getPath());

        $payload = json_decode((string) $request->getBody(), true);
        self::assertSame(12, $payload['compliance_enrollment_id']);
        self::assertSame('b2c_outbound', $payload['transaction_type']);
        self::assertSame('TKT-2026-00001', $payload['invoice_number']);
        self::assertSame('2026-06-15', $payload['invoice_date']);
        self::assertSame('EUR', $payload['currency']);
        self::assertSame(100, $payload['subtotal']);
        self::assertSame(20, $payload['tax_amount']);
        self::assertSame(120, $payload['total']);
        self::assertFalse($payload['submit_now']);
        self::assertArrayNotHasKey('due_date', $payload, 'null fields are not sent');
        self::assertArrayNotHasKey('counterparty_name', $payload);
        self::assertSame([
            [
                'description' => 'Museum ticket',
                'quantity' => 2,
                'price' => 50,
                'unit' => 9,
                'taxes_attributes' => [
                    ['name' => 'TVA', 'percent' => 20, 'category' => 'S'],
                ],
            ],
        ], $payload['invoice_lines_attributes'], 'Lines use the invoice_lines_attributes/taxes_attributes wire naming');

        self::assertInstanceOf(ComplianceTransaction::class, $tx);
        self::assertSame(77, $tx->id);
        self::assertSame(12, $tx->complianceEnrollmentId);
        self::assertSame(ComplianceTransactionType::B2C_OUTBOUND, $tx->transactionType);
        self::assertSame(ComplianceTransactionState::PENDING, $tx->state);
        self::assertFalse($tx->state->isTerminal());
        self::assertSame('100.0000', $tx->subtotal, 'Monetary values stay precision-safe strings');
        self::assertSame('120.0000', $tx->total);
        self::assertNull($tx->taxReport);
    }

    public function testCreateTransactionAcceptsIdempotentReplayWith200(): void
    {
        // A retried create matches the invoice's natural key server-side and
        // returns the existing transaction with 200 instead of 201.
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => self::transactionPayload(['state' => 'submitted', 'state_label' => 'Submitted']),
            ])),
        ]);

        $tx = $this->createEndpoint($http)->createTransaction(
            enrollmentId: 12,
            transactionType: 'b2c_outbound',
            invoiceNumber: 'TKT-2026-00001',
            invoiceDate: '2026-06-15',
            subtotal: 100,
            total: 120,
            invoiceLines: [self::sampleLine()],
        );

        self::assertSame(77, $tx->id);
        self::assertSame(ComplianceTransactionState::SUBMITTED, $tx->state);
        self::assertTrue($tx->state->isTerminal());
    }

    public function testCreateTransactionRejectsNonPositiveEnrollmentId(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('enrollmentId must be a positive integer.');

        $endpoint->createTransaction(
            enrollmentId: 0,
            transactionType: 'b2c_outbound',
            invoiceNumber: 'INV-1',
            invoiceDate: '2026-06-15',
            subtotal: 100,
            total: 120,
            invoiceLines: [self::sampleLine()],
        );
    }

    public function testCreateTransactionRejectsUnknownTransactionType(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('transactionType must be one of "b2c_outbound", "b2b_domestic_outbound", "b2b_domestic_inbound", "crossborder_outbound", "crossborder_inbound", got "b2x".');

        $endpoint->createTransaction(
            enrollmentId: 12,
            transactionType: 'b2x',
            invoiceNumber: 'INV-1',
            invoiceDate: '2026-06-15',
            subtotal: 100,
            total: 120,
            invoiceLines: [self::sampleLine()],
        );
    }

    public function testCreateTransactionRejectsEmptyInvoiceNumber(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invoiceNumber must not be empty.');

        $endpoint->createTransaction(
            enrollmentId: 12,
            transactionType: 'b2c_outbound',
            invoiceNumber: '   ',
            invoiceDate: '2026-06-15',
            subtotal: 100,
            total: 120,
            invoiceLines: [self::sampleLine()],
        );
    }

    public function testCreateTransactionRejectsOverlongInvoiceNumber(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invoiceNumber must not exceed 50 characters.');

        $endpoint->createTransaction(
            enrollmentId: 12,
            transactionType: 'b2c_outbound',
            invoiceNumber: str_repeat('X', 51),
            invoiceDate: '2026-06-15',
            subtotal: 100,
            total: 120,
            invoiceLines: [self::sampleLine()],
        );
    }

    public function testCreateTransactionRejectsEmptyInvoiceLines(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invoiceLines requires at least one line.');

        $endpoint->createTransaction(
            enrollmentId: 12,
            transactionType: 'b2c_outbound',
            invoiceNumber: 'INV-1',
            invoiceDate: '2026-06-15',
            subtotal: 100,
            total: 120,
            invoiceLines: [],
        );
    }

    public function testCreateTransactionRejectsMalformedInvoiceDate(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Date string must be in Y-m-d format.');

        $endpoint->createTransaction(
            enrollmentId: 12,
            transactionType: 'b2c_outbound',
            invoiceNumber: 'INV-1',
            invoiceDate: '15/06/2026',
            subtotal: 100,
            total: 120,
            invoiceLines: [self::sampleLine()],
        );
    }

    public function testCreateTransactionRejectsInvalidCurrency(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('currency must be an ISO 4217 code (exactly 3 characters).');

        $endpoint->createTransaction(
            enrollmentId: 12,
            transactionType: 'b2c_outbound',
            invoiceNumber: 'INV-1',
            invoiceDate: '2026-06-15',
            subtotal: 100,
            total: 120,
            invoiceLines: [self::sampleLine()],
            currency: 'EURO',
        );
    }

    public function testCreateTransactionRejectsNonNumericSubtotal(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('subtotal must be numeric.');

        $endpoint->createTransaction(
            enrollmentId: 12,
            transactionType: 'b2c_outbound',
            invoiceNumber: 'INV-1',
            invoiceDate: '2026-06-15',
            subtotal: 'a lot',
            total: 120,
            invoiceLines: [self::sampleLine()],
        );
    }

    public function testInvoiceLineRejectsEmptyDescription(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invoice line description must not be empty.');

        new ComplianceInvoiceLine(
            description: '  ',
            quantity: 1,
            price: 10,
            taxes: [new ComplianceLineTax(name: 'TVA', percent: 20, category: 'S')],
        );
    }

    public function testInvoiceLineRejectsEmptyTaxes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invoice line requires at least one tax.');

        new ComplianceInvoiceLine(description: 'Ticket', quantity: 1, price: 10, taxes: []);
    }

    public function testLineTaxRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tax name must not be empty.');

        new ComplianceLineTax(name: ' ', percent: 20, category: 'S');
    }

    public function testLineTaxSerializesOptionalVatexComment(): void
    {
        $tax = new ComplianceLineTax(name: 'TVA', percent: 0, category: 'E', comment: 'VATEX-EU-132-1F');

        self::assertSame(
            ['name' => 'TVA', 'percent' => 0, 'category' => 'E', 'comment' => 'VATEX-EU-132-1F'],
            $tax->toArray(),
        );
    }

    public function testListTransactionsBuildsFilterQueryFromEnums(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'data' => [],
                    'meta' => ['current_page' => 3, 'per_page' => 50, 'total' => 120, 'last_page' => 3],
                ],
            ])),
        ]);

        $page = $this->createEndpoint($http)->listTransactions(
            dateFrom: '2026-01-01',
            dateTo: new DateTimeImmutable('2026-06-30'),
            state: ComplianceTransactionState::SUBMITTED,
            transactionType: ComplianceTransactionType::CROSSBORDER_OUTBOUND,
            page: 3,
            perPage: 50,
        );

        self::assertCount(0, $page);
        self::assertSame(3, $page->currentPage);
        self::assertSame(120, $page->total);

        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v1/compliance/transactions', $request->getUri()->getPath());
        self::assertSame(
            'date_from=2026-01-01&date_to=2026-06-30&state=submitted&transaction_type=crossborder_outbound&page=3&per_page=50',
            $request->getUri()->getQuery(),
        );
    }

    public function testListTransactionsRejectsMalformedDateFilter(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Date string must be in Y-m-d format.');

        $endpoint->listTransactions(dateFrom: '2026-13-99x');
    }

    public function testListTransactionsParsesRowsWithNestedTaxReportAndUnknownState(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'data' => [
                        self::transactionPayload([
                            'state' => 'half_submitted',
                            'state_label' => 'Half submitted',
                            'tax_report' => self::taxReportPayload(),
                        ]),
                    ],
                    'meta' => ['current_page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1],
                ],
            ])),
        ]);

        $page = $this->createEndpoint($http)->listTransactions();

        self::assertCount(1, $page);
        $tx = $page->rows[0];
        self::assertSame(ComplianceTransactionState::UNKNOWN, $tx->state, 'Unknown states degrade gracefully');
        self::assertSame('Half submitted', $tx->stateLabel);
        self::assertNotNull($tx->taxReport);
        self::assertSame(5, $tx->taxReport->id);
        self::assertSame(ComplianceTaxReportState::REGISTERED, $tx->taxReport->state);
        self::assertTrue($tx->taxReport->isTerminal);
        self::assertSame('page=1&per_page=25', $http->requests[0]->getUri()->getQuery(), 'Filterless calls still send the pagination defaults');
    }

    public function testGetTransactionFetchesById(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => self::transactionPayload(),
            ])),
        ]);

        $tx = $this->createEndpoint($http)->getTransaction(77);

        self::assertSame(77, $tx->id);
        self::assertSame('GET', $http->requests[0]->getMethod());
        self::assertSame('/v1/compliance/transactions/77', $http->requests[0]->getUri()->getPath());
    }

    public function testGetTransactionRejectsNonPositiveId(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('id must be a positive integer.');

        $endpoint->getTransaction(-1);
    }

    public function testUpdateTransactionSendsOnlyProvidedFieldsViaPut(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => self::transactionPayload(['invoice_number' => 'TKT-2026-00002']),
            ])),
        ]);

        $tx = $this->createEndpoint($http)->updateTransaction(
            id: 77,
            invoiceNumber: 'TKT-2026-00002',
            total: '130.00',
        );

        self::assertSame('TKT-2026-00002', $tx->invoiceNumber);

        $request = $http->requests[0];
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/v1/compliance/transactions/77', $request->getUri()->getPath());

        $payload = json_decode((string) $request->getBody(), true);
        self::assertSame(['invoice_number' => 'TKT-2026-00002', 'total' => '130.00'], $payload, 'Only the provided fields are sent');
    }

    public function testUpdateTransactionRequiresAtLeastOneField(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('updateTransaction requires at least one field to update.');

        $endpoint->updateTransaction(77);
    }

    public function testDeleteTransactionSendsDeleteAndAccepts204(): void
    {
        $http = new SequenceHttpClient([
            new Response(204, [], null),
        ]);

        $this->createEndpoint($http)->deleteTransaction(77);

        self::assertCount(1, $http->requests);
        self::assertSame('DELETE', $http->requests[0]->getMethod());
        self::assertSame('/v1/compliance/transactions/77', $http->requests[0]->getUri()->getPath());
    }

    public function testDeleteTransactionThrowsValidationExceptionWhenNotPending(): void
    {
        $http = new SequenceHttpClient([
            new Response(422, ['Content-Type' => 'application/json'], '{"error":"Only pending transactions can be deleted"}'),
        ]);

        try {
            $this->createEndpoint($http)->deleteTransaction(77);
            $this->fail('Expected ValidationException to be thrown.');
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame('Only pending transactions can be deleted', $exception->getMessage());
            self::assertCount(1, $http->requests);
        }
    }

    public function testDeleteTransactionThrowsHttpExceptionOnNotFound(): void
    {
        $http = new SequenceHttpClient([
            new Response(404, ['Content-Type' => 'application/json'], '{"error":"Transaction not found"}'),
        ]);

        try {
            $this->createEndpoint($http)->deleteTransaction(77);
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }
    }

    public function testDeleteTransactionRejectsSuccessFalseEnvelopeOn200(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], '{"success":false,"message":"Deletion failed"}'),
        ]);

        try {
            $this->createEndpoint($http)->deleteTransaction(77);
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame('Deletion failed', $exception->getMessage());
            self::assertSame(200, $exception->getStatusCode());
        }
    }

    public function testSubmitTransactionPostsToSubmitPath(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => self::transactionPayload([
                    'state' => 'submitted',
                    'state_label' => 'Submitted',
                    'provider_invoice_id' => 'INV-83428-1',
                    'tax_report' => self::taxReportPayload(['state' => 'new', 'state_label' => 'New', 'is_terminal' => false]),
                ]),
            ])),
        ]);

        $tx = $this->createEndpoint($http)->submitTransaction(77);

        self::assertSame(ComplianceTransactionState::SUBMITTED, $tx->state);
        self::assertSame('INV-83428-1', $tx->providerInvoiceId);
        self::assertNotNull($tx->taxReport);
        self::assertSame(ComplianceTaxReportState::NEW, $tx->taxReport->state);
        self::assertFalse($tx->taxReport->isTerminal);

        self::assertSame('POST', $http->requests[0]->getMethod());
        self::assertSame('/v1/compliance/transactions/77/submit', $http->requests[0]->getUri()->getPath());
    }

    public function testSubmitTransactionSurfacesProviderErrorAs502(): void
    {
        $http = new SequenceHttpClient([
            new Response(502, ['Content-Type' => 'application/json'], '{"error":"Provider error: DGFiP unavailable"}'),
        ]);

        try {
            $this->createEndpoint($http)->submitTransaction(77);
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame(502, $exception->getStatusCode());
            self::assertCount(1, $http->requests);
        }
    }

    public function testImportTransactionsSendsMultipartBodyAndParsesResult(): void
    {
        $csv = "invoice_number;invoice_date;transaction_type;subtotal;total;line_description;line_quantity;line_price;tax_name;tax_percent;tax_category\n"
            . "INV-1;2026-06-15;b2c_outbound;100;120;Ticket;2;50;TVA;20;S\n";

        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'created' => 2,
                    'skipped_duplicates' => 1,
                    'errors' => ["Row 4 (INV-9): invalid transaction_type 'b2x'"],
                ],
            ])),
        ]);

        $result = $this->createEndpoint($http)->importTransactions(12, $csv, 'june.csv');

        self::assertSame(2, $result->created);
        self::assertSame(1, $result->skippedDuplicates);
        self::assertSame(["Row 4 (INV-9): invalid transaction_type 'b2x'"], $result->errors);

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v1/compliance/transactions/import', $request->getUri()->getPath());

        $contentType = $request->getHeaderLine('Content-Type');
        self::assertMatchesRegularExpression('/^multipart\/form-data; boundary=[0-9a-f]{32}$/', $contentType);
        $boundary = substr($contentType, strlen('multipart/form-data; boundary='));

        $body = (string) $request->getBody();
        self::assertStringContainsString('--' . $boundary . "\r\n", $body);
        self::assertStringContainsString(
            'Content-Disposition: form-data; name="compliance_enrollment_id"' . "\r\n\r\n" . '12' . "\r\n",
            $body,
        );
        self::assertStringContainsString('Content-Disposition: form-data; name="file"; filename="june.csv"' . "\r\n", $body);
        self::assertStringContainsString('Content-Type: text/csv' . "\r\n\r\n", $body);
        self::assertStringContainsString($csv, $body, 'The raw CSV payload is embedded verbatim');
        self::assertStringContainsString('--' . $boundary . '--' . "\r\n", $body, 'The body ends with the closing boundary');
    }

    public function testImportTransactionsRejectsEmptyCsvContent(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('csvContent must not be empty.');

        $endpoint->importTransactions(12, "  \n ");
    }

    public function testImportTransactionsRejectsNonPositiveEnrollmentId(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('enrollmentId must be a positive integer.');

        $endpoint->importTransactions(0, 'invoice_number;...');
    }

    public function testImportTransactionsSanitizesFilename(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => ['created' => 0, 'skipped_duplicates' => 0, 'errors' => []],
            ])),
        ]);

        $this->createEndpoint($http)->importTransactions(12, 'invoice_number;x', "ju\"ne\r\n.csv");

        $body = (string) $http->requests[0]->getBody();
        self::assertStringContainsString('filename="june.csv"', $body, 'Quotes and CR/LF are stripped from the filename');
    }

    public function testImportTransactionsThrowsValidationExceptionOnMissingColumns(): void
    {
        $http = new SequenceHttpClient([
            new Response(422, ['Content-Type' => 'application/json'], '{"error":"Missing CSV columns: tax_category"}'),
        ]);

        try {
            $this->createEndpoint($http)->importTransactions(12, "invoice_number;invoice_date\nINV-1;2026-06-15\n");
            $this->fail('Expected ValidationException to be thrown.');
        } catch (ValidationException $exception) {
            self::assertSame('Missing CSV columns: tax_category', $exception->getMessage());
            self::assertSame([], $exception->getErrors());
        }
    }

    public function testImportTransactionsThrowsHttpExceptionOnServerError(): void
    {
        $http = new SequenceHttpClient([
            new Response(500, ['Content-Type' => 'application/json'], '{"error":"Internal server error"}'),
        ]);

        try {
            $this->createEndpoint($http)->importTransactions(12, "a;b\n1;2\n");
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame(500, $exception->getStatusCode());
        }
    }

    public function testImportTransactionsRejectsSuccessFalseEnvelope(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], '{"success":false,"message":"Import failed"}'),
        ]);

        try {
            $this->createEndpoint($http)->importTransactions(12, "a;b\n1;2\n");
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame('Import failed', $exception->getMessage());
        }
    }

    /* ---------- tax reports ---------- */

    public function testListTaxReportsBuildsQueryAndParsesPage(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'data' => [self::taxReportPayload()],
                    'meta' => ['current_page' => 1, 'per_page' => 10, 'total' => 1, 'last_page' => 1],
                ],
            ])),
        ]);

        $page = $this->createEndpoint($http)->listTaxReports(page: 1, perPage: 10, state: ComplianceTaxReportState::REGISTERED);

        self::assertCount(1, $page);
        self::assertSame(10, $page->perPage);
        self::assertSame(ComplianceTaxReportState::REGISTERED, $page->rows[0]->state);
        self::assertTrue($page->rows[0]->state->isSuccess());

        $request = $http->requests[0];
        self::assertSame('/v1/compliance/tax-reports', $request->getUri()->getPath());
        self::assertSame('page=1&per_page=10&state=registered', $request->getUri()->getQuery());
    }

    public function testListTaxReportsPassesUnknownStateFilterThrough(): void
    {
        // The backend accepts any state string for tax reports, so the SDK
        // forwards unknown values instead of whitelisting them — states added
        // in future API versions stay filterable without an SDK update.
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => ['data' => [], 'meta' => ['current_page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1]],
            ])),
        ]);

        $page = $this->createEndpoint($http)->listTaxReports(state: 'archived');

        self::assertCount(0, $page);
        self::assertSame('page=1&per_page=25&state=archived', $http->requests[0]->getUri()->getQuery());
    }

    public function testGetTaxReportParsesReportWithEnumAndAmounts(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => self::taxReportPayload([
                    'state' => 'refused',
                    'state_label' => 'Refused',
                    'refusal_reason' => 'CDV 301: invalid ledger totals',
                    'registered_at' => null,
                    'refused_at' => '2026-06-17T04:00:00+00:00',
                ]),
            ])),
        ]);

        $report = $this->createEndpoint($http)->getTaxReport(5);

        self::assertSame(5, $report->id);
        self::assertSame(77, $report->complianceTransactionId);
        self::assertSame(ComplianceTaxReportState::REFUSED, $report->state);
        self::assertTrue($report->isTerminal);
        self::assertTrue($report->state->isFailure());
        self::assertSame('100.0000', $report->baseAmount, 'Amounts stay precision-safe strings');
        self::assertSame('20.0000', $report->taxAmount);
        self::assertSame('120.0000', $report->totalAmount);
        self::assertSame('CDV 301: invalid ledger totals', $report->refusalReason);
        self::assertSame('2026-06-17T04:00:00+00:00', $report->refusedAt);
        self::assertNull($report->registeredAt);
        self::assertSame('/v1/compliance/tax-reports/5', $http->requests[0]->getUri()->getPath());
    }

    public function testGetTaxReportRejectsNonPositiveId(): void
    {
        $endpoint = $this->createEndpoint(new SequenceHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('id must be a positive integer.');

        $endpoint->getTaxReport(0);
    }

    /* ---------- vat rates ---------- */

    public function testGetVatRatesParsesCatalogue(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'country' => 'FR',
                    'currency' => 'EUR',
                    'rates' => [
                        ['percent' => 20, 'category' => 'S', 'label_key' => 'fr_standard', 'tax_name' => 'TVA'],
                        ['percent' => 5.5, 'category' => 'AA', 'label_key' => 'fr_reduced_55', 'tax_name' => 'TVA'],
                        ['percent' => 0, 'category' => 'E', 'label_key' => 'exempt', 'tax_name' => 'TVA', 'requires_vatex' => true],
                    ],
                ],
            ])),
        ]);

        $rates = $this->createEndpoint($http)->getVatRates('FR');

        self::assertSame('FR', $rates->country);
        self::assertSame('EUR', $rates->currency);
        self::assertCount(3, $rates->rates);
        self::assertSame(20.0, $rates->rates[0]->percent);
        self::assertSame('S', $rates->rates[0]->category);
        self::assertSame('fr_standard', $rates->rates[0]->labelKey);
        self::assertSame('TVA', $rates->rates[0]->taxName);
        self::assertFalse($rates->rates[0]->requiresVatex, 'requires_vatex defaults to false when absent');
        self::assertSame(5.5, $rates->rates[1]->percent);
        self::assertTrue($rates->rates[2]->requiresVatex);

        $request = $http->requests[0];
        self::assertSame('/v1/compliance/vat-rates', $request->getUri()->getPath());
        self::assertSame('country=FR', $request->getUri()->getQuery());
    }

    public function testGetVatRatesWithoutCountrySendsNoQuery(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => ['country' => 'FR', 'currency' => 'EUR', 'rates' => []],
            ])),
        ]);

        $rates = $this->createEndpoint($http)->getVatRates();

        self::assertSame([], $rates->rates);
        self::assertSame('', $http->requests[0]->getUri()->getQuery());
    }

    /* ---------- access request ---------- */

    public function testRequestEReportingAccessPostsProvidedFieldsOnly(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], '{"success":true}'),
        ]);

        $this->createEndpoint($http)->requestEReportingAccess(
            company: 'Acme SARL',
            message: 'Please activate e-reporting for us.',
            language: 'fr',
        );

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v1/compliance/e-reporting-request', $request->getUri()->getPath());

        $payload = json_decode((string) $request->getBody(), true);
        self::assertSame(
            ['company' => 'Acme SARL', 'message' => 'Please activate e-reporting for us.', 'language' => 'fr'],
            $payload,
            'Identity fields default server-side to the authenticated user',
        );
    }

    public function testRequestEReportingAccessFailureEnvelopeThrowsHttpException(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => false,
                'message' => 'Cannot save e-reporting request! Error: mail gateway down',
            ])),
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Cannot save e-reporting request!');

        $this->createEndpoint($http)->requestEReportingAccess(message: 'Activate please');
    }

    /* ---------- revenue statistics ---------- */

    public function testGetRevenueStatisticsSendsGetWithQueryAndHydratesDto(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data'    => self::statisticsPayload(),
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $endpoint = $this->createEndpoint($http);

        $result = $endpoint->getRevenueStatistics(
            dateFrom: new \DateTimeImmutable('2026-01-01'),
            dateTo: '2026-06-30',
            interval: 'month',
            transactionType: 'b2c_outbound',
            state: 'submitted',
        );

        // Request assertions
        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v1/compliance/revenue-statistics', $request->getUri()->getPath());

        parse_str($request->getUri()->getQuery(), $query);
        self::assertSame('2026-01-01', $query['date_from']);
        self::assertSame('2026-06-30', $query['date_to']);
        self::assertSame('month', $query['interval']);
        self::assertSame('b2c_outbound', $query['transaction_type']);
        self::assertSame('submitted', $query['state']);

        // DTO assertions
        self::assertInstanceOf(RevenueStatistics::class, $result);
        self::assertSame('EUR', $result->primaryCurrency);
        self::assertFalse($result->isMultiCurrency);
        self::assertSame('2026-01-01', $result->dateFrom);
        self::assertSame('month', $result->interval);

        self::assertInstanceOf(RevenueTotals::class, $result->totals);
        self::assertSame(3, $result->totals->count);
        self::assertSame('350.0000', $result->totals->subtotal);
        self::assertSame('410.0000', $result->totals->total);

        self::assertCount(2, $result->timeSeries);
        self::assertInstanceOf(RevenueTimeBucket::class, $result->timeSeries[0]);
        self::assertSame('2026-01', $result->timeSeries[0]->bucket);
        self::assertSame('360.0000', $result->timeSeries[0]->total);

        self::assertSame('b2c_outbound', $result->byType[0]['type']);
        self::assertSame('de', $result->byCountry[0]['country']);
        self::assertSame('submitted', $result->byState[0]['state']);
        self::assertSame('EUR', $result->byCurrency[0]['currency']);
    }

    public function testGetRevenueStatisticsOmitsUnsetQueryParameters(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data'    => self::statisticsPayload(),
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $this->createEndpoint($http)->getRevenueStatistics();

        parse_str($http->requests[0]->getUri()->getQuery(), $query);
        self::assertSame([], $query);
    }

    public function testGetRevenueStatisticsThrowsValidationExceptionOn422(): void
    {
        $http = new SequenceHttpClient([
            new Response(422, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'The date to field must be a date after or equal to date from.',
            ], JSON_UNESCAPED_SLASHES)),
        ]);

        $this->expectException(ValidationException::class);

        $this->createEndpoint($http)->getRevenueStatistics(dateFrom: '2026-06-30', dateTo: '2026-01-01');
    }

    public function testGetRevenueStatisticsRejectsInvalidIntervalBeforeRequest(): void
    {
        $http = new SequenceHttpClient([]);
        $endpoint = $this->createEndpoint($http);

        try {
            $endpoint->getRevenueStatistics(interval: 'year');
            self::fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('interval must be one of "day", "week", "month", got "year".', $exception->getMessage());
        }

        self::assertCount(0, $http->requests, 'No HTTP request should be made when validation fails.');
    }

    public function testGetRevenueStatisticsAcceptsEnumFilters(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => self::statisticsPayload(),
            ])),
        ]);

        $this->createEndpoint($http)->getRevenueStatistics(
            transactionType: ComplianceTransactionType::B2C_OUTBOUND,
            state: ComplianceTransactionState::SUBMITTED,
        );

        self::assertSame('transaction_type=b2c_outbound&state=submitted', $http->requests[0]->getUri()->getQuery());
    }

    public function testGetRevenueStatisticsAcceptsStringOneSuccessEnvelope(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => '1',
                'data' => self::statisticsPayload(),
            ])),
        ]);

        $result = $this->createEndpoint($http)->getRevenueStatistics();

        self::assertSame(3, $result->totals->count, "A success='1' string envelope is accepted like the other endpoints");
    }

    /* ---------- shared error paths ---------- */

    public function testFailedSuccessEnvelopeThrowsHttpException(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => false,
                'message' => 'E-Reporting access is not active.',
            ])),
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('E-Reporting access is not active.');

        $this->createEndpoint($http)->listEnrollments();
    }

    public function testForbiddenResponseThrowsHttpExceptionWithStatusCode(): void
    {
        $http = new SequenceHttpClient([
            new Response(403, ['Content-Type' => 'application/json'], '{"success":false,"message":"E-Reporting access is not active."}'),
        ]);

        try {
            $this->createEndpoint($http)->listTransactions();
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
            self::assertStringContainsString('E-Reporting access is not active.', (string) $exception->getResponseBody());
        }
    }

    public function testServerErrorThrowsHttpException(): void
    {
        $http = new SequenceHttpClient([
            new Response(500, ['Content-Type' => 'text/plain'], 'server error'),
        ]);

        try {
            $this->createEndpoint($http)->getTransaction(77);
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $exception) {
            self::assertSame(500, $exception->getStatusCode());
            self::assertSame('server error', $exception->getMessage());
            self::assertCount(1, $http->requests);
        }
    }

    public function testInvalidJsonBodyThrowsHttpException(): void
    {
        $http = new SequenceHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], 'not-json'),
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Failed to decode JSON response from Taxora API.');

        $this->createEndpoint($http)->getEnrollment(12);
    }

    public function testUnauthorizedResponseTriggersRefreshAndRetry(): void
    {
        $http = new SequenceHttpClient([
            new Response(401, ['Content-Type' => 'application/json'], '{"message":"expired"}'),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => [
                    'data' => [self::enrollmentPayload()],
                    'meta' => ['current_page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1],
                ],
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

        $page = $endpoint->listEnrollments();

        self::assertCount(1, $page);
        self::assertSame(1, $refreshes);
        self::assertCount(2, $http->requests);
        self::assertSame(['Bearer stale-token'], $http->requests[0]->getHeader('Authorization'));
        self::assertSame(['Bearer refreshed-token'], $http->requests[1]->getHeader('Authorization'));
    }

    /* ---------- fixtures ---------- */

    private static function sampleLine(): ComplianceInvoiceLine
    {
        return new ComplianceInvoiceLine(
            description: 'Ticket',
            quantity: 1,
            price: 100,
            taxes: [new ComplianceLineTax(name: 'TVA', percent: 20, category: 'S')],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function enrollmentPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 12,
            'company_id' => 42,
            'country' => 'fr',
            'regime' => 'dgfip_flux10',
            'provider' => 'b2brouter',
            'status' => 'regime_activated',
            'status_label' => 'Regime activated',
            'status_error' => null,
            'provider_account_id' => '83428',
            'tax_id' => 'FR32123456789',
            'company_register_id' => '12345678900012',
            'company_register_scheme' => '0009',
            'regime_config' => ['naf_code' => '47', 'enterprise_size' => 'pme', 'type_operation' => 'mixed'],
            'reporting_start_date' => '2026-09-01',
            'notification_email' => 'tax@acme.fr',
            'auto_send' => true,
            'created_at' => '2026-07-01T10:00:00+00:00',
            'updated_at' => '2026-07-01T10:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function transactionPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 77,
            'company_id' => 42,
            'compliance_enrollment_id' => 12,
            'country' => 'fr',
            'regime' => 'dgfip_flux10',
            'transaction_type' => 'b2c_outbound',
            'transaction_type_label' => 'B2C outbound',
            'state' => 'pending',
            'state_label' => 'Pending',
            'invoice_number' => 'TKT-2026-00001',
            'invoice_date' => '2026-06-15',
            'due_date' => null,
            'currency' => 'EUR',
            'subtotal' => '100.0000',
            'tax_amount' => '20.0000',
            'total' => '120.0000',
            'counterparty_name' => null,
            'counterparty_country' => null,
            'counterparty_vat_number' => null,
            'is_paid' => false,
            'paid_at' => null,
            'provider_invoice_id' => null,
            'submission_error' => null,
            'reported_at' => null,
            'tax_report' => null,
            'created_at' => '2026-06-15T10:00:00+00:00',
            'updated_at' => '2026-06-15T10:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function taxReportPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 5,
            'company_id' => 42,
            'compliance_transaction_id' => 77,
            'ledger_id' => 3,
            'country' => 'fr',
            'regime' => 'dgfip_flux10',
            'state' => 'registered',
            'state_label' => 'Registered',
            'is_terminal' => true,
            'provider_tax_report_id' => 'TR-2026-06-16-1',
            'provider_ledger_id' => 'L-2026-06-16',
            'base_amount' => '100.0000',
            'tax_amount' => '20.0000',
            'total_amount' => '120.0000',
            'refusal_reason' => null,
            'registered_at' => '2026-06-16T04:00:00+00:00',
            'refused_at' => null,
            'created_at' => '2026-06-16T02:00:00+00:00',
            'updated_at' => '2026-06-16T04:00:00+00:00',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function statisticsPayload(): array
    {
        return [
            'range'             => ['date_from' => '2026-01-01', 'date_to' => '2026-06-30', 'interval' => 'month'],
            'primary_currency'  => 'EUR',
            'is_multi_currency' => false,
            'totals'            => ['count' => 3, 'subtotal' => '350.0000', 'tax_amount' => '60.0000', 'total' => '410.0000'],
            'time_series'       => [
                ['bucket' => '2026-01', 'count' => 2, 'subtotal' => '300.0000', 'tax_amount' => '60.0000', 'total' => '360.0000'],
                ['bucket' => '2026-02', 'count' => 1, 'subtotal' => '50.0000', 'tax_amount' => '0.0000', 'total' => '50.0000'],
            ],
            'by_type'     => [['type' => 'b2c_outbound', 'type_label' => 'B2C outbound', 'count' => 2, 'total' => '360.0000']],
            'by_country'  => [['country' => 'de', 'count' => 2, 'total' => '360.0000']],
            'by_state'    => [['state' => 'submitted', 'state_label' => 'Submitted', 'count' => 2, 'total' => '360.0000']],
            'by_currency' => [['currency' => 'EUR', 'count' => 3, 'subtotal' => '350.0000', 'tax_amount' => '60.0000', 'total' => '410.0000']],
        ];
    }

    private function createEndpoint(
        SequenceHttpClient $http,
        ?InMemoryTokenStorage $tokenStorage = null,
        ?callable $refreshCallback = null
    ): EReportingEndpoint {
        $tokenStorage ??= new InMemoryTokenStorage();

        return new EReportingEndpoint(
            http: $http,
            req: $this->requestFactory,
            stream: $this->streamFactory,
            apiKey: new ApiKeyMiddleware('test-key'),
            auth: new AuthMiddleware($tokenStorage),
            tokens: $tokenStorage,
            refreshCallback: $refreshCallback ?? static function (): void {
            },
            baseUrl: 'https://sandbox.taxora.io',
            apiVersion: ApiVersion::V1
        );
    }
}
