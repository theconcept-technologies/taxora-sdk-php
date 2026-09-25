<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * Document type of a compliance transaction: a regular invoice or a credit
 * note correcting an earlier invoice.
 */
enum ComplianceDocumentType: string
{
    case INVOICE = 'invoice';
    case CREDIT_NOTE = 'credit_note';
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
            self::INVOICE => 'Invoice',
            self::CREDIT_NOTE => 'Credit note (references the corrected invoice)',
            self::UNKNOWN => 'Unknown document type',
        };
    }
}
