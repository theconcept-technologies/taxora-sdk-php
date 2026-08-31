<?php

declare(strict_types=1);

namespace Taxora\Sdk\Endpoints;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Enums\ComplianceTaxReportState;
use Taxora\Sdk\Enums\ComplianceTransactionState;
use Taxora\Sdk\Enums\ComplianceTransactionType;
use Taxora\Sdk\Exceptions\AuthenticationException;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Exceptions\ValidationException;
use Taxora\Sdk\Http\ApiKeyMiddleware;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\RetryPolicy;
use Taxora\Sdk\Http\TokenStorageInterface;
use Taxora\Sdk\ValueObjects\ComplianceEnrollment;
use Taxora\Sdk\ValueObjects\ComplianceEnrollmentPage;
use Taxora\Sdk\ValueObjects\ComplianceImportResult;
use Taxora\Sdk\ValueObjects\ComplianceInvoiceLine;
use Taxora\Sdk\ValueObjects\ComplianceTaxReport;
use Taxora\Sdk\ValueObjects\ComplianceTaxReportPage;
use Taxora\Sdk\ValueObjects\ComplianceTransaction;
use Taxora\Sdk\ValueObjects\ComplianceTransactionPage;
use Taxora\Sdk\ValueObjects\ComplianceVatRates;
use Taxora\Sdk\ValueObjects\RevenueStatistics;
use Taxora\Sdk\ValueObjects\SireneLookupResult;
use Throwable;

/**
 * E-Reporting / Compliance (DGFiP Flux 10) endpoints.
 *
 * Typical flow:
 *
 *   $prefill    = $client->eReporting()->sireneLookup('12345678900012');
 *   $enrollment = $client->eReporting()->createEnrollment(email: ..., nafCode: ..., ...);
 *   $tx         = $client->eReporting()->createTransaction(enrollmentId: $enrollment->id, ...);
 *   $reports    = $client->eReporting()->listTaxReports();
 *
 * All endpoints except {@see requestEReportingAccess()} require an active
 * E-Reporting feature grant / subscription (403 otherwise).
 */
final class EReportingEndpoint
{
    use Concerns\RetriesTransientErrors;

    private const INTERVALS = ['day', 'week', 'month'];
    private const ENTERPRISE_SIZES = ['micro', 'pme', 'eti', 'ge'];
    private const TYPE_OPERATIONS = ['services', 'goods', 'mixed'];
    private const TRANSACTION_TYPES = [
        'b2c_outbound',
        'b2b_domestic_outbound',
        'b2b_domestic_inbound',
        'crossborder_outbound',
        'crossborder_inbound',
    ];
    private const TRANSACTION_STATES = ['pending', 'sending', 'submitted', 'error'];

    private readonly Closure $refreshCallback;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $req,
        private readonly StreamFactoryInterface $stream,
        private readonly ApiKeyMiddleware $apiKey,
        private readonly AuthMiddleware $auth,
        private readonly TokenStorageInterface $tokens,
        callable $refreshCallback,
        private readonly string $baseUrl,
        private readonly ApiVersion $apiVersion = ApiVersion::V1,
        ?RetryPolicy $retryPolicy = null,
    ) {
        $this->refreshCallback = Closure::fromCallable($refreshCallback);
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy();
    }

    // ---------------------------------------------------------------------
    // Enrollments
    // ---------------------------------------------------------------------

    /**
     * Create a compliance enrollment and provision the provider account. By
     * default the DGFiP tax-report setting is activated immediately; pass
     * $autoActivate = false to only create the account.
     *
     * At least one of $siret (14 characters) / $siren (9 characters) is required.
     * Provider failures surface as an {@see HttpException} with status code 502 —
     * the enrollment is persisted server-side in an error state and shows up in
     * listEnrollments() for retries.
     */
    public function createEnrollment(
        string $email,
        string $nafCode,
        string $enterpriseSize,
        string $typeOperation,
        DateTimeInterface|string $reportingStartDate,
        ?string $siret = null,
        ?string $siren = null,
        ?string $vatNumber = null,
        ?string $companyName = null,
        ?string $address = null,
        ?string $city = null,
        ?string $postalcode = null,
        ?string $province = null,
        ?string $country = null,
        ?string $regime = null,
        ?bool $autoActivate = null,
    ): ComplianceEnrollment {
        if (trim($email) === '' || !str_contains($email, '@')) {
            throw new InvalidArgumentException('email must be a valid e-mail address.');
        }
        if (preg_match('/^\d{2}$/', $nafCode) !== 1) {
            throw new InvalidArgumentException('nafCode must be exactly 2 digits (NAF division), e.g. "47".');
        }
        $enterpriseSize = $this->enumValue($enterpriseSize, self::ENTERPRISE_SIZES, 'enterpriseSize');
        $typeOperation = $this->enumValue($typeOperation, self::TYPE_OPERATIONS, 'typeOperation');
        if ($siret === null && $siren === null) {
            throw new InvalidArgumentException('At least one of siret or siren is required.');
        }
        if ($siret !== null && strlen($siret) !== 14) {
            throw new InvalidArgumentException('siret must be exactly 14 characters.');
        }
        if ($siren !== null && strlen($siren) !== 9) {
            throw new InvalidArgumentException('siren must be exactly 9 characters.');
        }
        if ($country !== null && strlen($country) !== 2) {
            throw new InvalidArgumentException('country must be an ISO 3166-1 alpha-2 code (exactly 2 characters).');
        }

        $body = array_filter([
            'country' => $country,
            'regime' => $regime,
            'siret' => $siret,
            'siren' => $siren,
            'vat_number' => $vatNumber,
            'company_name' => $companyName,
            'address' => $address,
            'city' => $city,
            'postalcode' => $postalcode,
            'province' => $province,
            'email' => $email,
            'naf_code' => $nafCode,
            'enterprise_size' => $enterpriseSize,
            'type_operation' => $typeOperation,
            'reporting_start_date' => $this->formatDate($reportingStartDate),
            'auto_activate' => $autoActivate,
        ], static fn ($v) => $v !== null);

        $payload = $this->jsonPost($this->uri('/compliance/enrollments'), $body);

        return ComplianceEnrollment::fromArray($this->extractData($payload));
    }

    /**
     * Paginated list of the company's enrollments (newest first). The server
     * caps $perPage at 100.
     */
    public function listEnrollments(int $page = 1, int $perPage = 25): ComplianceEnrollmentPage
    {
        $uri = $this->uri('/compliance/enrollments' . $this->buildQuery([
            'page' => (string) $page,
            'per_page' => (string) $perPage,
        ]));

        return ComplianceEnrollmentPage::fromArray($this->extractData($this->jsonGet($uri)));
    }

    /** Fetch a single enrollment by its id. */
    public function getEnrollment(int $id): ComplianceEnrollment
    {
        $this->assertPositiveId($id, 'id');

        $payload = $this->jsonGet($this->uri('/compliance/enrollments/' . $id));

        return ComplianceEnrollment::fromArray($this->extractData($payload));
    }

    /**
     * Look up company data in the French SIRENE registry by SIRET, SIREN or
     * French VAT number — handy to prefill createEnrollment().
     *
     * Rate limit: max 3 requests per minute per user (protects the external
     * French government API); exceeding it yields an HttpException with 429.
     */
    public function sireneLookup(string $q): SireneLookupResult
    {
        if (trim($q) === '') {
            throw new InvalidArgumentException('q must not be empty.');
        }

        $uri = $this->uri('/compliance/sirene-lookup' . $this->buildQuery(['q' => $q]));

        return SireneLookupResult::fromArray($this->extractData($this->jsonGet($uri)));
    }

    // ---------------------------------------------------------------------
    // Transactions
    // ---------------------------------------------------------------------

    /**
     * Record and (optionally) submit a Flux 10 transaction.
     *
     * Immediate per-invoice submission requires the e-invoicing feature on the
     * account; in pure e-reporting mode the transaction is only recorded and
     * reported via the aggregated daily ledgers.
     *
     * Creation is idempotent on the invoice's natural key (enrollment + type +
     * invoice number + invoice date + counterparty VAT): a retried create
     * returns the already-recorded transaction with HTTP 200 instead of 201 —
     * both are treated as success. Provider failures surface as an
     * {@see HttpException} with status code 502.
     *
     * @param list<ComplianceInvoiceLine> $invoiceLines at least one line, each with at least one tax
     */
    public function createTransaction(
        int $enrollmentId,
        ComplianceTransactionType|string $transactionType,
        string $invoiceNumber,
        DateTimeInterface|string $invoiceDate,
        float|int|string $subtotal,
        float|int|string $total,
        array $invoiceLines,
        DateTimeInterface|string|null $dueDate = null,
        ?string $currency = null,
        float|int|string|null $taxAmount = null,
        ?string $counterpartyName = null,
        ?string $counterpartyCountry = null,
        ?string $counterpartyVatNumber = null,
        ?string $providerContactId = null,
        ?int $paymentMethod = null,
        ?string $remittanceInformation = null,
        ?string $paymentMethodText = null,
        ?string $paymentTerms = null,
        ?bool $submitNow = null,
    ): ComplianceTransaction {
        $this->assertPositiveId($enrollmentId, 'enrollmentId');
        $type = $this->enumValue($transactionType, self::TRANSACTION_TYPES, 'transactionType');
        $this->assertInvoiceNumber($invoiceNumber);
        $this->assertAmount($subtotal, 'subtotal');
        $this->assertAmount($total, 'total');
        if ($taxAmount !== null) {
            $this->assertAmount($taxAmount, 'taxAmount');
        }
        if ($currency !== null && strlen($currency) !== 3) {
            throw new InvalidArgumentException('currency must be an ISO 4217 code (exactly 3 characters).');
        }
        if ($counterpartyCountry !== null && strlen($counterpartyCountry) !== 2) {
            throw new InvalidArgumentException('counterpartyCountry must be an ISO 3166-1 alpha-2 code (exactly 2 characters).');
        }

        $body = array_filter([
            'compliance_enrollment_id' => $enrollmentId,
            'transaction_type' => $type,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $this->formatDate($invoiceDate),
            'due_date' => $dueDate !== null ? $this->formatDate($dueDate) : null,
            'currency' => $currency,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'counterparty_name' => $counterpartyName,
            'counterparty_country' => $counterpartyCountry,
            'counterparty_vat_number' => $counterpartyVatNumber,
            'provider_contact_id' => $providerContactId,
            'invoice_lines_attributes' => $this->invoiceLinesToArray($invoiceLines),
            'payment_method' => $paymentMethod,
            'remittance_information' => $remittanceInformation,
            'payment_method_text' => $paymentMethodText,
            'payment_terms' => $paymentTerms,
            'submit_now' => $submitNow,
        ], static fn ($v) => $v !== null);

        $payload = $this->jsonPost($this->uri('/compliance/transactions'), $body);

        return ComplianceTransaction::fromArray($this->extractData($payload));
    }

    /**
     * Paginated list of the company's transactions (newest invoice date first),
     * optionally filtered by invoice-date range, state and type. Unlike the
     * other list endpoints the server VALIDATES $perPage (1-100) instead of
     * clamping it — out-of-range values yield a {@see ValidationException}.
     */
    public function listTransactions(
        DateTimeInterface|string|null $dateFrom = null,
        DateTimeInterface|string|null $dateTo = null,
        ComplianceTransactionState|string|null $state = null,
        ComplianceTransactionType|string|null $transactionType = null,
        int $page = 1,
        int $perPage = 25,
    ): ComplianceTransactionPage {
        $uri = $this->uri('/compliance/transactions' . $this->buildQuery([
            'date_from' => $dateFrom !== null ? $this->formatDate($dateFrom) : null,
            'date_to' => $dateTo !== null ? $this->formatDate($dateTo) : null,
            'state' => $state !== null ? $this->enumValue($state, self::TRANSACTION_STATES, 'state') : null,
            'transaction_type' => $transactionType !== null
                ? $this->enumValue($transactionType, self::TRANSACTION_TYPES, 'transactionType')
                : null,
            'page' => (string) $page,
            'per_page' => (string) $perPage,
        ]));

        return ComplianceTransactionPage::fromArray($this->extractData($this->jsonGet($uri)));
    }

    /** Fetch a single transaction by its id. */
    public function getTransaction(int $id): ComplianceTransaction
    {
        $this->assertPositiveId($id, 'id');

        $payload = $this->jsonGet($this->uri('/compliance/transactions/' . $id));

        return ComplianceTransaction::fromArray($this->extractData($payload));
    }

    /**
     * Update a still-pending transaction. Only the provided (non-null) fields
     * are sent — everything else stays unchanged (PATCH semantics over PUT).
     * Editing a transaction that already left the pending state yields a
     * {@see ValidationException}.
     *
     * Limitation: because null means "leave unchanged", nullable backend fields
     * (due_date, tax_amount, counterparty_*, …) cannot be explicitly cleared
     * through this method.
     *
     * @param list<ComplianceInvoiceLine>|null $invoiceLines when provided, replaces all invoice lines
     */
    public function updateTransaction(
        int $id,
        ComplianceTransactionType|string|null $transactionType = null,
        ?string $invoiceNumber = null,
        DateTimeInterface|string|null $invoiceDate = null,
        DateTimeInterface|string|null $dueDate = null,
        ?string $currency = null,
        float|int|string|null $subtotal = null,
        float|int|string|null $taxAmount = null,
        float|int|string|null $total = null,
        ?string $counterpartyName = null,
        ?string $counterpartyCountry = null,
        ?string $counterpartyVatNumber = null,
        ?string $providerContactId = null,
        ?array $invoiceLines = null,
        ?int $paymentMethod = null,
        ?string $remittanceInformation = null,
        ?string $paymentMethodText = null,
        ?string $paymentTerms = null,
    ): ComplianceTransaction {
        $this->assertPositiveId($id, 'id');
        if ($invoiceNumber !== null) {
            $this->assertInvoiceNumber($invoiceNumber);
        }
        foreach (['subtotal' => $subtotal, 'taxAmount' => $taxAmount, 'total' => $total] as $name => $amount) {
            if ($amount !== null) {
                $this->assertAmount($amount, $name);
            }
        }
        if ($currency !== null && strlen($currency) !== 3) {
            throw new InvalidArgumentException('currency must be an ISO 4217 code (exactly 3 characters).');
        }
        if ($counterpartyCountry !== null && strlen($counterpartyCountry) !== 2) {
            throw new InvalidArgumentException('counterpartyCountry must be an ISO 3166-1 alpha-2 code (exactly 2 characters).');
        }

        $body = array_filter([
            'transaction_type' => $transactionType !== null
                ? $this->enumValue($transactionType, self::TRANSACTION_TYPES, 'transactionType')
                : null,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate !== null ? $this->formatDate($invoiceDate) : null,
            'due_date' => $dueDate !== null ? $this->formatDate($dueDate) : null,
            'currency' => $currency,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'counterparty_name' => $counterpartyName,
            'counterparty_country' => $counterpartyCountry,
            'counterparty_vat_number' => $counterpartyVatNumber,
            'provider_contact_id' => $providerContactId,
            'invoice_lines_attributes' => $invoiceLines !== null ? $this->invoiceLinesToArray($invoiceLines) : null,
            'payment_method' => $paymentMethod,
            'remittance_information' => $remittanceInformation,
            'payment_method_text' => $paymentMethodText,
            'payment_terms' => $paymentTerms,
        ], static fn ($v) => $v !== null);

        if ($body === []) {
            throw new InvalidArgumentException('updateTransaction requires at least one field to update.');
        }

        $payload = $this->jsonSend('PUT', $this->uri('/compliance/transactions/' . $id), $body, [200]);

        return ComplianceTransaction::fromArray($this->extractData($payload));
    }

    /**
     * Delete a still-pending transaction (204 No Content on success). Deleting
     * a transaction that already left the pending state yields a
     * {@see ValidationException}.
     */
    public function deleteTransaction(int $id): void
    {
        $this->assertPositiveId($id, 'id');

        $response = $this->send(fn () => $this->req->createRequest('DELETE', $this->uri('/compliance/transactions/' . $id)));
        $code = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($code === 422) {
            throw ValidationException::fromResponseBody($body);
        }
        if ($code !== 204 && $code !== 200) {
            throw HttpException::fromResponse($code, $body, retryAfter: $this->retryAfterOf($response));
        }
        if ($code === 200 && trim($body) !== '') {
            $this->assertSuccessful($this->decodeJson($body, $code), $code, $body);
        }
    }

    /**
     * Submit (or retry) an existing pending/errored transaction to the provider.
     * Provider failures surface as an {@see HttpException} with status code 502;
     * an already-submitted transaction yields a {@see ValidationException}.
     *
     * Requires the e-invoicing feature on the account — pure e-reporting
     * accounts get a {@see ValidationException} (their transactions are
     * reported via the aggregated daily ledgers instead).
     */
    public function submitTransaction(int $id): ComplianceTransaction
    {
        $this->assertPositiveId($id, 'id');

        $payload = $this->jsonPost($this->uri('/compliance/transactions/' . $id . '/submit'), []);

        return ComplianceTransaction::fromArray($this->extractData($payload));
    }

    /**
     * Bulk-import transactions from a semicolon-separated CSV (max 2 MB). Pass
     * the raw CSV bytes — e.g. file_get_contents('transactions.csv'). Rows are
     * grouped into invoices by invoice_number; re-uploading the same file is
     * idempotent (duplicates are counted in skippedDuplicates, not re-created).
     *
     * Required CSV columns: invoice_number, invoice_date, transaction_type,
     * subtotal, total, line_description, line_quantity, line_price, tax_name,
     * tax_percent, tax_category.
     */
    public function importTransactions(
        int $enrollmentId,
        string $csvContent,
        string $filename = 'transactions.csv',
    ): ComplianceImportResult {
        $this->assertPositiveId($enrollmentId, 'enrollmentId');
        if (trim($csvContent) === '') {
            throw new InvalidArgumentException('csvContent must not be empty.');
        }

        $filename = str_replace(["\r", "\n", '"', '\\'], '', trim($filename));
        if ($filename === '') {
            $filename = 'transactions.csv';
        }

        $boundary = bin2hex(random_bytes(16));
        $multipart = $this->multipartFormData(
            $boundary,
            ['compliance_enrollment_id' => (string) $enrollmentId],
            'file',
            $filename,
            $csvContent,
        );

        $uri = $this->uri('/compliance/transactions/import');
        $response = $this->send(function () use ($uri, $boundary, $multipart) {
            return $this->req->createRequest('POST', $uri)
                ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary)
                ->withBody($this->stream->createStream($multipart));
        });

        $code = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($code === 422) {
            throw ValidationException::fromResponseBody($body);
        }
        if ($code !== 200 && $code !== 201) {
            throw HttpException::fromResponse($code, $body, retryAfter: $this->retryAfterOf($response));
        }

        $payload = $this->decodeJson($body, $code);
        $this->assertSuccessful($payload, $code, $body);

        return ComplianceImportResult::fromArray($this->extractData($payload));
    }

    // ---------------------------------------------------------------------
    // Tax reports
    // ---------------------------------------------------------------------

    /**
     * Paginated list of the company's DGFiP tax reports (newest first),
     * optionally filtered by state. The server caps $perPage at 100.
     *
     * $state is passed through as-is (the server accepts any string filter),
     * so states added in future API versions can be filtered without an SDK
     * update — {@see ComplianceTaxReportState} covers the currently known ones.
     */
    public function listTaxReports(
        int $page = 1,
        int $perPage = 25,
        ComplianceTaxReportState|string|null $state = null,
    ): ComplianceTaxReportPage {
        $uri = $this->uri('/compliance/tax-reports' . $this->buildQuery([
            'page' => (string) $page,
            'per_page' => (string) $perPage,
            'state' => $state instanceof ComplianceTaxReportState ? $state->value : $state,
        ]));

        return ComplianceTaxReportPage::fromArray($this->extractData($this->jsonGet($uri)));
    }

    /** Fetch a single tax report by its id. */
    public function getTaxReport(int $id): ComplianceTaxReport
    {
        $this->assertPositiveId($id, 'id');

        $payload = $this->jsonGet($this->uri('/compliance/tax-reports/' . $id));

        return ComplianceTaxReport::fromArray($this->extractData($payload));
    }

    // ---------------------------------------------------------------------
    // Configuration & analytics
    // ---------------------------------------------------------------------

    /**
     * The canonical VAT rates & DGFiP tax categories for a reporting country
     * (e.g. "FR"). Defaults server-side to the configured default country when
     * no (or an unknown) country is given.
     */
    public function getVatRates(?string $country = null): ComplianceVatRates
    {
        $uri = $this->uri('/compliance/vat-rates' . $this->buildQuery(['country' => $country]));

        return ComplianceVatRates::fromArray($this->extractData($this->jsonGet($uri)));
    }

    /**
     * Aggregated turnover statistics for the authenticated company's
     * e-reporting transactions.
     *
     * @param  DateTimeInterface|string|null  $dateFrom  Inclusive lower bound (Y-m-d). Defaults server-side to 12 months ago.
     * @param  DateTimeInterface|string|null  $dateTo    Inclusive upper bound (Y-m-d). Defaults server-side to today.
     * @param  string|null  $interval  Time-series bucket size: "day", "week" or "month" (default "month").
     * @param  ComplianceTransactionType|string|null  $transactionType  Optional filter, e.g. "b2c_outbound".
     * @param  ComplianceTransactionState|string|null  $state  Optional filter, e.g. "submitted".
     */
    public function getRevenueStatistics(
        DateTimeInterface|string|null $dateFrom = null,
        DateTimeInterface|string|null $dateTo = null,
        ?string $interval = null,
        ComplianceTransactionType|string|null $transactionType = null,
        ComplianceTransactionState|string|null $state = null,
    ): RevenueStatistics {
        if ($interval !== null && !in_array($interval, self::INTERVALS, true)) {
            throw new InvalidArgumentException(sprintf(
                'interval must be one of "%s", got "%s".',
                implode('", "', self::INTERVALS),
                $interval
            ));
        }

        $uri = $this->uri('/compliance/revenue-statistics' . $this->buildQuery([
            'date_from' => $dateFrom !== null ? $this->formatDate($dateFrom) : null,
            'date_to' => $dateTo !== null ? $this->formatDate($dateTo) : null,
            'interval' => $interval,
            'transaction_type' => $transactionType !== null
                ? $this->enumValue($transactionType, self::TRANSACTION_TYPES, 'transactionType')
                : null,
            'state' => $state !== null ? $this->enumValue($state, self::TRANSACTION_STATES, 'state') : null,
        ]));

        $payload = $this->jsonGet($uri);

        return RevenueStatistics::fromArray($this->extractData($payload));
    }

    // ---------------------------------------------------------------------
    // Access request
    // ---------------------------------------------------------------------

    /**
     * Ask the Taxora team to activate E-Reporting for your account. This is the
     * one compliance endpoint that is NOT gated by the E-Reporting feature —
     * it exists precisely for customers who do not have access yet. Identity
     * defaults to the authenticated user; the parameters are only fallbacks /
     * extra context for the activation request.
     */
    public function requestEReportingAccess(
        ?string $name = null,
        ?string $email = null,
        ?string $company = null,
        ?string $phone = null,
        ?string $message = null,
        ?string $language = null,
    ): void {
        $body = array_filter([
            'name' => $name,
            'email' => $email,
            'company' => $company,
            'phone' => $phone,
            'message' => $message,
            'language' => $language,
        ], static fn ($v) => $v !== null);

        $this->jsonPost($this->uri('/compliance/e-reporting-request'), $body, [200, 201]);
    }

    // ---------------------------------------------------------------------
    // Input validation
    // ---------------------------------------------------------------------

    /**
     * Resolve an enum instance (or raw string) against the allowed wire values,
     * failing fast on anything the API would reject with a 422 anyway.
     *
     * @param list<string> $allowed
     */
    private function enumValue(BackedEnum|string $value, array $allowed, string $param): string
    {
        $raw = $value instanceof BackedEnum ? (string) $value->value : $value;

        if (!in_array($raw, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                '%s must be one of "%s", got "%s".',
                $param,
                implode('", "', $allowed),
                $raw
            ));
        }

        return $raw;
    }

    private function assertPositiveId(int $id, string $param): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $param));
        }
    }

    private function assertInvoiceNumber(string $invoiceNumber): void
    {
        if (trim($invoiceNumber) === '') {
            throw new InvalidArgumentException('invoiceNumber must not be empty.');
        }
        if (strlen($invoiceNumber) > 50) {
            throw new InvalidArgumentException('invoiceNumber must not exceed 50 characters.');
        }
    }

    private function assertAmount(float|int|string $amount, string $param): void
    {
        if (is_string($amount) && !is_numeric($amount)) {
            throw new InvalidArgumentException(sprintf('%s must be numeric.', $param));
        }
    }

    /**
     * @param array<mixed> $invoiceLines
     * @return list<array<string,mixed>>
     */
    private function invoiceLinesToArray(array $invoiceLines): array
    {
        if ($invoiceLines === []) {
            throw new InvalidArgumentException('invoiceLines requires at least one line.');
        }

        $lines = [];
        foreach ($invoiceLines as $index => $line) {
            if (!$line instanceof ComplianceInvoiceLine) {
                throw new InvalidArgumentException(sprintf(
                    'invoiceLines[%s] must be a %s instance.',
                    (string) $index,
                    ComplianceInvoiceLine::class
                ));
            }
            $lines[] = $line->toArray();
        }

        return $lines;
    }

    private function formatDate(DateTimeInterface|string $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Date string cannot be empty.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Date string must be in Y-m-d format.');
        }

        return $value;
    }

    // ---------------------------------------------------------------------
    // HTTP plumbing (kept in sync with SmartEnrichmentEndpoint)
    // ---------------------------------------------------------------------

    private function uri(string $path): string
    {
        return sprintf('%s/%s%s', $this->baseUrl, $this->apiVersion->value, $path);
    }

    /**
     * Query string ('?…' or '') from non-null parameters (insertion order preserved).
     *
     * @param array<string,string|null> $params
     */
    private function buildQuery(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value !== null) {
                $pairs[] = rawurlencode($key) . '=' . rawurlencode($value);
            }
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    /**
     * Read-only, so a transient gateway failure is retried (see RetryPolicy).
     *
     * @return array<string,mixed>
     */
    private function jsonGet(string $uri): array
    {
        /** @var array<string,mixed> */
        return $this->withRetries('e-reporting request', fn () => $this->sendJsonGet($uri));
    }

    /** @return array<string,mixed> */
    private function sendJsonGet(string $uri): array
    {
        $response = $this->send(fn () => $this->req->createRequest('GET', $uri));
        $code = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($code === 422) {
            throw ValidationException::fromResponseBody($body);
        }
        if ($code !== 200) {
            throw HttpException::fromResponse($code, $body, retryAfter: $this->retryAfterOf($response));
        }

        $payload = $this->decodeJson($body, $code);
        $this->assertSuccessful($payload, $code, $body);

        return $payload;
    }

    /**
     * @param array<string,mixed> $body
     * @param list<int> $okCodes
     * @return array<string,mixed>
     */
    private function jsonPost(string $uri, array $body, array $okCodes = [200, 201]): array
    {
        return $this->jsonSend('POST', $uri, $body, $okCodes);
    }

    /**
     * @param array<string,mixed> $body
     * @param list<int> $okCodes
     * @return array<string,mixed>
     */
    private function jsonSend(string $method, string $uri, array $body, array $okCodes): array
    {
        $json = $this->encodeJsonBody($body);
        $response = $this->send(function () use ($method, $uri, $json) {
            return $this->req->createRequest($method, $uri)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->stream->createStream($json));
        });

        $code = $response->getStatusCode();
        $responseBody = (string) $response->getBody();

        if ($code === 422) {
            throw ValidationException::fromResponseBody($responseBody);
        }
        if (!in_array($code, $okCodes, true)) {
            throw HttpException::fromResponse($code, $responseBody, retryAfter: $this->retryAfterOf($response));
        }

        $payload = $this->decodeJson($responseBody, $code);
        $this->assertSuccessful($payload, $code, $responseBody);

        return $payload;
    }

    /**
     * RFC 2388 multipart/form-data body with CRLF line endings — built manually
     * because the SDK only depends on PSR-18/PSR-7 (no multipart helper).
     *
     * @param array<string,string> $fields
     */
    private function multipartFormData(
        string $boundary,
        array $fields,
        string $fileField,
        string $filename,
        string $fileContent,
    ): string {
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
            $body .= $value . "\r\n";
        }

        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $fileField . '"; filename="' . $filename . '"' . "\r\n";
        $body .= 'Content-Type: text/csv' . "\r\n\r\n";
        $body .= $fileContent . "\r\n";
        $body .= '--' . $boundary . '--' . "\r\n";

        return $body;
    }

    /** @param callable():\Psr\Http\Message\RequestInterface $factory */
    private function send(callable $factory): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            $this->ensureValidToken();

            $request = $factory();
            $request = ($this->apiKey)($request);
            $request = ($this->auth)($request);

            $response = $this->http->sendRequest($request);
            if ($response->getStatusCode() !== 401) {
                return $response;
            }

            if ($attempt++ >= 1) {
                throw AuthenticationException::fromResponse((string) $response->getBody());
            }

            $this->refreshTokenOrFail((string) $response->getBody());
        }
    }

    private function ensureValidToken(): void
    {
        $token = $this->tokens->get();
        if ($token !== null && $token->isExpired()) {
            $this->refreshTokenOrFail('Token expired before request');
        }
    }

    private function refreshTokenOrFail(string $body): void
    {
        if ($this->tokens->get() === null) {
            throw AuthenticationException::fromResponse($body);
        }

        try {
            ($this->refreshCallback)();
        } catch (Throwable $exception) {
            throw AuthenticationException::refreshFailed($body, $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractData(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $body, int $statusCode): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new HttpException('Failed to decode JSON response from Taxora API.', $statusCode, $body);
        }

        return $decoded;
    }

    /**
     * A 2xx carrying success=false is still a failure (shared envelope
     * convention, kept in sync with VatEndpoint/SmartEnrichmentEndpoint).
     *
     * @param array<string,mixed> $payload
     */
    private function assertSuccessful(array $payload, int $statusCode, string $body): void
    {
        if (!array_key_exists('success', $payload)) {
            return;
        }

        if ($this->isTruthySuccess($payload['success'] ?? null)) {
            return;
        }

        $message = isset($payload['message']) && is_string($payload['message'])
            ? $payload['message']
            : 'Taxora API indicated a failed response.';

        throw new HttpException($message, $statusCode, $body);
    }

    private function isTruthySuccess(mixed $success): bool
    {
        if (is_bool($success)) {
            return $success;
        }
        if (is_int($success)) {
            return $success === 1;
        }
        if (is_string($success)) {
            $normalized = strtolower($success);
            return $normalized === '1' || $normalized === 'true';
        }

        return false;
    }

    /** @param array<string,mixed> $body */
    private function encodeJsonBody(array $body): string
    {
        try {
            return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Failed to encode request body as JSON: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
