<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * One bucket of the revenue time series (a day, ISO week, or month depending on
 * the requested interval). `bucket` is the period key, e.g. "2026-01-15",
 * "2026-03" (ISO week as "%x-%v") or "2026-01".
 */
final readonly class RevenueTimeBucket
{
    public function __construct(
        public string $bucket,
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
            bucket: (string) ($data['bucket'] ?? ''),
            count: (int) ($data['count'] ?? 0),
            subtotal: (string) ($data['subtotal'] ?? '0.0000'),
            taxAmount: (string) ($data['tax_amount'] ?? '0.0000'),
            total: (string) ($data['total'] ?? '0.0000'),
        );
    }
}
