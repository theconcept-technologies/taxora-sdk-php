# Changelog

Release notes for earlier versions are on the GitHub releases page.

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
