<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * One bucket of the Smart Enrichment time series (a day, ISO week, or month depending
 * on the requested interval). `bucket` is the period key, e.g. "2026-06-15",
 * "2026-24" (ISO week) or "2026-06".
 */
final readonly class SmartEnrichmentTimeBucket
{
    public function __construct(
        public string $bucket,
        public int $jobs,
        public int $items,
        public int $found,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            bucket: (string) ($data['bucket'] ?? ''),
            jobs: (int) ($data['jobs'] ?? 0),
            items: (int) ($data['items'] ?? 0),
            found: (int) ($data['found'] ?? 0),
        );
    }
}
