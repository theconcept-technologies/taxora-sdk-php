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

foreach ($vat->breakdown ?? [] as $step) {
    echo $step->stepName.' gave '.$step->scoreContribution.PHP_EOL;
}

// 3️⃣ Access company info
$company = $client->company()->get();

// 4️⃣ Export certificates (returns a VatCertificateExport object)
$export = $client->vat()->certificatesBulkExport('2024-01-01', '2024-12-31');
$pdfZip = $client->vat()->downloadBulkExport($export->exportId);
file_put_contents('certificates.zip', $pdfZip);
```

`vat()->validate()` returns a `VatResource` object that includes the canonical VAT number, status, requested company name echo, and optional scoring data. The `score` reflects the overall confidence (higher is better), while `breakdown` provides an array of `ScoreBreakdown` objects describing every validation step, its score contribution, and any metadata (e.g. matched addresses or mismatched fields).

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
