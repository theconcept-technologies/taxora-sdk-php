<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * The company's e-invoicing allowance: one pool over all e-invoicing countries,
 * the current month and earlier ones.
 */
final readonly class EInvoicingQuota
{
    /**
     * @param string $billing how documents beyond the allowance are paid: "subscription" (on the plan's invoice) or "invoice" (invoiced separately)
     * @param int $included documents included per month
     * @param list<array{up_to: int|null, unit_price: string}> $tiers price ladder (unit prices in net EUR)
     * @param list<string> $countries e-invoicing countries in the pool
     * @param string|null $monthlyFee agreed monthly base fee (net EUR), null when none
     * @param list<EInvoicingQuotaPeriod> $history earlier months, newest first
     */
    public function __construct(
        public string $billing,
        public ?string $plan,
        public string $currency,
        public int $included,
        public array $tiers,
        public array $countries,
        public ?string $monthlyFee,
        public EInvoicingQuotaPeriod $current,
        public array $history = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $tiers = [];
        foreach ((array) ($data['tiers'] ?? []) as $tier) {
            if (is_array($tier)) {
                $tiers[] = [
                    'up_to' => isset($tier['up_to']) ? (int) $tier['up_to'] : null,
                    'unit_price' => (string) ($tier['unit_price'] ?? '0'),
                ];
            }
        }

        $history = [];
        foreach ((array) ($data['history'] ?? []) as $row) {
            if (is_array($row)) {
                /** @var array<string,mixed> $row */
                $history[] = EInvoicingQuotaPeriod::fromArray($row);
            }
        }

        /** @var array<string,mixed> $current */
        $current = is_array($data['current'] ?? null) ? $data['current'] : [];

        return new self(
            billing: (string) ($data['billing'] ?? 'invoice'),
            plan: isset($data['plan']) ? (string) $data['plan'] : null,
            currency: (string) ($data['currency'] ?? 'EUR'),
            included: (int) ($data['included'] ?? 0),
            tiers: $tiers,
            countries: array_values(array_map('strval', (array) ($data['countries'] ?? []))),
            monthlyFee: isset($data['monthly_fee']) ? (string) $data['monthly_fee'] : null,
            current: EInvoicingQuotaPeriod::fromArray($current),
            history: $history,
        );
    }
}
