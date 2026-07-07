<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Headline totals of the Smart Enrichment statistics: how many lookups (jobs) ran,
 * how many company records (items) they contained, how many resolved to a VAT number,
 * the resulting match rate (0–100) and the average confidence of the matches (0–100).
 */
final readonly class SmartEnrichmentStatisticsTotals
{
    public function __construct(
        public int $jobs,
        public int $items,
        public int $found,
        public float $foundRate,
        public float $avgConfidence,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            jobs: (int) ($data['jobs'] ?? 0),
            items: (int) ($data['items'] ?? 0),
            found: (int) ($data['found'] ?? 0),
            foundRate: (float) ($data['found_rate'] ?? 0),
            avgConfidence: (float) ($data['avg_confidence'] ?? 0),
        );
    }
}
