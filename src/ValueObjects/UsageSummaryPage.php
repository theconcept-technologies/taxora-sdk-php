<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * A paginated page of usage summaries (newest billing period first).
 *
 * @implements IteratorAggregate<int, UsageSummary>
 */
final readonly class UsageSummaryPage implements IteratorAggregate, Countable
{
    /**
     * @param list<UsageSummary> $rows
     */
    public function __construct(
        public array $rows,
        public int $currentPage,
        public int $perPage,
        public int $total,
        public int $lastPage,
    ) {
    }

    /** @param array<string,mixed> $payload the unwrapped page envelope: {data: [...], meta: {...}} */
    public static function fromArray(array $payload): self
    {
        $rows = [];
        if (isset($payload['data']) && is_array($payload['data'])) {
            foreach ($payload['data'] as $row) {
                if (is_array($row)) {
                    /** @var array<string,mixed> $row */
                    $rows[] = UsageSummary::fromArray($row);
                }
            }
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        return new self(
            rows: $rows,
            currentPage: (int) ($meta['current_page'] ?? 1),
            perPage: (int) ($meta['per_page'] ?? count($rows)),
            total: (int) ($meta['total'] ?? count($rows)),
            lastPage: (int) ($meta['last_page'] ?? 1),
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
