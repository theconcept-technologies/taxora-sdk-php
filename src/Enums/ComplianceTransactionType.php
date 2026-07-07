<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * DGFiP transaction type of a compliance (e-reporting) transaction.
 */
enum ComplianceTransactionType: string
{
    case B2C_OUTBOUND = 'b2c_outbound';
    case B2B_DOMESTIC_OUTBOUND = 'b2b_domestic_outbound';
    case B2B_DOMESTIC_INBOUND = 'b2b_domestic_inbound';
    case CROSSBORDER_OUTBOUND = 'crossborder_outbound';
    case CROSSBORDER_INBOUND = 'crossborder_inbound';
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
            self::B2C_OUTBOUND => 'Sale to a private person in the merchant country (DGFiP Flux 10.3)',
            self::B2B_DOMESTIC_OUTBOUND => 'Sale to a domestic business (DGFiP Flux 1, Phase 2)',
            self::B2B_DOMESTIC_INBOUND => 'Purchase from a domestic business (DGFiP Flux 1, Phase 2)',
            self::CROSSBORDER_OUTBOUND => 'Sale to a non-domestic business (DGFiP Flux 10.1)',
            self::CROSSBORDER_INBOUND => 'Purchase from a non-domestic supplier (DGFiP Flux 10.1)',
            self::UNKNOWN => 'Unknown transaction type',
        };
    }
}
