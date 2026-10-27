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
use Taxora\Sdk\Enums\ComplianceService;
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
use Taxora\Sdk\Support\NorwegianOrgNumber;
use Taxora\Sdk\ValueObjects\ComplianceCheckoutSession;
use Taxora\Sdk\ValueObjects\ComplianceEnrollment;
use Taxora\Sdk\ValueObjects\ComplianceEnrollmentPage;
use Taxora\Sdk\ValueObjects\ComplianceImportResult;
use Taxora\Sdk\ValueObjects\ComplianceInvoiceLine;
use Taxora\Sdk\ValueObjects\ComplianceOfferings;
use Taxora\Sdk\ValueObjects\ComplianceSubscriptionState;
use Taxora\Sdk\ValueObjects\ComplianceTaxReport;
use Taxora\Sdk\ValueObjects\ComplianceTaxReportPage;
use Taxora\Sdk\ValueObjects\ComplianceTransaction;
use Taxora\Sdk\ValueObjects\ComplianceTransactionDocument;
use Taxora\Sdk\ValueObjects\ComplianceTransactionPage;
use Taxora\Sdk\ValueObjects\ComplianceVatRates;
use Taxora\Sdk\ValueObjects\PeppolLookupResult;
use Taxora\Sdk\ValueObjects\RegistryCompany;
use Taxora\Sdk\ValueObjects\RevenueStatistics;
use Taxora\Sdk\ValueObjects\SireneLookupResult;
use Throwable;

/**
 * Compliance endpoints: e-reporting / e-invoicing per country.
 *
 * Each enrollment books one service for one country — France: e-reporting (or
 * e-invoicing) via DGFiP Flux 10; Norway: e-invoicing via EHF / Peppol BIS 3.0.
 *
 * Typical flow (France):
 *
 *   $prefill    = $client->eReporting()->sireneLookup('12345678900012');
 *   $enrollment = $client->eReporting()->createEnrollment(email: ..., nafCode: ..., ...);
 *   $tx         = $client->eReporting()->createTransaction(enrollmentId: $enrollment->id, ...);
 *   $reports    = $client->eReporting()->listTaxReports();
 *
 * Typical flow (Norway):
 *
 *   $company    = $client->eReporting()->registryLookup('NO', '923609016')[0];
 *   $enrollment = $client->eReporting()->createNorwayEnrollment(orgNumber: ..., ...);
 *   $peppol     = $client->eReporting()->peppolLookup('NO', $buyerOrgNumber);
 *   $tx         = $client->eReporting()->createTransaction(..., counterpartyRegisterId: $buyerOrgNumber);
 *
 * All endpoints except {@see requestEReportingAccess()} and the self-service
 * checkout calls require an active e-reporting or e-invoicing feature grant /
 * subscription (403 otherwise). Write calls (create / update / delete / submit /
 * import) by a user whose only role is read-only also get a 403
 * {@see HttpException}, body code "read_only_role".
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
    private const TRANSACTION_STATES = ['pending', 'sending', 'submitted', 'error', 'not_required'];
    private const SERVICES = ['e_reporting', 'e_invoicing'];
    /** Countries served by registryLookup() / peppolLookup(). */
    private const LOOKUP_COUNTRIES = ['NO'];
    /** Server limit for invoice_pdf (some e-invoicing networks cap attachments at 10 MB). */
    private const INVOICE_PDF_MAX_BYTES = 10 * 1024 * 1024;
    /** Content types of the legal document → file extension for the fallback filename. */
    private const DOCUMENT_EXTENSIONS = ['xml' => 'xml', 'pdf' => 'pdf', 'json' => 'json'];

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
     * Create a FRANCE compliance enrollment (DGFiP Flux 10) and provision the
     * provider account. By default the DGFiP tax-report setting is activated
     * immediately; pass $autoActivate = false to only create the account.
     * For Norway use {@see createNorwayEnrollment()}.
     *
     * At least one of $siret (14 characters) / $siren (9 characters) is required.
     * $service picks the booked service ("e_reporting" — the server default — or
     * "e_invoicing"); the company must have that service unlocked, otherwise the
     * server answers 403 with code "service_not_active" ({@see HttpException}).
     *
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
        ComplianceService|string|null $service = null,
    ): ComplianceEnrollment {
        if ($country !== null && strtoupper($country) === 'NO') {
            throw new InvalidArgumentException('Norwegian enrollments use createNorwayEnrollment().');
        }
        $this->assertEmail($email, 'email');
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
            'service' => $service !== null ? $this->enumValue($service, self::SERVICES, 'service') : null,
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
     * Create a NORWAY e-invoicing enrollment (EHF 3.0 = Peppol BIS Billing 3.0)
     * and provision the provider account. By default the company is registered
     * on Peppol immediately; pass $autoActivate = false to only create the
     * account. Tip: prefill the company data with {@see registryLookup()}.
     *
     * $orgNumber is the 9-digit Norwegian organisation number (also the Peppol
     * id under scheme 0192). Spaces, a "NO" prefix and an "MVA" suffix are
     * accepted and stripped; a wrong check digit is rejected before any request.
     *
     * Norway is billed per country: a one-off setup fee when the regime is
     * activated plus a price per document. The company must have e-invoicing
     * unlocked — otherwise the server answers 403 with code
     * "service_not_active" ({@see HttpException}). Provider failures surface as
     * an {@see HttpException} with status code 502.
     *
     * @param bool|null $vatRegistered      registered in the Norwegian VAT register (MVA); server default true
     * @param bool|null $enterpriseRegister registered in Foretaksregisteret — adds the mandatory note to invoices; server default false
     * @param bool|null $reception          also register to RECEIVE e-invoices over Peppol; server default false
     * @param string|null $legalCountry     ISO 3166-1 alpha-2 country of the legal entity, when not Norway
     */
    public function createNorwayEnrollment(
        string $email,
        string $orgNumber,
        string $companyName,
        string $address,
        string $city,
        string $postalcode,
        ?bool $vatRegistered = null,
        ?bool $enterpriseRegister = null,
        ?bool $reception = null,
        ?string $vatNumber = null,
        ?string $province = null,
        ?string $legalCountry = null,
        DateTimeInterface|string|null $reportingStartDate = null,
        ?bool $autoActivate = null,
    ): ComplianceEnrollment {
        $this->assertEmail($email, 'email');
        $orgNumber = $this->norwegianOrgNumber($orgNumber, 'orgNumber');
        foreach ([
            'companyName' => [$companyName, 255],
            'address' => [$address, 255],
            'city' => [$city, 100],
            'postalcode' => [$postalcode, 20],
        ] as $param => [$value, $max]) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('%s must not be empty.', $param));
            }
            $this->assertMaxLength($value, $max, $param);
        }
        if ($legalCountry !== null) {
            $this->assertCountryCode($legalCountry, 'legalCountry');
        }

        $body = array_filter([
            'country' => 'NO',
            'service' => ComplianceService::E_INVOICING->value,
            'org_number' => $orgNumber,
            'vat_number' => $vatNumber,
            'company_name' => $companyName,
            'address' => $address,
            'city' => $city,
            'postalcode' => $postalcode,
            'province' => $province,
            'legal_country' => $legalCountry !== null ? strtoupper($legalCountry) : null,
            'email' => $email,
            'vat_registered' => $vatRegistered,
            'enterprise_register' => $enterpriseRegister,
            'reception' => $reception,
            'reporting_start_date' => $reportingStartDate !== null ? $this->formatDate($reportingStartDate) : null,
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

    /**
     * Look up companies in a national company register — Norway ("NO"):
     * Brønnøysund Enhetsregisteret. $q is an organisation number (spaces,
     * "NO…MVA" accepted → at most one result) or a company name (search).
     * Handy to prefill createNorwayEnrollment() and the buyer fields of an
     * e-invoice. For France use {@see sireneLookup()}.
     *
     * Nothing found yields an {@see HttpException} with status code 404; an
     * invalid query a {@see ValidationException}. Rate limit: 30 requests per
     * minute per user (429 beyond).
     *
     * @return list<RegistryCompany>
     */
    public function registryLookup(string $country, string $q): array
    {
        $country = $this->lookupCountry($country);
        $q = trim($q);
        if ($q === '') {
            throw new InvalidArgumentException('q must not be empty.');
        }

        // A number-shaped query is checked (and normalised) locally — the server
        // rejects a wrong check digit with a 422 anyway.
        if (preg_match('/^\d{9}$/', NorwegianOrgNumber::normalize($q)) === 1) {
            $q = $this->norwegianOrgNumber($q, 'q');
        }

        $uri = $this->uri('/compliance/registry-lookup' . $this->buildQuery([
            'country' => $country,
            'q' => $q,
        ]));

        $data = $this->extractData($this->jsonGet($uri));

        $results = [];
        foreach (is_array($data['results'] ?? null) ? $data['results'] : [] as $row) {
            if (is_array($row)) {
                /** @var array<string,mixed> $row */
                $results[] = RegistryCompany::fromArray($row);
            }
        }

        return $results;
    }

    /**
     * Check in the Peppol directory whether a participant — e.g. a Norwegian
     * buyer by organisation number — can receive e-invoices. In Norway a
     * reachable buyer (registered in ELMA) must receive an EHF e-invoice.
     *
     * The directory resolves asynchronously: {@see PeppolLookupResult::$status}
     * PENDING (reachable = null) means "ask again in a few seconds". Definite
     * answers are cached server-side for 24h. Free of charge.
     *
     * $scheme defaults server-side to 0192 (Norwegian organisation number); for
     * that scheme $id is validated (check digit) and normalised before sending.
     */
    public function peppolLookup(string $country, string $id, ?string $scheme = null): PeppolLookupResult
    {
        $country = $this->lookupCountry($country);
        $scheme = $scheme !== null ? trim($scheme) : null;

        if ($scheme === null || $scheme === '' || $scheme === NorwegianOrgNumber::PEPPOL_SCHEME) {
            $id = $this->norwegianOrgNumber($id, 'id');
        } else {
            if (preg_match('/^\d{4}$/', $scheme) !== 1) {
                throw new InvalidArgumentException('scheme must be a 4-digit Peppol identifier scheme, e.g. "0192".');
            }
            $id = trim($id);
            if ($id === '') {
                throw new InvalidArgumentException('id must not be empty.');
            }
            $this->assertMaxLength($id, 50, 'id');
        }

        $uri = $this->uri('/compliance/peppol-lookup' . $this->buildQuery([
            'country' => $country,
            'id' => $id,
            'scheme' => $scheme !== '' ? $scheme : null,
        ]));

        return PeppolLookupResult::fromArray($this->extractData($this->jsonGet($uri)));
    }

    // ---------------------------------------------------------------------
    // Transactions
    // ---------------------------------------------------------------------

    /**
     * Record and (optionally) submit a compliance transaction (France: Flux 10;
     * Norway: EHF / Peppol BIS 3.0 e-invoice).
     *
     * Immediate per-invoice submission requires the e-invoicing feature on the
     * account; in pure e-reporting mode the transaction is only recorded and
     * reported via the aggregated daily ledgers.
     *
     * Norway: $transactionType must be "b2b_domestic_outbound", and the server
     * requires $counterpartyRegisterId (the buyer's organisation number — check
     * reachability first with {@see peppolLookup()}) and $counterpartyName
     * unless $providerContactId points to a stored provider contact. A credit
     * note sets $isCreditNote and references the corrected invoice via
     * $amendedNumber (+ optional $amendedDate). $buyerReference defaults
     * server-side to the buyer's organisation number (Peppol requires one).
     *
     * Creation is idempotent on the invoice's natural key (enrollment + type +
     * invoice number + invoice date + counterparty VAT): a retried create
     * returns the already-recorded transaction with HTTP 200 instead of 201 —
     * both are treated as success. Provider failures surface as an
     * {@see HttpException} with status code 502.
     *
     * @param list<ComplianceInvoiceLine> $invoiceLines at least one line, each with at least one tax
     * @param string|null $counterpartyRegisterId buyer's company register id (Norway: 9-digit organisation number, check digit validated)
     * @param string|null $extraInfo free-text invoice note (max 1000 characters); mandatory Norwegian notes are added server-side
     * @param string|null $invoicePdf e-invoicing only: your own invoice PDF, sent along as the e-invoice's visual
     *                                representation — a file path, the raw PDF bytes or an already base64-encoded PDF
     *                                (max 10 MB); the SDK base64-encodes it for the API. Only accepted when the
     *                                country is booked as e-invoicing (422 otherwise). Read the outcome from
     *                                {@see ComplianceTransaction::$invoicePdf}.
     * @param string|null $invoicePdfFilename file name shown to the buyer (max 120 characters); defaults to the
     *                                        file's basename when $invoicePdf is a path, else server-side to the invoice number
     * @param string|null $internalReference your own reference (order number, ERP id; max 100 characters), stored for
     *                                       your search only — never sent to the buyer or the tax authority
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
        ?string $counterpartyRegisterId = null,
        ?string $counterpartyAddress = null,
        ?string $counterpartyCity = null,
        ?string $counterpartyPostalcode = null,
        ?string $counterpartyEmail = null,
        ?string $buyerReference = null,
        ?bool $isCreditNote = null,
        ?string $amendedNumber = null,
        DateTimeInterface|string|null $amendedDate = null,
        ?string $extraInfo = null,
        ?string $invoicePdf = null,
        ?string $invoicePdfFilename = null,
        ?string $internalReference = null,
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
        if ($internalReference !== null) {
            $this->assertMaxLength($internalReference, 100, 'internalReference');
        }

        $invoiceFields = $this->invoiceFields(
            $counterpartyRegisterId,
            $counterpartyAddress,
            $counterpartyCity,
            $counterpartyPostalcode,
            $counterpartyEmail,
            $buyerReference,
            $isCreditNote,
            $amendedNumber,
            $amendedDate,
            $extraInfo,
        );

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
            'internal_reference' => $internalReference,
        ], static fn ($v) => $v !== null) + $invoiceFields + $this->invoicePdfFields($invoicePdf, $invoicePdfFilename);

        $payload = $this->jsonPost($this->uri('/compliance/transactions'), $body);

        return ComplianceTransaction::fromArray($this->extractData($payload));
    }

    /**
     * Paginated list of the company's transactions (newest invoice date first),
     * optionally filtered by invoice-date range, state, type, enrollment and
     * country (e.g. "NO" to see only Norwegian e-invoices). $search is a
     * contains-match over invoice number, internal reference and buyer name;
     * $internalReference matches exactly (both max 100 characters). Unlike the other
     * list endpoints the server VALIDATES $perPage (1-100) instead of clamping
     * it — out-of-range values yield a {@see ValidationException}.
     */
    public function listTransactions(
        DateTimeInterface|string|null $dateFrom = null,
        DateTimeInterface|string|null $dateTo = null,
        ComplianceTransactionState|string|null $state = null,
        ComplianceTransactionType|string|null $transactionType = null,
        int $page = 1,
        int $perPage = 25,
        ?int $complianceEnrollmentId = null,
        ?string $country = null,
        ?string $search = null,
        ?string $internalReference = null,
    ): ComplianceTransactionPage {
        if ($complianceEnrollmentId !== null) {
            $this->assertPositiveId($complianceEnrollmentId, 'complianceEnrollmentId');
        }
        if ($country !== null) {
            $this->assertCountryCode($country, 'country');
        }
        if ($search !== null) {
            $this->assertMaxLength($search, 100, 'search');
        }
        if ($internalReference !== null) {
            $this->assertMaxLength($internalReference, 100, 'internalReference');
        }

        $uri = $this->uri('/compliance/transactions' . $this->buildQuery([
            'date_from' => $dateFrom !== null ? $this->formatDate($dateFrom) : null,
            'date_to' => $dateTo !== null ? $this->formatDate($dateTo) : null,
            'state' => $state !== null ? $this->enumValue($state, self::TRANSACTION_STATES, 'state') : null,
            'transaction_type' => $transactionType !== null
                ? $this->enumValue($transactionType, self::TRANSACTION_TYPES, 'transactionType')
                : null,
            'compliance_enrollment_id' => $complianceEnrollmentId !== null ? (string) $complianceEnrollmentId : null,
            'country' => $country !== null ? strtoupper($country) : null,
            'search' => $search,
            'internal_reference' => $internalReference,
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
     * The e-invoicing fields ($counterpartyRegisterId … $extraInfo) behave as in
     * {@see createTransaction()}; they are merged into the stored ones. $invoicePdf
     * (path, raw bytes or base64) replaces a PDF that is still waiting to be sent.
     *
     * $internalReference (max 100 characters) is the exception to the limitation
     * above: an empty string clears it.
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
        ?string $counterpartyRegisterId = null,
        ?string $counterpartyAddress = null,
        ?string $counterpartyCity = null,
        ?string $counterpartyPostalcode = null,
        ?string $counterpartyEmail = null,
        ?string $buyerReference = null,
        ?bool $isCreditNote = null,
        ?string $amendedNumber = null,
        DateTimeInterface|string|null $amendedDate = null,
        ?string $extraInfo = null,
        ?string $invoicePdf = null,
        ?string $invoicePdfFilename = null,
        ?string $internalReference = null,
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
        if ($internalReference !== null) {
            $this->assertMaxLength($internalReference, 100, 'internalReference');
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
            'internal_reference' => $internalReference,
        ], static fn ($v) => $v !== null) + $this->invoiceFields(
            $counterpartyRegisterId,
            $counterpartyAddress,
            $counterpartyCity,
            $counterpartyPostalcode,
            $counterpartyEmail,
            $buyerReference,
            $isCreditNote,
            $amendedNumber,
            $amendedDate,
            $extraInfo,
        ) + $this->invoicePdfFields($invoicePdf, $invoicePdfFilename);

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
     * Download the legal e-invoice of a transaction (e-invoicing only) — the
     * structured document (e.g. EHF / Peppol UBL XML) exactly as it was sent to
     * the buyer. Available once the transaction is submitted
     * ({@see ComplianceTransaction::hasDocument()}); Taxora keeps an archived
     * copy, so it stays downloadable.
     *
     * Not sent yet, or the provider is still preparing the document: an
     * {@see HttpException} with status 409 — try again in a few minutes. 404 when
     * the transaction does not exist, 502 on a provider error.
     */
    public function getTransactionDocument(int $id): ComplianceTransactionDocument
    {
        $this->assertPositiveId($id, 'id');

        $uri = $this->uri('/compliance/transactions/' . $id . '/document');
        $response = $this->rawGet($uri, 'application/xml, application/pdf, application/octet-stream, application/json', 'e-invoice document download');

        $contentType = trim($response->getHeaderLine('Content-Type'));
        if ($contentType === '') {
            $contentType = 'application/octet-stream';
        }

        return new ComplianceTransactionDocument(
            content: (string) $response->getBody(),
            contentType: $contentType,
            filename: $this->filenameFrom($response) ?? sprintf('e-invoice-%d.%s', $id, $this->extensionFor($contentType)),
        );
    }

    /**
     * Download the invoice as a readable PDF — the seller's attached PDF, or the
     * provider's rendering of the e-invoice. Available once
     * {@see ComplianceTransaction::hasPdf()} ($pdfUrl is set).
     *
     * No PDF (yet): an {@see HttpException} with status 409. 404 when the
     * transaction does not exist, 502 on a provider error.
     */
    public function getTransactionPdf(int $id): ComplianceTransactionDocument
    {
        $this->assertPositiveId($id, 'id');

        $uri = $this->uri('/compliance/transactions/' . $id . '/pdf');
        $response = $this->rawGet($uri, 'application/pdf, application/octet-stream, application/json', 'invoice PDF download');

        $contentType = trim($response->getHeaderLine('Content-Type'));
        if ($contentType === '') {
            $contentType = 'application/pdf';
        }

        return new ComplianceTransactionDocument(
            content: (string) $response->getBody(),
            contentType: $contentType,
            filename: $this->filenameFrom($response) ?? sprintf('invoice-%d.pdf', $id),
        );
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
     * $country (ISO alpha-2, any case) limits the list to one country.
     */
    public function listTaxReports(
        int $page = 1,
        int $perPage = 25,
        ComplianceTaxReportState|string|null $state = null,
        ?string $country = null,
    ): ComplianceTaxReportPage {
        if ($country !== null) {
            $this->assertCountryCode($country, 'country');
        }

        $uri = $this->uri('/compliance/tax-reports' . $this->buildQuery([
            'page' => (string) $page,
            'per_page' => (string) $perPage,
            'state' => $state instanceof ComplianceTaxReportState ? $state->value : $state,
            'country' => $country !== null ? strtoupper($country) : null,
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
     * @param  int|null  $complianceEnrollmentId  Optional: only this enrollment (= one country), e.g. France without Norway.
     */
    public function getRevenueStatistics(
        DateTimeInterface|string|null $dateFrom = null,
        DateTimeInterface|string|null $dateTo = null,
        ?string $interval = null,
        ComplianceTransactionType|string|null $transactionType = null,
        ComplianceTransactionState|string|null $state = null,
        ?int $complianceEnrollmentId = null,
    ): RevenueStatistics {
        if ($complianceEnrollmentId !== null) {
            $this->assertPositiveId($complianceEnrollmentId, 'complianceEnrollmentId');
        }
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
            'compliance_enrollment_id' => $complianceEnrollmentId !== null ? (string) $complianceEnrollmentId : null,
        ]));

        $payload = $this->jsonGet($uri);

        return RevenueStatistics::fromArray($this->extractData($payload));
    }

    /**
     * Net and VAT per month, currency and tax rate as a semicolon-separated CSV
     * (columns: month;currency;tax_category;tax_rate_percent;lines;net;vat;gross),
     * computed from the invoice lines — for reconciling the reported figures with
     * your VAT return. Same filters as {@see getRevenueStatistics()} (without
     * interval, plus $complianceEnrollmentId for one country); $dateFrom defaults
     * server-side to one year before $dateTo, which defaults to today.
     *
     * @return string the raw CSV bytes, returned exactly as sent (a BOM, if any, is kept)
     */
    public function exportRevenueTaxRatesCsv(
        DateTimeInterface|string|null $dateFrom = null,
        DateTimeInterface|string|null $dateTo = null,
        ComplianceTransactionType|string|null $transactionType = null,
        ComplianceTransactionState|string|null $state = null,
        ?int $complianceEnrollmentId = null,
    ): string {
        if ($complianceEnrollmentId !== null) {
            $this->assertPositiveId($complianceEnrollmentId, 'complianceEnrollmentId');
        }
        $uri = $this->uri('/compliance/revenue-statistics/tax-rates.csv' . $this->buildQuery([
            'date_from' => $dateFrom !== null ? $this->formatDate($dateFrom) : null,
            'date_to' => $dateTo !== null ? $this->formatDate($dateTo) : null,
            'transaction_type' => $transactionType !== null
                ? $this->enumValue($transactionType, self::TRANSACTION_TYPES, 'transactionType')
                : null,
            'state' => $state !== null ? $this->enumValue($state, self::TRANSACTION_STATES, 'state') : null,
            'compliance_enrollment_id' => $complianceEnrollmentId !== null ? (string) $complianceEnrollmentId : null,
        ]));

        return (string) $this->rawGet($uri, 'text/csv, application/json', 'tax rates CSV export')->getBody();
    }

    // ---------------------------------------------------------------------
    // Self-service plans (checkout)
    // ---------------------------------------------------------------------

    /**
     * Bookable compliance plans per country with prices and the company's
     * current state, every country Taxora reaches ({@see ComplianceOfferings::$coverage})
     * and which services are unlocked — company-wide and per country. Like the
     * other self-service calls this works WITHOUT an active compliance service.
     */
    public function getOfferings(): ComplianceOfferings
    {
        return ComplianceOfferings::fromArray($this->jsonGet($this->uri('/compliance/offerings')));
    }

    /**
     * Start a Stripe checkout for a compliance plan ($plan = a plan key from
     * {@see getOfferings()}, e.g. "standard") and send the buyer to the returned
     * URL. After payment Stripe redirects to the Taxora portal; call
     * {@see confirmCheckout()} with the session id to activate the plan right
     * away (the payment webhook activates it as well).
     *
     * Unknown / contact-only plans, unmet prerequisites and an already active
     * plan for the country yield a {@see HttpException} with status 422; Stripe
     * errors a 502. Not retried — a retry could open a second checkout.
     */
    public function createCheckout(string $country, string $plan): ComplianceCheckoutSession
    {
        $this->assertCountryCode($country, 'country');
        if (trim($plan) === '') {
            throw new InvalidArgumentException('plan must not be empty.');
        }
        $this->assertMaxLength($plan, 30, 'plan');

        $payload = $this->jsonPost($this->uri('/compliance/checkout'), [
            'country' => strtoupper($country),
            'plan' => $plan,
        ], [200, 201]);

        return ComplianceCheckoutSession::fromArray($this->extractData($payload));
    }

    /**
     * Confirm a finished checkout and activate its plan (idempotent). Unknown,
     * foreign or unpaid sessions return active = false with null details.
     */
    public function confirmCheckout(string $sessionId): ComplianceSubscriptionState
    {
        if (trim($sessionId) === '') {
            throw new InvalidArgumentException('sessionId must not be empty.');
        }
        $this->assertMaxLength($sessionId, 255, 'sessionId');

        $payload = $this->jsonPost($this->uri('/compliance/checkout/confirm'), ['session_id' => $sessionId], [200]);

        return ComplianceSubscriptionState::fromArray($this->extractData($payload));
    }

    /**
     * URL of a Stripe billing portal session for the company's self-service
     * plans (invoices, payment method, cancellation). Short-lived — open it
     * right away. 404 ({@see HttpException}) when no plan was bought via Stripe.
     */
    public function createBillingPortalSession(): string
    {
        $data = $this->extractData($this->jsonPost($this->uri('/compliance/billing-portal'), [], [200]));
        $url = isset($data['url']) ? trim((string) $data['url']) : '';
        if ($url === '') {
            throw new HttpException('Billing portal response is missing url.', 200, (string) json_encode($data));
        }

        return $url;
    }

    // ---------------------------------------------------------------------
    // Access request
    // ---------------------------------------------------------------------

    /**
     * Ask the Taxora team to activate a compliance service for your account.
     * This is the one compliance endpoint that is NOT gated by the compliance
     * feature — it exists precisely for customers who do not have access yet.
     * Identity defaults to the authenticated user; the parameters are only
     * fallbacks / extra context for the activation request.
     *
     * @param ComplianceService|string|null $service "e_reporting" (server default) or "e_invoicing"
     * @param list<string>|null $countries ISO 3166-1 alpha-2 codes of the countries you need, e.g. ['NO'] (max 30)
     */
    public function requestEReportingAccess(
        ?string $name = null,
        ?string $email = null,
        ?string $company = null,
        ?string $phone = null,
        ?string $message = null,
        ?string $language = null,
        ComplianceService|string|null $service = null,
        ?array $countries = null,
    ): void {
        if ($countries !== null) {
            if (count($countries) > 30) {
                throw new InvalidArgumentException('countries must not contain more than 30 entries.');
            }
            $normalized = [];
            foreach ($countries as $index => $country) {
                if (!is_string($country)) {
                    throw new InvalidArgumentException(sprintf('countries[%s] must be a string.', (string) $index));
                }
                $this->assertCountryCode($country, sprintf('countries[%s]', (string) $index));
                $normalized[] = strtoupper($country);
            }
            $countries = $normalized;
        }

        $body = array_filter([
            'name' => $name,
            'email' => $email,
            'company' => $company,
            'phone' => $phone,
            'message' => $message,
            'language' => $language,
            'service' => $service !== null ? $this->enumValue($service, self::SERVICES, 'service') : null,
            'countries' => $countries,
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

    private function assertEmail(string $email, string $param): void
    {
        if (trim($email) === '' || !str_contains($email, '@')) {
            throw new InvalidArgumentException(sprintf('%s must be a valid e-mail address.', $param));
        }
    }

    /** Length in characters (UTF-8 aware without requiring ext-mbstring), like the server's max rule. */
    private function assertMaxLength(string $value, int $max, string $param): void
    {
        $length = preg_match_all('/./su', $value);
        if ($length === false) {
            $length = strlen($value);
        }
        if ($length > $max) {
            throw new InvalidArgumentException(sprintf('%s must not exceed %d characters.', $param, $max));
        }
    }

    private function assertCountryCode(string $country, string $param): void
    {
        if (preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
            throw new InvalidArgumentException(sprintf('%s must be an ISO 3166-1 alpha-2 code (exactly 2 letters).', $param));
        }
    }

    /** Validate + normalise a Norwegian organisation number to its 9 digits. */
    private function norwegianOrgNumber(string $value, string $param): string
    {
        if (!NorwegianOrgNumber::isValid($value)) {
            throw new InvalidArgumentException(sprintf(
                '%s must be a valid Norwegian organisation number (9 digits with a valid check digit).',
                $param
            ));
        }

        return NorwegianOrgNumber::normalize($value);
    }

    private function lookupCountry(string $country): string
    {
        $country = strtoupper(trim($country));
        if (!in_array($country, self::LOOKUP_COUNTRIES, true)) {
            throw new InvalidArgumentException(sprintf(
                'country must be one of "%s", got "%s".',
                implode('", "', self::LOOKUP_COUNTRIES),
                $country
            ));
        }

        return $country;
    }

    /**
     * The e-invoicing buyer / document fields shared by create + update
     * (validated against the server limits, null fields dropped).
     *
     * @return array<string,mixed>
     */
    private function invoiceFields(
        ?string $counterpartyRegisterId,
        ?string $counterpartyAddress,
        ?string $counterpartyCity,
        ?string $counterpartyPostalcode,
        ?string $counterpartyEmail,
        ?string $buyerReference,
        ?bool $isCreditNote,
        ?string $amendedNumber,
        DateTimeInterface|string|null $amendedDate,
        ?string $extraInfo,
    ): array {
        if ($counterpartyRegisterId !== null) {
            if (trim($counterpartyRegisterId) === '') {
                throw new InvalidArgumentException('counterpartyRegisterId must not be empty.');
            }
            $this->assertMaxLength($counterpartyRegisterId, 30, 'counterpartyRegisterId');
            // Number-shaped ids are Norwegian organisation numbers today — catch a
            // wrong check digit before the server does.
            if (preg_match('/^\d{9}$/', NorwegianOrgNumber::normalize($counterpartyRegisterId)) === 1) {
                $counterpartyRegisterId = $this->norwegianOrgNumber($counterpartyRegisterId, 'counterpartyRegisterId');
            }
        }
        if ($counterpartyEmail !== null) {
            $this->assertEmail($counterpartyEmail, 'counterpartyEmail');
        }
        foreach ([
            'counterpartyAddress' => [$counterpartyAddress, 255],
            'counterpartyCity' => [$counterpartyCity, 100],
            'counterpartyPostalcode' => [$counterpartyPostalcode, 20],
            'counterpartyEmail' => [$counterpartyEmail, 255],
            'buyerReference' => [$buyerReference, 100],
            'amendedNumber' => [$amendedNumber, 50],
            'extraInfo' => [$extraInfo, 1000],
        ] as $param => [$value, $max]) {
            if ($value !== null) {
                $this->assertMaxLength($value, $max, $param);
            }
        }

        return array_filter([
            'counterparty_register_id' => $counterpartyRegisterId,
            'counterparty_address' => $counterpartyAddress,
            'counterparty_city' => $counterpartyCity,
            'counterparty_postalcode' => $counterpartyPostalcode,
            'counterparty_email' => $counterpartyEmail,
            'buyer_reference' => $buyerReference,
            'is_credit_note' => $isCreditNote,
            'amended_number' => $amendedNumber,
            'amended_date' => $amendedDate !== null ? $this->formatDate($amendedDate) : null,
            'extra_info' => $extraInfo,
        ], static fn ($v) => $v !== null);
    }

    /**
     * invoice_pdf (+ invoice_pdf_filename) as the API expects them: the PDF
     * base64-encoded. $pdf may be a file path, the raw PDF bytes or an already
     * base64-encoded PDF (optionally as a data:application/pdf;base64, URI).
     *
     * @return array<string,string>
     */
    private function invoicePdfFields(?string $pdf, ?string $filename): array
    {
        if ($filename !== null) {
            $filename = trim($filename);
            if ($filename === '') {
                throw new InvalidArgumentException('invoicePdfFilename must not be empty.');
            }
            $this->assertMaxLength($filename, 120, 'invoicePdfFilename');
            if ($pdf === null) {
                throw new InvalidArgumentException('invoicePdfFilename requires invoicePdf.');
            }
        }
        if ($pdf === null) {
            return [];
        }

        $bytes = $this->invoicePdfBytes($pdf, $filename);

        if (!str_starts_with($bytes, '%PDF-')) {
            throw new InvalidArgumentException('invoicePdf must be a PDF (a file path, the raw bytes or base64).');
        }
        if (strlen($bytes) > self::INVOICE_PDF_MAX_BYTES) {
            throw new InvalidArgumentException('invoicePdf must not exceed 10 MB.');
        }

        return array_filter([
            'invoice_pdf' => base64_encode($bytes),
            'invoice_pdf_filename' => $filename,
        ], static fn ($v) => $v !== null);
    }

    /** Resolve the invoicePdf argument to raw bytes; a path also defaults the filename. */
    private function invoicePdfBytes(string $pdf, ?string &$filename): string
    {
        if (str_starts_with($pdf, '%PDF-')) {
            return $pdf;
        }

        $base64 = preg_replace('#^data:application/pdf;base64,#i', '', trim($pdf));
        if ($base64 !== null && $base64 !== trim($pdf)) {
            $decoded = base64_decode($base64, true);

            return $decoded === false ? '' : $decoded;
        }

        if (strlen($pdf) < 4096 && !str_contains($pdf, "\0") && is_file($pdf)) {
            if (!is_readable($pdf)) {
                throw new InvalidArgumentException(sprintf('invoicePdf file "%s" is not readable.', $pdf));
            }
            $bytes = file_get_contents($pdf);
            if ($bytes === false) {
                throw new InvalidArgumentException(sprintf('invoicePdf file "%s" could not be read.', $pdf));
            }
            if ($filename === null) {
                $basename = basename($pdf);
                $filename = $basename !== '' ? $this->truncateFilename($basename) : null;
            }

            return $bytes;
        }

        $decoded = base64_decode(trim($pdf), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('invoicePdf must be a readable file path, the raw PDF bytes or base64.');
        }

        return $decoded;
    }

    /** Keep a defaulted basename within the server's 120-character limit (extension preserved). */
    private function truncateFilename(string $filename): string
    {
        $length = preg_match_all('/./su', $filename);
        if ($length === false || $length <= 120) {
            return $filename;
        }
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.' . $extension : '';
        $stem = (string) preg_replace('/^(.{0,' . (120 - strlen($suffix)) . '}).*$/su', '$1', $filename);

        return $stem . $suffix;
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
     * GET of a non-JSON download (CSV, XML, PDF): same auth/401-refresh flow, a
     * 422 becomes a {@see ValidationException}; read-only, so retried on a
     * transient gateway failure (see RetryPolicy).
     */
    private function rawGet(string $uri, string $accept, string $operation): ResponseInterface
    {
        /** @var ResponseInterface */
        return $this->withRetries($operation, function () use ($uri, $accept): ResponseInterface {
            $response = $this->send(fn () => $this->req->createRequest('GET', $uri)->withHeader('Accept', $accept));
            $code = $response->getStatusCode();

            if ($code === 422) {
                throw ValidationException::fromResponseBody((string) $response->getBody());
            }
            if ($code !== 200) {
                throw HttpException::fromResponse($code, (string) $response->getBody(), retryAfter: $this->retryAfterOf($response));
            }

            return $response;
        });
    }

    /** The filename of a Content-Disposition header (RFC 6266 filename* preferred), or null. */
    private function filenameFrom(ResponseInterface $response): ?string
    {
        $header = $response->getHeaderLine('Content-Disposition');
        if ($header === '') {
            return null;
        }

        if (preg_match("/filename\\*\\s*=\\s*(?:UTF-8|ISO-8859-1)?''([^;]+)/i", $header, $m) === 1) {
            $name = rawurldecode(trim($m[1], " \t\""));
        } elseif (preg_match('/filename\\s*=\\s*"([^"]*)"/i', $header, $m) === 1 || preg_match('/filename\\s*=\\s*([^;]+)/i', $header, $m) === 1) {
            $name = trim($m[1]);
        } else {
            return null;
        }

        // Never let a header steer where the caller writes the file.
        $name = basename(str_replace('\\', '/', $name));

        return $name !== '' && $name !== '.' && $name !== '..' ? $name : null;
    }

    private function extensionFor(string $contentType): string
    {
        foreach (self::DOCUMENT_EXTENSIONS as $needle => $extension) {
            if (stripos($contentType, $needle) !== false) {
                return $extension;
            }
        }

        return 'bin';
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
