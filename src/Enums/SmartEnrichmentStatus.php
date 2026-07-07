<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * Status of a Smart Enrichment (reverse VAT lookup) result or job.
 *
 * Item-level: found / no_vat_exists / not_found / processing.
 * Job-level:  queued / processing / completed / failed.
 *
 * Note: `no_vat_exists` means the company was identified but legitimately has no
 * VAT/UID number (common for purely-domestic German firms) — a definitive answer,
 * not a failure, and not billable.
 */
enum SmartEnrichmentStatus: string
{
    case FOUND = 'found';
    case NO_VAT_EXISTS = 'no_vat_exists';
    case NOT_FOUND = 'not_found';
    case PROCESSING = 'processing';
    case QUEUED = 'queued';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
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
            self::FOUND => 'A VAT number was resolved and confirmed',
            self::NO_VAT_EXISTS => 'The company was identified but has no VAT number',
            self::NOT_FOUND => 'No sufficiently-confident match could be resolved',
            self::PROCESSING => 'The lookup is still being processed',
            self::QUEUED => 'The job is queued',
            self::COMPLETED => 'The job has completed',
            self::FAILED => 'The job failed',
            self::UNKNOWN => 'Unknown status',
        };
    }
}
