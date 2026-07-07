<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Account-wide Smart Enrichment history tiles: total lookups ever run and how many
 * of them resolved at least one VAT number. Stable regardless of the current
 * search/page filter.
 */
final readonly class SmartEnrichmentHistoryStats
{
    public function __construct(
        public int $total,
        public int $found,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            total: (int) ($data['total'] ?? 0),
            found: (int) ($data['found'] ?? 0),
        );
    }
}
