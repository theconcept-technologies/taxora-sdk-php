<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * A paginated page of Smart Enrichment lookup history, plus optional account-wide
 * stats tiles (independent of the current search/page filter).
 *
 * @implements IteratorAggregate<int, SmartEnrichmentHistoryRow>
 */
final readonly class SmartEnrichmentHistoryPage implements IteratorAggregate, Countable
{
    /**
     * @param list<SmartEnrichmentHistoryRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $currentPage,
        public int $perPage,
        public int $total,
        public int $lastPage,
        public ?SmartEnrichmentHistoryStats $stats = null,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $rows = [];
        if (isset($payload['data']) && is_array($payload['data'])) {
            foreach ($payload['data'] as $row) {
                if (is_array($row)) {
                    $rows[] = SmartEnrichmentHistoryRow::fromArray($row);
                }
            }
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        return new self(
            rows: $rows,
            currentPage: (int) ($meta['currentPage'] ?? 1),
            perPage: (int) ($meta['perPage'] ?? count($rows)),
            total: (int) ($meta['total'] ?? count($rows)),
            lastPage: (int) ($meta['lastPage'] ?? 1),
            stats: is_array($payload['stats'] ?? null) ? SmartEnrichmentHistoryStats::fromArray($payload['stats']) : null,
        );
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->rows);
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
