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

// Where the number came from, and who it is registered to. `sourceUrl` is the page it was read
// from (usually the Impressum). `registeredOwner` is set when the tax authority has the VAT ID
// under a person rather than the trading name — a German e.K. — and confirmed it for them.
echo $result?->sourceUrl ?? '—';        // e.g. https://example.de/impressum
echo implode(', ', $result?->sourceUrls ?? []); // every page it was found on, main one first
echo $result?->registeredOwner ?? '—';  // e.g. Kevin Seibel

// The official VAT check behind a found number. `nameMatch` 'mismatch' means the register has the
// number under a different name — another company, unless `registeredOwner` is set (an e.K. filed
// under its owner): then write `officialName` / `officialAddress` into your own records.
if ($result?->nameMismatch()) {
    echo $result->officialName . ', ' . implode(' ', $result->officialAddress ?? []) . PHP_EOL;
}

// `taxNumber` is the company's LOCAL tax / business number — what domestic e-invoicing asks for.
// On `found` it is read off the confirmed VAT ID where that country's VAT ID contains it
// (`taxNumberSource` 'vat_id': FR SIREN, IT Partita IVA, ES NIF, PL NIP, BE, DK CVR, CZ IČO, …).
// Otherwise it is a number the company published itself ('company_website', e.g. a German
// Steuernummer shown under the VAT ID heading) — that one is not a VAT ID.
echo $result?->taxNumber ?? '—';        // e.g. 5260250995
echo $result?->taxNumberType ?? '—';    // e.g. pl_nip, fr_siren, de_steuernummer
echo $result?->taxNumberLabel ?? '—';   // e.g. NIP
echo $result?->taxNumberSource ?? '—';  // 'vat_id' | 'company_website'

// `not_found` doesn't always mean nothing turned up. When the research found a VAT number but
// the official check threw it out (e.g. the tax authority reports it as invalid), the number
// and the reason are in `rejectedCandidates` — no need to re-check it yourself.
foreach ($job->result()?->rejectedCandidates ?? [] as $candidate) {
    echo $candidate->vatNumber . ': ' . $candidate->reason->value . PHP_EOL; // e.g. vat_invalid
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

// Check again: search once more for rows without a usable answer (not_found, or a number
// registered to another company). Every recheck counts as a check like a new lookup
// (Deep Verify ×2), found or not. Max 3 per row, 7 days apart; no_vat_exists and found
// rows are never rechecked. Ask first what it would cost with a dry run:
$plan = $client->smartEnrichment()->recheck($bulk->jobId, dryRun: true);
echo count($plan->rows) . ' rows, ' . $plan->checks . ' checks' . PHP_EOL; // skipped: $plan->skipped
$started = $client->smartEnrichment()->recheck($bulk->jobId);           // all rows that qualify
// $client->smartEnrichment()->recheck($bulk->jobId, rows: [3, 7]);     // or just these rows
// Improved rows arrive via the `enrichment.updated` webhook; or poll:
$job = $client->smartEnrichment()->get($bulk->jobId);
echo $job->recheck?->isActive() ? 'still rechecking' : 'done';
echo $job->results[3]->recheckCount . '× rechecked, last ' . $job->results[3]->lastRecheckedAt . PHP_EOL;

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

### 🧾 Local tax numbers report

Every company you checked — single and bulk VAT checks and Smart Enrichment lookups — once, with
its **local tax number** (NIP, SIREN, Partita IVA, Steuernummer …), the number's national name and
where it comes from (`vat_id` = read off the confirmed VAT ID, `company_website` = published by the
company). All filters are optional.

```php
// Parsed rows (BOM dropped, cells trimmed, empty cells null)
$rows = $client->vat()->localTaxNumbers(
    dateFrom: '2026-10-01',            // optional (Y-m-d or DateTimeInterface)
    dateTo: '2026-10-31',              // optional
    countries: ['PL', 'IT'],           // optional (ISO-2)
    onlyWithTaxNumber: true,           // optional: skip companies without one
);
foreach ($rows as $row) {
    // e.g. Orlen S.A. PL 5260250995 (NIP, vat_id)
    echo "{$row->companyName} {$row->country} {$row->taxNumber} ({$row->taxNumberName}, {$row->taxNumberSource})" . PHP_EOL;
}
// $row->vatStatus: valid | invalid | unconfirmed | wrong_company | no_vat_id | not_found
// $row->source: vat_check | smart_enrichment; $row->checkId: the VAT check uuid or the lookup job id

// The raw CSV (UTF-8 with BOM) for a spreadsheet. Digit-only tax numbers carry a leading TAB so
// Excel keeps leading zeros; VatEndpoint::parseLocalTaxNumbersCsv() turns a saved file into rows.
file_put_contents('local-tax-numbers.csv', $client->vat()->localTaxNumbersCsv(countries: ['PL']));

// Counts for the same filters
$summary = $client->vat()->localTaxNumbersSummary(dateFrom: '2026-10-01');
echo "{$summary->withTaxNumber} of {$summary->companies} companies have a local tax number" . PHP_EOL;
```

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
    internalReference: 'ORDER-4711',        // optional, max 100: your own reference (order no., ERP id),
                                            // never sent to the buyer or the tax authority
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
$tx = $client->eReporting()->updateTransaction($tx->id, internalReference: ''); // empty string clears it
$tx = $client->eReporting()->submitTransaction($tx->id);
$client->eReporting()->deleteTransaction($obsoleteId);   // 204, only while pending

// Browse what has been recorded (filters optional, perPage capped at 100)
$page = $client->eReporting()->listTransactions(
    dateFrom: '2026-06-01',
    dateTo: '2026-06-30',
    state: 'pending',                       // pending | sending | submitted | error
    transactionType: 'b2c_outbound',
    search: 'ACME',                         // contains-match: invoice number, internal reference, buyer name
    internalReference: 'ORDER-4711',        // exact match on your internal reference
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
// line_quantity, line_price, tax_name, tax_percent, tax_category. Optional:
// internal_reference (your own reference per invoice, max 100 chars).
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
$frOnly  = $client->eReporting()->listTaxReports(country: 'FR');     // one country at a time
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
    complianceEnrollmentId: 12,      // optional: one enrollment = one country (e.g. France without Norway)
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

**Net and VAT per month and tax rate (CSV).** For reconciling the reported figures
with your VAT return — one row per month, currency and tax rate, computed from the
invoice lines. Semicolon-separated, columns
`month;currency;tax_category;tax_rate_percent;lines;net;vat;gross`. The bytes are
returned exactly as sent (nothing is stripped), so you can write them straight to a file:

```php
$csv = $client->eReporting()->exportRevenueTaxRatesCsv(
    dateFrom: '2026-01-01',          // optional — defaults to one year before dateTo
    dateTo: '2026-09-30',            // optional — defaults to today
    transactionType: null,           // optional, as in getRevenueStatistics()
    state: 'submitted',              // optional
    complianceEnrollmentId: 12,      // optional: one country only
);
file_put_contents('tax-rates.csv', $csv);
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
    service: 'e_reporting',                 // optional: e_reporting (default) | e_invoicing
    countries: ['FR'],                      // optional: ISO alpha-2 codes you need
);
```

### 🇳🇴 E-Invoicing Norway (EHF / Peppol)

Compliance services are booked **per country**: each enrollment covers one
country and one service — France offers e-reporting (and e-invoicing) via DGFiP
Flux 10 (`regime: dgfip_flux10`), Norway offers e-invoicing via EHF 3.0 =
Peppol BIS Billing 3.0 (`regime: peppol_bis3`). `$enrollment->service` tells you
which service an enrollment books (`ComplianceService::E_REPORTING` /
`E_INVOICING`). Billing is per country as well: Norway is charged a one-off setup
fee when the country is activated plus a price per document sent.

The company needs the e-invoicing service unlocked — otherwise enrolling fails
with an `HttpException` (status `403`, body code `service_not_active`); use
`requestEReportingAccess(service: 'e_invoicing', countries: ['NO'])` to ask for it.
Norwegian organisation numbers are validated locally (9 digits, modulus-11 check
digit) before any request; `Taxora\Sdk\Support\NorwegianOrgNumber` exposes the
same helpers (`normalize()`, `isValid()`, `toVatNumber()` → `NO923609016MVA`).

#### 1. Registry lookup → enrollment

```php
// Brønnøysund register lookup by org number (spaces / "NO…MVA" accepted → one
// result) or by name (search). 404 = nothing found; rate limit 30/minute.
$companies = $client->eReporting()->registryLookup('NO', '923 609 016');
$company   = $companies[0];                  // RegistryCompany

$enrollment = $client->eReporting()->createNorwayEnrollment(
    email: 'faktura@example.no',             // notification e-mail
    orgNumber: $company->orgNumber,          // also the Peppol id (scheme 0192)
    companyName: $company->companyName ?? 'Example AS',
    address: $company->address ?? 'Storgata 1',
    city: $company->city ?? 'Oslo',
    postalcode: $company->postalcode ?? '0155',
    vatRegistered: $company->vatRegistered,  // MVA-registered (default true)
    enterpriseRegister: $company->enterpriseRegister, // adds "Foretaksregisteret" to invoices
    reception: false,                        // true = also receive e-invoices over Peppol
);

echo $enrollment->service?->value;           // e_invoicing
echo $enrollment->regime;                    // peppol_bis3
```

#### 2. Can the buyer receive e-invoices? (Peppol lookup)

```php
use Taxora\Sdk\Enums\PeppolLookupStatus;

$peppol = $client->eReporting()->peppolLookup('NO', '974760673'); // scheme defaults to 0192

if ($peppol->status === PeppolLookupStatus::PENDING) {
    sleep(3);                                 // the directory resolves asynchronously — ask again
    $peppol = $client->eReporting()->peppolLookup('NO', '974760673');
}

if ($peppol->status->isSuccess()) {           // reachable: buyer is in ELMA → send EHF
    print_r($peppol->documentTypes);          // e.g. xml.ubl.invoice.bis3, xml.ubl.credit_note.bis3
}
```

#### 3. Send an invoice

```php
use Taxora\Sdk\ValueObjects\ComplianceInvoiceLine;
use Taxora\Sdk\ValueObjects\ComplianceLineTax;

$tx = $client->eReporting()->createTransaction(
    enrollmentId: $enrollment->id,
    transactionType: 'b2b_domestic_outbound',  // the only type for Norway
    invoiceNumber: 'NO-2026-0001',
    invoiceDate: '2026-10-01',
    dueDate: '2026-10-31',
    currency: 'NOK',
    subtotal: '1000.00',
    taxAmount: '250.00',
    total: '1250.00',
    invoiceLines: [
        new ComplianceInvoiceLine(
            description: 'Consulting',
            quantity: 10,
            price: 100,
            // Norwegian rates: getVatRates('NO') — 25/15/12 % are category S; AE = reverse charge
            taxes: [new ComplianceLineTax(name: 'MVA', percent: 25, category: 'S')],
        ),
    ],
    counterpartyName: 'Buyer AS',             // required for Norway
    counterpartyRegisterId: '974760673',      // buyer org number — required for Norway
    counterpartyVatNumber: 'NO974760673MVA',  // when the buyer is MVA-registered
    counterpartyAddress: 'Kirkegata 2',
    counterpartyCity: 'Bergen',
    counterpartyPostalcode: '5003',
    counterpartyEmail: 'ap@buyer.no',
    buyerReference: 'PO-4711',                // Peppol buyer reference (defaults to the org number)
    extraInfo: 'Thank you for your business.', // max 1000 chars; mandatory notes are added server-side
);

echo $tx->state->value;                       // pending / submitted / error
echo $tx->providerState;                      // delivery state: sent, accepted, refused, paid, …
```

#### 4. Send a credit note

```php
$credit = $client->eReporting()->createTransaction(
    enrollmentId: $enrollment->id,
    transactionType: 'b2b_domestic_outbound',
    invoiceNumber: 'NO-CN-2026-0001',
    invoiceDate: '2026-10-05',
    currency: 'NOK',
    subtotal: '1000.00',
    taxAmount: '250.00',
    total: '1250.00',
    invoiceLines: [/* the credited lines */],
    counterpartyName: 'Buyer AS',
    counterpartyRegisterId: '974760673',
    isCreditNote: true,
    amendedNumber: 'NO-2026-0001',            // the corrected invoice — required for credit notes
    amendedDate: '2026-10-01',
);

var_dump($credit->isCreditNote());            // true (documentType = ComplianceDocumentType::CREDIT_NOTE)

// Only the Norwegian transactions of one enrollment:
$page = $client->eReporting()->listTransactions(complianceEnrollmentId: $enrollment->id, country: 'NO');
```

#### 5. Attach your own invoice PDF

The structured e-invoice (EHF XML) is the legal document; you can send your own
PDF along as its visual representation. Pass `invoicePdf` to `createTransaction()`
/ `updateTransaction()` as a **file path, the raw PDF bytes or an already base64-encoded
PDF** — the SDK checks it is a PDF of at most 10 MB and base64-encodes it for the API.
Only accepted when the country is booked as e-invoicing (`422` otherwise).

```php
$tx = $client->eReporting()->createTransaction(
    /* … as above … */
    invoicePdf: '/var/invoices/NO-2026-0001.pdf',  // or file_get_contents(...), or base64
    invoicePdfFilename: 'NO-2026-0001.pdf',        // optional, max 120 chars (defaults to the file's basename)
);

$tx->invoicePdf?->filename;   // NO-2026-0001.pdf
$tx->invoicePdf?->size;       // bytes
$tx->invoicePdf?->attached;   // true once it was uploaded together with the e-invoice
```

#### 6. Buyer not on Peppol: `not_required`

Before sending, Taxora checks the Peppol directory. If the buyer **cannot receive
e-invoices**, nothing is sent and nothing is billed: the transaction ends in state
`not_required` (`ComplianceTransactionState::NOT_REQUIRED`, terminal) — send your
regular invoice instead. If the directory cannot answer yet, the transaction stays
`pending` and Taxora asks again automatically. In both cases `deliveryCheck` tells you why:

```php
use Taxora\Sdk\Enums\ComplianceTransactionState;
use Taxora\Sdk\Enums\PeppolLookupStatus;

$tx = $client->eReporting()->getTransaction($tx->id);

if ($tx->isNotRequired()) {                                   // state === NOT_REQUIRED
    // $tx->deliveryCheck->status === PeppolLookupStatus::NOT_REACHABLE
    sendRegularInvoice($tx->invoiceNumber);
} elseif ($tx->deliveryCheck?->status === PeppolLookupStatus::PENDING) {
    // still waiting for the directory — checked again automatically
}

// Filter for them:
$held = $client->eReporting()->listTransactions(state: ComplianceTransactionState::NOT_REQUIRED, country: 'NO');
```

#### 7. Download the legal e-invoice

Once a transaction is `submitted`, `documentUrl` is set and the legal document
(usually EHF / Peppol UBL XML) can be downloaded — exactly as it was sent to the
buyer. Taxora keeps an archived copy, so it stays available.

```php
if ($tx->hasDocument()) {
    $doc = $client->eReporting()->getTransactionDocument($tx->id);
    $doc->contentType;                  // e.g. application/xml
    $doc->filename;                     // from Content-Disposition (path parts stripped)
    $doc->saveTo('/archive/' . $doc->filename);
}
```

Not sent yet, or the provider is still preparing the document → `HttpException` with
status `409`; try again in a few minutes.

The invoice as a readable PDF (your attached PDF, or the provider's rendering of the
e-invoice) is available once `pdfUrl` is set:

```php
if ($tx->hasPdf()) {
    $pdf = $client->eReporting()->getTransactionPdf($tx->id);
    $pdf->contentType;                  // application/pdf
    $pdf->filename;                     // e.g. RE-2026-0201.pdf (falls back to invoice-{id}.pdf)
    $pdf->saveTo('/archive/' . $pdf->filename);
}
```

No PDF available (yet) → `HttpException` with status `409`.

### 🛒 Self-service plans (checkout)

Book a compliance plan without talking to sales. These calls work **without** an
active compliance service — they exist for companies that do not have it yet.

```php
$offerings = $client->eReporting()->getOfferings();

$no = $offerings->forCountry('NO');            // ComplianceOffering|null
foreach ($no?->plans ?? [] as $plan) {
    echo $plan->key . ': ' . $plan->monthlyFee . ' EUR/month + ' . $plan->setupFee . ' setup'
        . ($plan->checkoutAvailable ? '' : ' (not bookable right now)') . PHP_EOL;
}
$offerings->coverage;                          // every country Taxora reaches (bookable / on_request)
$offerings->hasAccess('FR', 'e_reporting');    // what is already unlocked, per country

// 1. Start a Stripe checkout and send the buyer to $session->url
$session = $client->eReporting()->createCheckout(country: 'NO', plan: 'standard');

// 2. After payment Stripe redirects to the Taxora portal (…?session_id=cs_…).
//    Confirm to activate right away (the payment webhook activates it too; idempotent):
$state = $client->eReporting()->confirmCheckout($session->id);
$state->active;                                // true once paid

// Invoices, payment method, cancellation — a short-lived Stripe billing portal link:
$url = $client->eReporting()->createBillingPortalSession();   // 404 HttpException when no plan was bought via Stripe
```

Unknown or contact-only plans, unmet prerequisites (e.g. Norway's plan requires
France e-reporting) and an already active plan answer `422` (`ValidationException`).

### 💶 Usage summaries (billing)

Read-only transparency over what is billed per feature and billing period
(e-reporting / e-invoicing transactions, Smart Enrichment checks):

```php
$page = $client->billing()->listUsageSummaries(page: 1, perPage: 25);   // newest period first
foreach ($page as $summary) {
    echo $summary->billingPeriod . ' ' . $summary->feature . ': '
        . $summary->eventCount . ' events, ' . ($summary->billableAmount ?? '–') . PHP_EOL;
}

// One period (YYYY-MM) with its individual usage events — 404 HttpException when there was no usage:
foreach ($client->billing()->getUsageSummaries('2026-09') as $summary) {
    foreach ($summary->events as $event) {
        echo $event->resourceType . ' #' . $event->resourceId . PHP_EOL;   // e.g. compliance_transaction #88
    }
}
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

## 🔐 Webhook Signatures

Taxora can sign every webhook request with HMAC-SHA256 (opt-in per endpoint: dashboard → **Developer → Webhooks**,
or `POST /v1/webhooks/{id}/signing/enable`). Signed requests carry
`Taxora-Signature: t=<unix>,v1=<hex>` and `Taxora-Webhook-Id: <delivery uuid>`.

```php
use Taxora\Sdk\Exceptions\WebhookSignatureVerificationException;
use Taxora\Sdk\Webhooks\WebhookSignature;

$rawBody = file_get_contents('php://input');   // Laravel: $request->getContent() — never re-encoded JSON

try {
    $event = WebhookSignature::constructEvent(
        $rawBody,
        $_SERVER['HTTP_TAXORA_SIGNATURE'] ?? null,  // Laravel: $request->header('Taxora-Signature')
        getenv('TAXORA_WEBHOOK_SECRET'),
        tolerance: 300,                             // seconds, default
    );
} catch (WebhookSignatureVerificationException $e) {
    http_response_code(400);                       // $e->getReason(): 'no_matching_signature', 'timestamp_outside_tolerance', …
    exit;
}

if ($event['event'] === 'vat.validated') {
    $vatUid = $event['data']['object']['vat_uid'];
}
http_response_code(200);
```

- `WebhookSignature::verify($rawBody, $header, $secret)` only checks (constant-time `hash_equals`, replay window).
- During a secret rotation Taxora sends one `v1=` per live secret — any match is accepted. `$secret` may also be an
  array to accept old and new secret on your side.
- `WebhookSignature::generateHeader($rawBody, $secret)` builds a valid header for testing your own handler.
- Dedupe on `$event['id']` (= `Taxora-Webhook-Id`), which stays the same across retries.

**Event types.** `WebhookEventType` lists every event name (`vat.validated`, `vat.state_changed`,
`compliance.transaction.state_changed`, `compliance.ledger.state_changed`, `enrichment.completed`,
`enrichment.updated`, `webhook.test`). The two compliance events have typed payload objects:

```php
use Taxora\Sdk\Webhooks\ComplianceLedgerStateChangedEvent;
use Taxora\Sdk\Webhooks\ComplianceTransactionStateChangedEvent;
use Taxora\Sdk\Webhooks\WebhookEventType;

switch (WebhookEventType::tryFrom($event['event'])) {
    case WebhookEventType::COMPLIANCE_TRANSACTION_STATE_CHANGED:
        $tx = ComplianceTransactionStateChangedEvent::fromEvent($event);
        // $tx->transactionId, $tx->state (ComplianceTransactionState), $tx->providerState (sent, error, accepted, …),
        // $tx->country, $tx->invoiceNumber — fetch the full transaction with getTransaction($tx->transactionId)
        break;
    case WebhookEventType::COMPLIANCE_LEDGER_STATE_CHANGED:
        $ledger = ComplianceLedgerStateChangedEvent::fromEvent($event);
        // $ledger->ledgerId, $ledger->state (e.g. registered, refused), $ledger->ledgerType, $ledger->ledgerDate
        break;
    default:
        // other / future events: ignore or handle the raw array
}
```

---

## 🧩 Architecture

The SDK follows clean separation of concerns:

```
TaxoraClient
 ├── auth()     → AuthEndpoint     (login, refresh)
 ├── company()  → CompanyEndpoint  (company info)
 ├── vat()      → VatEndpoint      (validate, history, search, certificate)
 ├── smartEnrichment() → SmartEnrichmentEndpoint (reverse VAT lookup)
 ├── eReporting()      → EReportingEndpoint      (e-reporting / e-invoicing, self-service plans)
 └── billing()         → BillingEndpoint         (usage summaries)
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
| `ComplianceEnrollment` | Compliance enrollment for one country + service (`service`, `regime`, `status`, provider account)    |
| `ComplianceTransaction` | Recorded e-reporting / e-invoicing transaction incl. buyer register data, `documentType`, credit-note reference and `providerState` |
| `ComplianceTaxReport` | DGFiP tax report outcome of a transaction ledger (France)                                             |
| `SireneLookupResult` | French SIRENE company data (prefill for `createEnrollment()`)                                         |
| `RegistryCompany` | Norwegian register (Brønnøysund) company data from `registryLookup()`                                     |
| `PeppolLookupResult` | Peppol directory answer (`status` reachable / not_reachable / pending, supported document types)      |
| `RevenueStatistics` | Aggregated turnover statistics over compliance transactions                                            |
| `ComplianceDeliveryCheck` / `ComplianceInvoicePdf` | E-invoicing delivery details on a transaction (`deliveryCheck`, `invoicePdf`) |
| `ComplianceTransactionDocument` | Legal e-invoice or invoice-PDF download (`content`, `contentType`, `filename`, `saveTo()`) |
| `ComplianceOfferings` | Self-service plans per country, coverage and access flags (`getOfferings()`)                         |
| `ComplianceCheckoutSession` / `ComplianceSubscriptionState` | Checkout start (`id`, `url`) and confirmation (`active`, `plan`, …) |
| `UsageSummary` / `UsageSummaryPage` | Billed usage per feature and period, with `UsageEvent`s for a single period            |
| `SmartEnrichmentJob` | Smart Enrichment (reverse VAT lookup) job with its results                                            |
| `LocalTaxNumberRow` | One company of the local tax numbers report (`taxNumber`, `taxNumberName`, `taxNumberSource`, `vatStatus`) |
| `LocalTaxNumberSummary` | `companies` / `withTaxNumber` counts of the local tax numbers report                              |

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

Compliance write calls (create / update / delete / submit / import transactions, enrollments)
by a user whose only role is **read-only** answer `403` with body code `read_only_role`
(`HttpException`; read it from `getResponseBody()`) — ask an admin of the company to make the change.

Adding countries or changing plans — creating an enrollment, `createCheckout()`, `confirmCheckout()` and
`createBillingPortalSession()` — is reserved for company **admins**: a user with the developer role gets
`403` with body `{"message": "…", "code": "admin_only"}` (`HttpException`) — only admins can add countries
or change plans.

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
