<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * One month of Smart Enrichment overage billing history (full transparency:
 * how many matches were billed, for how much, and whether the amount has been
 * invoiced yet). `amount` stays a string to preserve monetary precision.
 */
final readonly class SmartEnrichmentUsageHistoryEntry
{
    public function __construct(
        public string $period,
        public int $matches,
        public string $amount,
        public string $state,                  // 'billed' | 'pending'
        public ?string $billingType = null,    // e.g. 'manual'
        public ?string $manualReference = null, // e.g. an invoice number
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            period: (string) ($data['period'] ?? ''),
            matches: (int) ($data['matches'] ?? 0),
            amount: (string) ($data['amount'] ?? '0.00'),
            state: (string) ($data['state'] ?? 'pending'),
            billingType: isset($data['billingType']) ? (string) $data['billingType'] : null,
            manualReference: isset($data['manualReference']) ? (string) $data['manualReference'] : null,
        );
    }
}
