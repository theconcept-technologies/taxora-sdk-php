<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Aggregated turnover totals for an e-reporting period.
 *
 * Monetary values are kept as 4-decimal strings exactly as returned by the API
 * (no float conversion) to avoid precision loss.
 */
final readonly class RevenueTotals
{
    public function __construct(
        public int $count,
        public string $subtotal,
        public string $taxAmount,
        public string $total,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            count: (int) ($data['count'] ?? 0),
            subtotal: (string) ($data['subtotal'] ?? '0.0000'),
            taxAmount: (string) ($data['tax_amount'] ?? '0.0000'),
            total: (string) ($data['total'] ?? '0.0000'),
        );
    }
}
