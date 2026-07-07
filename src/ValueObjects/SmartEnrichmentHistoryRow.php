<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\SmartEnrichmentStatus;

/**
 * One row of Smart Enrichment lookup history (compact summary; full detail via get($jobId)).
 */
final readonly class SmartEnrichmentHistoryRow
{
    public function __construct(
        public string $jobId,
        public SmartEnrichmentStatus $status,
        public bool $isBulk,
        public int $itemCount,
        public int $foundCount,
        public ?string $queryCompanyName,
        public ?string $queryCountry,
        public ?string $queryCity,
        public ?SmartEnrichmentInput $input,
        public ?SmartEnrichmentResource $result,
        public ?string $createdAt,
        public ?string $finishedAt,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $query = is_array($data['query'] ?? null) ? $data['query'] : [];
        $input = is_array($data['input'] ?? null) ? SmartEnrichmentInput::fromArray($data['input']) : null;
        $result = is_array($data['result'] ?? null) ? SmartEnrichmentResource::fromArray($data['result']) : null;

        return new self(
            jobId: (string) ($data['jobId'] ?? ''),
            status: SmartEnrichmentStatus::fromValue($data['status'] ?? 'unknown'),
            isBulk: (bool) ($data['isBulk'] ?? false),
            itemCount: (int) ($data['itemCount'] ?? 0),
            foundCount: (int) ($data['foundCount'] ?? 0),
            queryCompanyName: isset($query['companyName']) ? (string) $query['companyName'] : null,
            queryCountry: isset($query['country']) ? (string) $query['country'] : null,
            queryCity: isset($query['city']) ? (string) $query['city'] : null,
            input: $input,
            result: $result,
            createdAt: isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            finishedAt: isset($data['finishedAt']) ? (string) $data['finishedAt'] : null,
        );
    }
}
