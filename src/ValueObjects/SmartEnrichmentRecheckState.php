<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * "Check again" state of a lookup, as GET /smart-enrichment/{jobId} reports it: the rows that can
 * be rechecked right now, the rechecks of the last week, and the limits behind both.
 */
final readonly class SmartEnrichmentRecheckState
{
    /**
     * @param list<int> $eligible row indexes that can be rechecked now
     * @param array<int, array{state: string, resolved?: bool}> $runs by row index; state is
     *                                                                queued | running | finished | failed
     */
    public function __construct(
        public array $eligible = [],
        public array $runs = [],
        public int $maxPerRow = 3,
        public int $cooldownDays = 7,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $eligible = [];
        foreach (is_array($data['eligible'] ?? null) ? $data['eligible'] : [] as $index) {
            if (is_numeric($index)) {
                $eligible[] = (int) $index;
            }
        }

        $runs = [];
        foreach (is_array($data['runs'] ?? null) ? $data['runs'] : [] as $index => $run) {
            if (!is_numeric($index) || !is_array($run) || !is_string($run['state'] ?? null)) {
                continue;
            }
            $entry = ['state' => $run['state']];
            if (is_bool($run['resolved'] ?? null)) {
                $entry['resolved'] = $run['resolved'];
            }
            $runs[(int) $index] = $entry;
        }

        return new self(
            eligible: $eligible,
            runs: $runs,
            maxPerRow: is_numeric($data['maxPerRow'] ?? null) ? (int) $data['maxPerRow'] : 3,
            cooldownDays: is_numeric($data['cooldownDays'] ?? null) ? (int) $data['cooldownDays'] : 7,
        );
    }

    /** True while any recheck of this lookup is queued or running. */
    public function isActive(): bool
    {
        foreach ($this->runs as $run) {
            if ($run['state'] === 'queued' || $run['state'] === 'running') {
                return true;
            }
        }

        return false;
    }
}
