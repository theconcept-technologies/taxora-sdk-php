<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * The compliance service an enrollment books for its country. France offers
 * both (DGFiP Flux 10), Norway only e-invoicing (EHF / Peppol BIS 3.0).
 */
enum ComplianceService: string
{
    case E_REPORTING = 'e_reporting';
    case E_INVOICING = 'e_invoicing';
    case UNKNOWN = 'unknown';

    /** Tolerant mapping: unrecognized values become UNKNOWN instead of throwing. */
    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        foreach (self::cases() as $case) {
            if ($case->value === $normalized) {
                return $case;
            }
        }

        return self::UNKNOWN;
    }

    public function description(): string
    {
        return match ($this) {
            self::E_REPORTING => 'E-reporting: transaction data is reported to the tax authority',
            self::E_INVOICING => 'E-invoicing: structured invoices are delivered to the buyer (e.g. over Peppol)',
            self::UNKNOWN => 'Unknown service',
        };
    }
}
