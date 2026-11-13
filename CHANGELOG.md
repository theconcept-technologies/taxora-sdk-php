# Changelog

Release notes for earlier versions are on the GitHub releases page.

## Unreleased

Germany: XRechnung needs the seller contact.

- `createGermanyEnrollment()` takes `contactPerson` and `phone`. They are the seller contact
  printed on every XRechnung (BT-41/42), which is always used for public buyers. **The API now
  requires both for Germany** and answers 422 without them.
- `createGermanyEnrollment()` takes an optional `iban` (+ `bic`). Every German invoice without its
  own payment method then goes out as SEPA credit transfer (58) to that account. Without it, an
  XRechnung needs `paymentMethod` on each invoice.
- New enum `ComplianceInvoicePaymentMeans` for `paymentMethod` on `createTransaction()` /
  `updateTransaction()`: `SEPA_CREDIT_TRANSFER`, `CREDIT_TRANSFER`, `SEPA_DIRECT_DEBIT`,
  `DIRECT_DEBIT`, `CREDIT_CARD`, `DEBIT_CARD`, `ONLINE_PAYMENT` (PayPal, Klarna, …), `CASH`,
  `CHEQUE`, `OTHER`. `ONLINE_PAYMENT` and `OTHER` need `paymentMethodText` (e.g. "PayPal").
  Older integer ids still work.
- New `paidAmount` on `createTransaction()` / `updateTransaction()`: for orders already paid at
  checkout. The e-invoice shows the amount as prepaid and nothing as due.

## 1.16.0

France enrollment: VAT regime and switching from another platform.

- `createEnrollment()`: new `vatRegime` (`EReportingEndpoint::VAT_REGIMES`:
  `reel_normal_mensuel`, `reel_normal_trimestriel`, `simplifie`, `franchise_en_base`).
  The API now requires it for France and answers 422 without it. Most foreign companies
  with a French VAT number file monthly: `reel_normal_mensuel`.
- `createEnrollment()`: new `migrationCode` for a company already registered with another
  approved platform (PA). Pass the one-time code that platform issued; it is used once
  and never returned.

## 1.15.0

E-invoicing for Germany and nine Peppol countries.

- `createGermanyEnrollment()`: Germany e-invoicing (EN 16931, ZUGFeRD / XRechnung 3.0);
  the VAT number (DE + 9 digits) is checked before any request.
- `createPeppolEnrollment()`: e-invoicing over Peppol BIS 3.0 for BE, NL, AT, IE, DK, SE,
  FI, EE and GB (`EReportingEndpoint::PEPPOL_COUNTRIES`), with the company's Peppol id
  (`peppolId`, "scheme:value" or the bare value under the country's usual scheme) and
  optional `reception`.
- `createTransaction()` / `updateTransaction()` / `createCreditNote()`: new Germany-only
  `eInvoiceFormat` (`ComplianceEInvoiceFormat`: `zugferd` default, `xrechnung`) and
  `delivery` (`ComplianceInvoiceDelivery`: `email` default, `download`). Refused for a
  buyer outside Germany. For Peppol countries the buyer's Peppol id goes into
  `counterpartyRegisterId`.
- New `Taxora\Sdk\Support\PeppolId` (`DEFAULT_SCHEMES`, `parse()`, `format()`,
  `isValid()`), mirroring the server's Peppol id parsing.
- `createEnrollment()` now points DE and the Peppol countries to their own methods.
- `Version::SDK` is 1.15.0 (the constant had stayed at 1.13.0 for the v1.14.0 tag).
