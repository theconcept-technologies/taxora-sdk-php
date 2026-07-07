<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Current billing-period Smart Enrichment quota usage (counted in successful matches),
 * plus recent monthly billing history for full transparency.
 */
final readonly class SmartEnrichmentUsage
{
    /**
     * @param list<SmartEnrichmentUsageHistoryEntry> $history
     */
    public function __construct(
        public string $period,
        public int $used,
        public int $included,
        public int $remaining,
        public int $overageCount,
        public float $overagePrice,
        public float $overageAmount = 0.0,
        public ?int $hardCap = null,
        public array $history = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $history = [];
        if (isset($data['history']) && is_array($data['history'])) {
            foreach ($data['history'] as $entry) {
                if (is_array($entry)) {
                    $history[] = SmartEnrichmentUsageHistoryEntry::fromArray($entry);
                }
            }
        }

        return new self(
            period: (string) ($data['period'] ?? ''),
            used: (int) ($data['used'] ?? 0),
            included: (int) ($data['included'] ?? 0),
            remaining: (int) ($data['remaining'] ?? 0),
            overageCount: (int) ($data['overageCount'] ?? 0),
            overagePrice: (float) ($data['overagePrice'] ?? 0),
            overageAmount: (float) ($data['overageAmount'] ?? 0),
            hardCap: isset($data['hardCap']) ? (int) $data['hardCap'] : null,
            history: $history,
        );
    }
}
