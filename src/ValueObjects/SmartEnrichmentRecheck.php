<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * What a recheck started (or, for a dry run, would start) and what it costs. Every recheck counts
 * as a check like a new lookup (Deep Verify ×2), whether a VAT number is found or not.
 */
final readonly class SmartEnrichmentRecheck
{
    /**
     * @param list<int> $rows row indexes that are (or would be) rechecked
     * @param array<string,int> $skipped rows left out, by reason: resolved, no_vat_exists,
     *                                   limit_reached, cooldown, running, pending
     */
    public function __construct(
        public string $jobId,
        public array $rows = [],
        public int $checks = 0,
        public array $skipped = [],
        public bool $dryRun = false,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $rows = [];
        foreach (is_array($data['rows'] ?? null) ? $data['rows'] : [] as $index) {
            if (is_numeric($index)) {
                $rows[] = (int) $index;
            }
        }

        $skipped = [];
        foreach (is_array($data['skipped'] ?? null) ? $data['skipped'] : [] as $reason => $count) {
            if (is_string($reason) && is_numeric($count)) {
                $skipped[$reason] = (int) $count;
            }
        }

        return new self(
            jobId: (string) ($data['jobId'] ?? ''),
            rows: $rows,
            checks: is_numeric($data['checks'] ?? null) ? (int) $data['checks'] : 0,
            skipped: $skipped,
            dryRun: (bool) ($data['dryRun'] ?? false),
        );
    }
}
