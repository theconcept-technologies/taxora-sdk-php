<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * Format of a German e-invoice (EN 16931, § 14 UStG) — the `e_invoice_format`
 * transaction field. Germany only; other countries ignore it.
 *
 * A Leitweg-ID in `buyerReference` (public buyer, B2G) always makes it an
 * XRechnung sent over Peppol, whatever is passed here.
 */
enum ComplianceEInvoiceFormat: string
{
    /** Factur-X / ZUGFeRD EN 16931: PDF/A-3 with the embedded CII XML (server default). */
    case ZUGFERD = 'zugferd';
    /** XRechnung 3.0 (UBL XML). */
    case XRECHNUNG = 'xrechnung';

    public function description(): string
    {
        return match ($this) {
            self::ZUGFERD => 'ZUGFeRD / Factur-X EN 16931 (PDF/A-3 with embedded XML)',
            self::XRECHNUNG => 'XRechnung 3.0 (UBL XML)',
        };
    }
}
