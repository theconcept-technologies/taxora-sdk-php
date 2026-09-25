<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * Outcome of a Peppol directory lookup. The directory resolves asynchronously:
 * PENDING means "ask again in a few seconds".
 */
enum PeppolLookupStatus: string
{
    case REACHABLE = 'reachable';
    case NOT_REACHABLE = 'not_reachable';
    case PENDING = 'pending';
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

    /** A definite answer — polling again will not change it (the server caches it for 24h). */
    public function isTerminal(): bool
    {
        return $this === self::REACHABLE || $this === self::NOT_REACHABLE;
    }

    /** The participant is registered on Peppol and can receive e-invoices. */
    public function isSuccess(): bool
    {
        return $this === self::REACHABLE;
    }

    public function description(): string
    {
        return match ($this) {
            self::REACHABLE => 'Registered on Peppol — can receive e-invoices',
            self::NOT_REACHABLE => 'Not registered on Peppol',
            self::PENDING => 'Directory lookup still in progress — retry in a few seconds',
            self::UNKNOWN => 'Unknown status',
        };
    }
}
