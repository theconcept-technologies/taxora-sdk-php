<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * Submission state of a compliance (e-reporting) transaction.
 */
enum ComplianceTransactionState: string
{
    case PENDING = 'pending';
    case SENDING = 'sending';
    case SUBMITTED = 'submitted';
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

    /** No further transition will happen without another explicit submit. */
    public function isTerminal(): bool
    {
        return $this === self::SUBMITTED || $this === self::ERROR;
    }

    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Recorded locally, not yet submitted to the provider',
            self::SENDING => 'Submission to the provider in progress',
            self::SUBMITTED => 'Successfully submitted to the provider',
            self::ERROR => 'Submission failed (see submissionError)',
            self::UNKNOWN => 'Unknown state',
        };
    }
}
