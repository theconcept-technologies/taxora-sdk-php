<p>
  <img src="https://taxora.io/assets/logo/taxora_logo.svg" alt="Taxora Logo" width="220"/>
</p>

#  Taxora PHP SDK
> **Official PHP SDK for the [Taxora VAT Validation API](https://taxora.io)**
> Validate EU VAT numbers, generate compliance certificates, and integrate VAT checks seamlessly into your systems — all with clean, modern PHP.

![Build](https://github.com/theconcept-technologies/taxora-sdk-php/actions/workflows/ci.yml/badge.svg)
---

## 🚀 Overview

The **Taxora SDK** provides an elegant, PSR-compliant interface to the [Taxora API](https://taxora.io), supporting:

* ✅ Secure **API-Key** and **Bearer Token** authentication
* ✅ Single & multiple VAT validation with AI-based company matching
* ✅ VAT state history and search endpoints
* ✅ Certificate generation (PDF) and bulk/list exports (ZIP or PDF)
* ✅ Full test coverage & PSR-18 compatible HTTP client
* ✅ PHP 8.3, 8.4, and (soon) 8.5 ready

> 🔒 The SDK itself is free to use, but a **Taxora API subscription** is required.
> You can obtain your `x-api-key` from your [Taxora account developer settings](https://app.taxora.io).

---

## 🧮 Installation

Install via Composer:

```bash
composer require taxora/sdk-php
```

The package supports all PSR-18 clients (e.g. Guzzle, Symfony, Buzz) and PSR-17/PSR-7 factories.

Example dependencies for Guzzle:

```bash
composer require guzzlehttp/guzzle http-interop/http-factory-guzzle
```

---

## ⚙️ Quick Start

```php
use Taxora\Sdk\TaxoraClientFactory;
use Taxora\Sdk\Enums\Environment;

$client = TaxoraClientFactory::create(
    apiKey: 'YOUR_X_API_KEY',
    environment: Environment::SANDBOX // or PRODUCTION
);

// 1️⃣ Authenticate
$client->auth()->login('user@example.com', 'superSecret');

// 2️⃣ Validate a VAT number
$vat = $client->vat()->validate('ATU12345678', 'Example GmbH');
echo $vat->state->value;        // valid / invalid
echo $vat->company_name; // Official company name
echo $vat->score;        // Overall confidence score (float)
var_dump($vat->has_api_error); // true when the official provider had a technical failure
echo $vat->error_message; // technical provider error details, if returned
echo $vat->next_api_recheck_at; // planned retry timestamp, if returned

foreach ($vat->breakdown ?? [] as $step) {
    echo $step->stepName.' gave '.$step->scoreContribution.PHP_EOL;
}

// Optional typed address input for fallback name scoring
$addressInput = new \Taxora\Sdk\ValueObjects\VatValidationAddressInput(
    addressLine1: 'Example GmbH',
    addressLine2: '2nd Floor',
    postalCode: '1010',
    city: 'Vienna',
    countryCode: 'AT'
);
$vatWithAddress = $client->vat()->validate('ATU12345678', 'John Doe', 'vies', $addressInput);

// 3️⃣ Access company info
$company = $client->company()->get();
echo $company['api_rate_limit'] ?? 'n/a';
echo $company['vat_rate_limit'] ?? 'n/a';

// 4️⃣ Export certificates (returns a VatCertificateExport object)
$export = $client->vat()->certificatesBulkExport('2024-01-01', '2024-12-31');
$pdfZip = $client->vat()->downloadBulkExport($export->exportId);
file_put_contents('certificates.zip', $pdfZip);
```

### 🔎 Smart Enrichment (reverse VAT lookup)

Resolve a company **name + address + country → VAT number + confidence**. A confident match
returns synchronously; harder cases (and all bulk batches) are processed asynchronously and
delivered via the `enrichment.completed` webhook — poll with `get($jobId)` in the meantime.
Requires the Smart Enrichment add-on to be active on your account.

```php
// Single lookup
$job = $client->smartEnrichment()->lookup(
    'Example Company GmbH',
    'AT',
    street: 'Main Street 10',
    postalCode: '1010',
    city: 'Vienna',
);

if ($job->isProcessing()) {
    // Resolved asynchronously — poll (or wait for the webhook)
    $job = $client->smartEnrichment()->get($job->jobId);
}

$result = $job->result();
echo $result?->status->value;   // found | no_vat_exists | not_found
echo $result?->vatNumber;       // e.g. ATU12345678
echo $result?->confidence;      // 0–100

// Search mode: `default` searches with one AI provider and only consults a second one
// when the first finds nothing. `complex` has two independent providers search from the
// start and puts every number either of them proposes through the tax authority's checks.
// Complex finds companies a single search misses — typically ones whose VAT only appears
// in a website Impressum — and costs more per lookup, so it is opt-in.
$job = $client->smartEnrichment()->lookup(
    'Vodafone D2 GmbH',
    'DE',
    postalCode: '40547',
    mode: SmartEnrichmentMode::COMPLEX,
);

// When more than one provider searched, you can see what each one concluded on its own.
// Two of them reporting the same number is the strongest confirmation this layer produces.
foreach ($job->result()?->providerVerdicts ?? [] as $verdict) {
    echo $verdict['provider'] . ': ' . ($verdict['vatNumber'] ?? 'nothing') . PHP_EOL;
}

// The API also grades the address you sent. Worth writing back into your own records:
// a `postal_code_city_mismatch` / `postal_code_unassigned` warning means the postal code
// in your source data is wrong, and `derivedPlace` is the area it actually points at.
$quality = $job->result()?->addressQuality;
if ($quality !== null && $quality['warnings'] !== []) {
    echo implode(', ', $quality['warnings']) . ' → ' . ($quality['derivedPlace'] ?? '?') . PHP_EOL;
}

// Bulk lookup (always async → webhook + polling). The batch-level mode applies to every
// row; a row carrying its own 'mode' overrides it, so you can pay for `complex` on just
// the rows that need it.
$bulk = $client->smartEnrichment()->bulkLookup([
    ['companyName' => 'A GmbH', 'country' => 'DE', 'city' => 'Berlin'],
    ['companyName' => 'B SARL', 'country' => 'FR', 'mode' => 'complex'],
]);
$done = $client->smartEnrichment()->get($bulk->jobId);
foreach ($done->results as $r) {
    echo $r->status->value . ': ' . ($r->vatNumber ?? '—') . PHP_EOL;
}

// Or block until the job is done (polls get() every 2s, up to 120s by default;
// throws Taxora\Sdk\Exceptions\TimeoutException when the wait runs out — the job
// keeps running server-side and can still be polled afterwards)
$done = $client->smartEnrichment()->waitUntilComplete(
    $bulk->jobId,
    timeoutSeconds: 120.0,
    pollIntervalSeconds: 2.0,
);

// Lookup history (paginated, newest first; optional free-text search over the
// queried company name and the resolved VAT number / matched name; perPage is
// capped at 100 server-side)
$history = $client->smartEnrichment()->history(page: 1, perPage: 25, search: 'Example');
echo $history->total . ' lookups' . PHP_EOL;
echo ($history->stats?->found ?? 0) . ' of ' . ($history->stats?->total ?? 0) . ' ever matched' . PHP_EOL;
foreach ($history as $row) {
    echo $row->queryCompanyName . ' → ' . ($row->result?->vatNumber ?? $row->status->value) . PHP_EOL;
}

// Quota usage for the current billing period (+ recent monthly billing history)
$usage = $client->smartEnrichment()->usage();
echo $usage->used . '/' . $usage->included . ' matches used, ' . $usage->remaining . ' left' . PHP_EOL;
foreach ($usage->history as $entry) {
    echo $entry->period . ': ' . $entry->matches . ' matches, ' . $entry->amount . ' EUR (' . $entry->state . ')' . PHP_EOL;
}

// CSV export of all lookups (bulk-input shape + resolved VAT columns; UTF-8 with BOM).
// All filters are optional — an empty call exports the whole history.
$csv = $client->smartEnrichment()->export(
    dateFrom: '2026-01-01',            // Y-m-d, on the job's creation date
    dateTo: '2026-06-30',
    minConfidence: 80.0,               // 0–100
    status: ['found', 'not_found'],    // found | no_vat_exists | not_found
    onlyFound: true,                   // only rows that resolved a VAT number
);
file_put_contents('smart-enrichment.csv', $csv);

// Aggregated statistics (defaults to the last 12 months, monthly buckets)
$stats = $client->smartEnrichment()->statistics(
    dateFrom: '2026-01-01',            // optional (Y-m-d)
    dateTo: '2026-06-30',              // optional (Y-m-d, >= dateFrom)
    interval: 'month',                 // 'day' | 'week' | 'month' (default 'month')
);
echo $stats->totals->found . '/' . $stats->totals->items . ' matched (' . $stats->totals->foundRate . '%)' . PHP_EOL;
foreach ($stats->timeSeries as $bucket) {
    echo $bucket->bucket . ': ' . $bucket->found . '/' . $bucket->items . PHP_EOL; // 2026-06: 8/10
}
foreach ($stats->byOutcome as $row) {
    echo $row['outcome'] . ' → ' . $row['count'] . PHP_EOL;
}
```

> Note: `no_vat_exists` means the company was identified but legitimately has **no** VAT/UID
> number (common for purely-domestic German firms). It is a definitive answer — not a failure —
> and is not billed.

### 🇫🇷 E-Reporting / Compliance (DGFiP Flux 10)

Full French e-reporting flow: enroll a company with the provider, record (and
optionally submit) transactions, follow the resulting DGFiP tax reports, and pull
turnover statistics. All endpoints require an active E-Reporting subscription /
feature grant — except `requestEReportingAccess()`, which is exactly how you ask
for one. Provider failures (B2Brouter/DGFiP) surface as `HttpException` with
status code `502`.

#### 1. Enroll (SIRENE lookup → enrollment)

```php
// Prefill company data from the French SIRENE registry by SIRET, SIREN or FR VAT
// number (rate limit: 3 requests/minute).
$prefill = $client->eReporting()->sireneLookup('12345678900012');

$enrollment = $client->eReporting()->createEnrollment(
    email: 'tax@acme.fr',                    // notification e-mail (required)
    nafCode: $prefill->nafCode ?? '47',      // 2-digit NAF division (required)
    enterpriseSize: $prefill->enterpriseSize ?? 'pme', // micro | pme | eti | ge
    typeOperation: 'mixed',                  // services | goods | mixed
    reportingStartDate: '2026-09-01',        // Y-m-d or DateTimeInterface
    siret: $prefill->siret,                  // 14 chars — at least one of siret/siren
    siren: $prefill->siren,                  // 9-char fallback when no head office SIRET exists
    companyName: $prefill->companyName,
    address: $prefill->address,
    city: $prefill->city,
    postalcode: $prefill->postalcode,
    autoActivate: true,                      // false = create the account only
);

echo $enrollment->status->value;             // e.g. regime_activated
echo $enrollment->statusLabel;               // human-readable label from the API

// Later: list / fetch enrollments (paginated, perPage capped at 100)
$enrollments = $client->eReporting()->listEnrollments(page: 1, perPage: 25);
$enrollment  = $client->eReporting()->getEnrollment($enrollment->id);
```

#### 2. Record & submit transactions

```php
use Taxora\Sdk\Enums\ComplianceTransactionType;
use Taxora\Sdk\ValueObjects\ComplianceInvoiceLine;
use Taxora\Sdk\ValueObjects\ComplianceLineTax;

$tx = $client->eReporting()->createTransaction(
    enrollmentId: $enrollment->id,
    transactionType: ComplianceTransactionType::B2C_OUTBOUND, // or 'b2c_outbound'
    invoiceNumber: 'TKT-2026-00001',        // max 50 chars
    invoiceDate: '2026-06-15',              // Y-m-d or DateTimeInterface
    subtotal: '100.00',
    total: '120.00',
    invoiceLines: [
        new ComplianceInvoiceLine(
            description: 'Museum ticket',
            quantity: 2,
            price: 50,
            taxes: [new ComplianceLineTax(name: 'TVA', percent: 20, category: 'S')],
        ),
    ],
    currency: 'EUR',                        // optional, defaults to EUR
    taxAmount: '20.00',
    submitNow: false,                       // defer provider submission
);

// Creation is idempotent: retrying the same invoice (enrollment + type + number +
// date + counterparty VAT) returns the existing transaction instead of a duplicate.

// Pending transactions can still be edited (PATCH semantics — only what you pass
// is changed; nullable fields cannot be cleared back to null through the SDK)
// or deleted; submit (or retry) explicitly when ready. Note: per-invoice
// submission requires the e-invoicing feature — in pure e-reporting mode
// transactions are reported automatically via the aggregated daily ledgers
// and submitTransaction() returns a 422 ValidationException.
$tx = $client->eReporting()->updateTransaction($tx->id, total: '130.00');
$tx = $client->eReporting()->submitTransaction($tx->id);
$client->eReporting()->deleteTransaction($obsoleteId);   // 204, only while pending

// Browse what has been recorded (filters optional, perPage capped at 100)
$page = $client->eReporting()->listTransactions(
    dateFrom: '2026-06-01',
    dateTo: '2026-06-30',
    state: 'pending',                       // pending | sending | submitted | error
    transactionType: 'b2c_outbound',
);
foreach ($page as $row) {
    echo $row->invoiceNumber . ': ' . $row->state->value . PHP_EOL;
}
```

#### 3. CSV bulk import

```php
// Semicolon-separated CSV, max 2 MB; rows are grouped into invoices by
// invoice_number, re-uploads are idempotent. Required columns: invoice_number,
// invoice_date, transaction_type, subtotal, total, line_description,
// line_quantity, line_price, tax_name, tax_percent, tax_category.
$result = $client->eReporting()->importTransactions(
    $enrollment->id,
    file_get_contents('transactions.csv'),  // raw CSV content
    'transactions.csv',
);
echo $result->created . ' created, ' . $result->skippedDuplicates . ' duplicates' . PHP_EOL;
foreach ($result->errors as $error) {
    echo $error . PHP_EOL;                  // per-row problems, e.g. bad tax category
}
```

#### 4. Tax reports (DGFiP ledger outcomes)

```php
$reports = $client->eReporting()->listTaxReports(state: 'refused'); // new | sent | acknowledged | registered | refused | error
foreach ($reports as $report) {
    echo $report->state->value . ' — ' . ($report->refusalReason ?? 'ok') . PHP_EOL;
}

$report = $client->eReporting()->getTaxReport(5);
if ($report->state->isFailure()) {
    echo $report->refusalReason;            // e.g. CDV 301 details
}
```

#### 5. Revenue statistics & VAT rates

Read-only turnover aggregation over your e-reporting transactions — totals, a time
series and breakdowns by transaction type, counterparty country and state. All headline
figures are expressed in the most frequent currency in the range (`primaryCurrency`);
when more than one currency is present, `isMultiCurrency` is `true` and `byCurrency`
lists each one. Monetary values are returned as 4-decimal strings to preserve precision.

```php
$stats = $client->eReporting()->getRevenueStatistics(
    dateFrom: '2026-01-01',          // optional — defaults to 12 months ago (server-side)
    dateTo: new DateTimeImmutable(), // optional — defaults to today
    interval: 'month',               // 'day' | 'week' | 'month' (default 'month')
    transactionType: 'b2c_outbound', // optional filter (string or ComplianceTransactionType)
    state: 'submitted',              // optional filter (string or ComplianceTransactionState)
);

echo $stats->primaryCurrency;        // e.g. EUR
echo $stats->totals->total;          // e.g. 120000.0000
foreach ($stats->timeSeries as $bucket) {
    echo $bucket->bucket . ': ' . $bucket->total . PHP_EOL; // 2026-01: 10800.0000
}
foreach ($stats->byCountry as $row) {
    echo $row['country'] . ' → ' . $row['total'] . PHP_EOL; // 'unknown' groups NULL partners
}

// Canonical VAT rates & DGFiP tax categories for a reporting country — the valid
// `category` values for ComplianceLineTax (an `E` rate needs a VATEX comment).
$rates = $client->eReporting()->getVatRates('FR');
foreach ($rates->rates as $rate) {
    echo $rate->percent . '% (' . $rate->category . ')' . ($rate->requiresVatex ? ' — VATEX required' : '') . PHP_EOL;
}
```

#### 6. No access yet?

```php
// The one compliance endpoint that works WITHOUT the e-reporting feature —
// it files an activation request with the Taxora team. Identity defaults to
// the authenticated user; all parameters are optional context.
$client->eReporting()->requestEReportingAccess(
    company: 'Acme SARL',
    phone: '+33 1 23 45 67 89',
    message: 'We need Flux 10 e-reporting starting September.',
    language: 'fr',
);
```

`company()->get()` returns the raw company payload. New company limit fields are exposed as `api_rate_limit` and `vat_rate_limit`. The legacy `rate_limit` field may still appear temporarily for backward compatibility, but it should be treated as deprecated.

`vat()->validate()` returns a `VatResource` object that includes the canonical VAT number, status, requested company name echo, optional scoring data, and optional API error metadata. `state` remains backward-compatible and still carries the canonical business result, while `has_api_error`, `error_message`, and `next_api_recheck_at` expose technical provider failures without breaking existing integrations. The `score` reflects the overall confidence (higher is better), while `breakdown` provides an array of `ScoreBreakdown` objects describing every validation step, its score contribution, and any metadata (e.g. matched addresses or mismatched fields).

Need to plug in your own PSR-18 client or PSR-17 factories (e.g. to add logging or retries)?
Call the constructor directly or pass them as optional overrides to the factory:

```php
use GuzzleHttp\Client as GuzzleAdapter;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use Taxora\Sdk\TaxoraClientFactory;

$client = TaxoraClientFactory::create(
    apiKey: 'YOUR_X_API_KEY',
    http: new GuzzleAdapter(),
    requestFactory: new RequestFactory(),
    streamFactory: new StreamFactory()
);
```

---

## 🧩 Architecture

The SDK follows clean separation of concerns:

```
TaxoraClient
 ├── auth()     → AuthEndpoint     (login, refresh)
 ├── company()  → CompanyEndpoint  (company info)
 └── vat()      → VatEndpoint      (validate, history, search, certificate)
```

Each endpoint handles:

* Request signing with `x-api-key`
* Bearer token refresh if expired or unauthorized
* PSR-7 response parsing into DTOs

---

## 📦 DTOs

| Class             | Description                                                                                               |
| ----------------- | --------------------------------------------------------------------------------------------------------- |
| `VatResource`     | Represents a single VAT validation result (normalized VAT UID, state, score, breakdown, company data)    |
| `ScoreBreakdown`  | Scoring fragment with validation step name, score contribution, and metadata context for the decision     |
| `VatCollection`   | Iterable list of `VatResource` objects                                                                    |
| `Token`           | Auth token with expiry & type                                                                             |

Example:

```php
$dto = $client->vat()->validate('ATU12345678');
print_r($dto->toArray());

$retryCase = $client->vat()->validate('ATU44000001', 'Example GmbH');
if ($retryCase->state === \Taxora\Sdk\Enums\VatState::INVALID && $retryCase->has_api_error) {
    echo $retryCase->error_message.PHP_EOL;
    echo $retryCase->next_api_recheck_at.PHP_EOL;
}
```

---

## 🔄 Authentication Flow

1. **Login**

   ```php
   $client->auth()->login('email', 'password', device: 'my-server-01');
   // Passing device is optional; omitted value falls back to a generated host-based identifier.
   ```

   Need to authenticate with a technical `client_id` instead of an email?

   ```php
   $client->auth()->loginWithClientId('client_abc123', 'client-secret', device: 'integration-box');
   ```

   > Advanced: you can still pass `loginIdentifier: LoginIdentifier::CLIENT_ID` into `login()` if you prefer an explicit enum instead of the helper.

   → Stores and returns a `Token` DTO (valid for ~3600 seconds).

2. **Auto-refresh**
   The client automatically refreshes the token on `401` responses.

3. **Manual refresh (optional)**

   ```php
   $client->auth()->refresh();
   ```

4. **Token storage**
   By default, tokens are stored in memory.
   You can provide a PSR-16 cache adapter for persistence:

   ```php
   use Symfony\Component\Cache\Adapter\FilesystemAdapter;
   use Symfony\Component\Cache\Psr16Cache;
   use Taxora\Sdk\Http\Psr16TokenStorage;

   $cache = new Psr16Cache(new FilesystemAdapter());
   $storage = new Psr16TokenStorage($cache);
   $client = new TaxoraClient($http, $reqF, $strF, 'YOUR_KEY', $storage);
   ```

---

## 🤪 Testing

Run the test suite locally:

```bash
composer test
```

CI runs on **PHP 8.3**, **8.4**, and (soon) **8.5**, verifying:

* PHPUnit 12
* Psalm static analysis
* Code style checks

---

## 🗟️ Environments

| Environment | Base URL                       |
| ----------- | ------------------------------ |
| Sandbox     | `https://sandbox.taxora.io/v1` |
| Production  | `https://api.taxora.io/v1`     |

Need sandbox sample data? Known VAT UIDs with deterministic responses live in `tests/Fixtures/SandboxVatFixtures.php`.

Switch easily via the constructor:

```php
$client = new TaxoraClient(..., environment: Environment::PRODUCTION);
```

---

## 🚨 Error Handling

| Exception                 | When                                                                 |
| ------------------------- | -------------------------------------------------------------------- |
| `ValidationException`     | HTTP 422 — `getErrors()` holds the per-field messages                 |
| `AuthenticationException` | HTTP 401 — credentials rejected, or the token refresh failed          |
| `HttpException`           | Every other non-success status — `getStatusCode()` / `getResponseBody()` |
| `TimeoutException`        | A client-side wait gave up; the server-side job keeps running          |

**Exception messages are always short and safe to log.** A response body is
never used as the message: gateways in front of the API answer `502`/`503`/`504`
with a full HTML error page, and that page would otherwise land in every log
line and error mail of your application. The SDK uses the `message` / `error`
field of a JSON error body when there is one, and falls back to the status line
(`Taxora API request failed (HTTP 504 Gateway Timeout).`) otherwise. The
untouched body stays available via `HttpException::getResponseBody()`.

```php
try {
    $vat = $client->vat()->validate('ATU12345678');
} catch (ValidationException $e) {
    // 422 — bad input
    $errors = $e->getErrors();
} catch (HttpException $e) {
    // e.g. "Taxora VAT validation failed after 3 attempts (HTTP 504 Gateway Timeout)."
    $logger->warning($e->getMessage(), ['status' => $e->getStatusCode()]);
}
```

### Automatic retries

The SDK retries what never produced an answer from the API itself: **3 attempts,
with 500 ms and 1000 ms of backoff in between**. That covers

- **gateway failures (`502`, `503`, `504`)** — the infrastructure in front of the
  API, where a second attempt a moment later usually succeeds;
- **connection-level failures** — reset connections, client-side timeouts, DNS
  hiccups. Once the attempts are used up, the transport exception of your own
  HTTP client is rethrown unchanged, so nothing about its type or message changes
  for you;
- **`429 Too Many Requests`, but only with a `Retry-After`** — then the SDK waits
  exactly as long as the API asked instead of using its own backoff. Without that
  header a retry would only hammer a rate limit that is already tripped, so the
  error is surfaced immediately. A `Retry-After` longer than `maxRetryAfterMs`
  (10 s by default) also fails immediately rather than blocking your worker — the
  raw header stays readable via `HttpException::getRetryAfter()`.

Everything else fails on the first attempt, including any `5xx` the API produced
itself (a `500` is a bug, not a hiccup — repeating it just costs time).

Retries apply to read-only calls only — VAT validation, lookups, state, history,
search, certificate downloads, company and e-reporting reads, login and token
refresh. Calls that change state or cost quota (`certificatesBulkExport()`,
`smartEnrichment()->lookup()`, e-reporting enrollment/transaction writes) are
**never** retried automatically, because a gateway timeout does not tell us
whether the API already processed the request.

Once the attempts are used up you get one clean error:

```
Taxora VAT validation failed after 3 attempts (HTTP 504 Gateway Timeout).
```

Tune or disable it via `RetryPolicy`:

```php
use Taxora\Sdk\Http\RetryPolicy;

$client = TaxoraClientFactory::create(
    apiKey: 'YOUR_X_API_KEY',
    retryPolicy: new RetryPolicy(
        maxAttempts: 5,
        initialDelayMs: 250,
        retryableStatusCodes: [502, 503, 504],  // add or drop status codes
        respectRetryAfter: true,                // false = ignore the header entirely
        maxRetryAfterMs: 10_000,                // longest wait accepted from Retry-After
        retryOnNetworkErrors: true,             // false = surface transport errors at once
    ),
);

// or, for callers that do their own retrying (queue workers, cron jobs):
$client = TaxoraClientFactory::create(apiKey: '…', retryPolicy: RetryPolicy::disabled());
```

---

## ⚠️ Deprecations

So fresh there aren't even any deprecated features yet. Check back in a few months when we're on v47 and have made some regrettable decisions. 🎉

---

## 🪪 License

Licensed under the **MIT License** © 2025 [theconcept technologies](https://www.theconcept-technologies.com).
The SDK is open-source, but API usage requires a valid **Taxora subscription**.

---

## 🤝 Contributing

Contributions and pull requests are welcome!

* Follow PSR-12 coding style (`composer fix`).
* Run `composer test` before submitting a PR.
* Ensure new endpoints include DTOs + tests.

---

## 💬 Support

Need help or enterprise support?
📧 **[support@taxora.io](mailto:support@taxora.io)**
🌐 [https://taxora.io](https://taxora.io)
