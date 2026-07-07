<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Immutable DTO for the e-reporting revenue statistics endpoint
 * (GET /compliance/revenue-statistics).
 *
 * Multi-currency: all headline figures (totals, timeSeries, byType, byCountry,
 * byState) are expressed in {@see $primaryCurrency} — the most frequent currency
 * in the range. When {@see $isMultiCurrency} is true, {@see $byCurrency} lists
 * every currency so callers can surface the rest. Monetary values stay as
 * 4-decimal strings to preserve precision.
 *
 * @phpstan-type TypeRow array{type: string, type_label: string, count: int, total: string}
 * @phpstan-type CountryRow array{country: string, count: int, total: string}
 * @phpstan-type StateRow array{state: string, state_label: string, count: int, total: string}
 * @phpstan-type CurrencyRow array{currency: string, count: int, subtotal: string, tax_amount: string, total: string}
 */
final readonly class RevenueStatistics
{
    /**
     * @param  list<RevenueTimeBucket>  $timeSeries
     * @param  list<array<string, mixed>>  $byType  each row: TypeRow
     * @param  list<array<string, mixed>>  $byCountry  each row: CountryRow
     * @param  list<array<string, mixed>>  $byState  each row: StateRow
     * @param  list<array<string, mixed>>  $byCurrency  each row: CurrencyRow
     */
    public function __construct(
        public ?string $dateFrom,
        public ?string $dateTo,
        public ?string $interval,
        public string $primaryCurrency,
        public bool $isMultiCurrency,
        public RevenueTotals $totals,
        public array $timeSeries,
        public array $byType,
        public array $byCountry,
        public array $byState,
        public array $byCurrency,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $range */
        $range = is_array($data['range'] ?? null) ? $data['range'] : [];

        return new self(
            dateFrom: isset($range['date_from']) && is_string($range['date_from']) ? $range['date_from'] : null,
            dateTo: isset($range['date_to']) && is_string($range['date_to']) ? $range['date_to'] : null,
            interval: isset($range['interval']) && is_string($range['interval']) ? $range['interval'] : null,
            primaryCurrency: (string) ($data['primary_currency'] ?? 'EUR'),
            isMultiCurrency: (bool) ($data['is_multi_currency'] ?? false),
            totals: RevenueTotals::fromArray(is_array($data['totals'] ?? null) ? $data['totals'] : []),
            timeSeries: array_map(
                static fn (array $row): RevenueTimeBucket => RevenueTimeBucket::fromArray($row),
                self::rows($data['time_series'] ?? null),
            ),
            byType: self::rows($data['by_type'] ?? null),
            byCountry: self::rows($data['by_country'] ?? null),
            byState: self::rows($data['by_state'] ?? null),
            byCurrency: self::rows($data['by_currency'] ?? null),
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
