<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * Lifecycle state of a DGFiP tax report (per-transaction ledger entry).
 */
enum ComplianceTaxReportState: string
{
    case NEW = 'new';
    case SENT = 'sent';
    case ACKNOWLEDGED = 'acknowledged';
    case REGISTERED = 'registered';
    case REFUSED = 'refused';
    case ERROR = 'error';
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

    /** Final state: the report will not change anymore. */
    public function isTerminal(): bool
    {
        return $this === self::REGISTERED || $this === self::REFUSED || $this === self::ERROR;
    }

    /** Accepted by DGFiP (CDV 300). */
    public function isSuccess(): bool
    {
        return $this === self::REGISTERED;
    }

    /** Rejected by DGFiP or a transmission error. */
    public function isFailure(): bool
    {
        return $this === self::REFUSED || $this === self::ERROR;
    }

    public function description(): string
    {
        return match ($this) {
            self::NEW => 'Created at the provider, waiting for the daily ledger batch',
            self::SENT => 'Ledger transmitted to the PPF',
            self::ACKNOWLEDGED => 'PPF acknowledged receipt of the ledger',
            self::REGISTERED => 'Accepted by DGFiP (CDV 300)',
            self::REFUSED => 'Rejected by DGFiP (CDV 301, see refusalReason)',
            self::ERROR => 'Transmission or PPF error',
            self::UNKNOWN => 'Unknown state',
        };
    }
}
