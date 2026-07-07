<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\SmartEnrichmentStatus;

/**
 * A Smart Enrichment job — single lookup or bulk batch. Unifies the API's sync and async
 * responses: a confident single lookup comes back already resolved (one result, status
 * `found`); everything else comes back `processing` with a `jobId` to poll.
 */
final readonly class SmartEnrichmentJob
{
    /**
     * @param list<SmartEnrichmentResource> $results
     */
    public function __construct(
        public string $jobId,
        public SmartEnrichmentStatus $status,
        public ?int $itemCount = null,
        public ?int $completedCount = null,
        public ?int $foundCount = null,
        public array $results = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $results = [];

        if (isset($data['results']) && is_array($data['results'])) {
            // Bulk job: an array of per-item results.
            foreach ($data['results'] as $row) {
                if (is_array($row)) {
                    $results[] = SmartEnrichmentResource::fromArray($row);
                }
            }
        } elseif (isset($data['vatNumber']) || self::looksLikeItem($data['status'] ?? null)) {
            // Flat single response — the payload itself is the (only) result.
            $results[] = SmartEnrichmentResource::fromArray($data);
        }

        return new self(
            jobId: (string) ($data['jobId'] ?? ''),
            status: SmartEnrichmentStatus::fromValue($data['status'] ?? 'unknown'),
            itemCount: isset($data['itemCount']) ? (int) $data['itemCount'] : null,
            completedCount: isset($data['completedCount']) ? (int) $data['completedCount'] : null,
            foundCount: isset($data['foundCount']) ? (int) $data['foundCount'] : null,
            results: $results,
        );
    }

    public function isProcessing(): bool
    {
        return $this->status === SmartEnrichmentStatus::PROCESSING
            || $this->status === SmartEnrichmentStatus::QUEUED;
    }

    /** Convenience for single lookups: the first (or only) result, if any. */
    public function result(): ?SmartEnrichmentResource
    {
        return $this->results[0] ?? null;
    }

    private static function looksLikeItem(mixed $status): bool
    {
        return in_array(
            SmartEnrichmentStatus::fromValue($status),
            [
                SmartEnrichmentStatus::FOUND,
                SmartEnrichmentStatus::NO_VAT_EXISTS,
                SmartEnrichmentStatus::NOT_FOUND,
            ],
            true,
        );
    }
}
