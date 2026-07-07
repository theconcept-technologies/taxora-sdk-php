<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Immutable DTO for the Smart Enrichment statistics endpoint
 * (GET /smart-enrichment/statistics).
 *
 * Aggregated, read-only lookup statistics for the authenticated company: headline
 * totals, a time series (bucketed by the requested interval) and breakdowns by
 * submission source, outcome and confidence band. The server defaults to the last
 * 12 months and a monthly interval when no range is given.
 *
 * @phpstan-type SourceRow array{source: string, jobs: int, found: int}
 * @phpstan-type OutcomeRow array{outcome: string, count: int}
 * @phpstan-type ConfidenceRow array{bucket: 'high'|'medium'|'low', count: int}
 */
final readonly class SmartEnrichmentStatistics
{
    /**
     * @param  list<SmartEnrichmentTimeBucket>  $timeSeries
     * @param  list<array<string, mixed>>  $bySource  each row: SourceRow
     * @param  list<array<string, mixed>>  $byOutcome  each row: OutcomeRow
     * @param  list<array<string, mixed>>  $confidenceBuckets  each row: ConfidenceRow
     */
    public function __construct(
        public ?string $dateFrom,
        public ?string $dateTo,
        public ?string $interval,
        public SmartEnrichmentStatisticsTotals $totals,
        public array $timeSeries,
        public array $bySource,
        public array $byOutcome,
        public array $confidenceBuckets,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $range */
        $range = is_array($data['range'] ?? null) ? $data['range'] : [];

        return new self(
            dateFrom: isset($range['date_from']) && is_string($range['date_from']) ? $range['date_from'] : null,
            dateTo: isset($range['date_to']) && is_string($range['date_to']) ? $range['date_to'] : null,
            interval: isset($range['interval']) && is_string($range['interval']) ? $range['interval'] : null,
            totals: SmartEnrichmentStatisticsTotals::fromArray(is_array($data['totals'] ?? null) ? $data['totals'] : []),
            timeSeries: array_map(
                static fn (array $row): SmartEnrichmentTimeBucket => SmartEnrichmentTimeBucket::fromArray($row),
                self::rows($data['time_series'] ?? null),
            ),
            bySource: self::rows($data['by_source'] ?? null),
            byOutcome: self::rows($data['by_outcome'] ?? null),
            confidenceBuckets: self::rows($data['confidence_buckets'] ?? null),
        );
    }

    /**
     * Coerce a raw breakdown payload into a list of associative rows.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
