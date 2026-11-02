<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * One month of the e-invoicing allowance: documents included, used and still
 * free, the documents beyond the allowance and what is due for them (net EUR).
 */
final readonly class EInvoicingQuotaPeriod
{
    /**
     * @param string $period billing period, YYYY-MM
     * @param string $amount net EUR due for documents beyond the allowance
     * @param string|null $monthlyFee agreed monthly base fee (net EUR) for this month, null when none
     * @param string $total net EUR due for the month: base fee + documents beyond the allowance
     * @param string $state open | included | pending_billing | billed
     * @param array<string,int> $byCountry documents used per country code
     * @param string|null $manualReference our invoice number once invoiced
     */
    public function __construct(
        public string $period,
        public int $used,
        public int $included,
        public int $freeUsed,
        public int $freeRemaining,
        public int $billableUnits,
        public string $amount,
        public ?string $monthlyFee,
        public string $total,
        public string $state,
        public array $byCountry = [],
        public ?string $billedAt = null,
        public ?string $manualReference = null,
        public ?string $monthlyFeeReference = null,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $byCountry = [];
        foreach ((array) ($data['by_country'] ?? []) as $row) {
            if (is_array($row) && isset($row['country'])) {
                $byCountry[(string) $row['country']] = (int) ($row['used'] ?? 0);
            }
        }

        return new self(
            period: (string) ($data['period'] ?? ''),
            used: (int) ($data['used'] ?? 0),
            included: (int) ($data['included'] ?? 0),
            freeUsed: (int) ($data['free_used'] ?? 0),
            freeRemaining: (int) ($data['free_remaining'] ?? 0),
            billableUnits: (int) ($data['billable_units'] ?? 0),
            amount: (string) ($data['amount'] ?? '0.00'),
            monthlyFee: isset($data['monthly_fee']) ? (string) $data['monthly_fee'] : null,
            total: (string) ($data['total'] ?? '0.00'),
            state: (string) ($data['state'] ?? 'open'),
            byCountry: $byCountry,
            billedAt: isset($data['billed_at']) ? (string) $data['billed_at'] : null,
            manualReference: isset($data['manual_reference']) ? (string) $data['manual_reference'] : null,
            monthlyFeeReference: isset($data['monthly_fee_reference']) ? (string) $data['monthly_fee_reference'] : null,
        );
    }
}
