<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * One bookable plan of a country offering (GET /compliance/offerings). Fees are
 * net EUR amounts.
 */
final readonly class ComplianceOfferingPlan
{
    /**
     * @param list<mixed> $usageTiers usage tiers as configured server-side (raw)
     * @param bool $contactOnly contact-only plans cannot be booked via createCheckout()
     * @param bool $checkoutAvailable createCheckout() can be started for this plan right now
     * @param array<array-key,mixed>|null $requires prerequisite of the plan (e.g. France e-reporting), null when there is none
     * @param bool $eligible the company meets $requires
     */
    public function __construct(
        public string $key,
        public ?float $monthlyFee,
        public ?float $setupFee,
        public array $usageTiers,
        public mixed $volumeLimit,
        public bool $contactOnly,
        public bool $checkoutAvailable,
        public ?array $requires,
        public bool $eligible,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) ($data['key'] ?? ''),
            monthlyFee: isset($data['monthly_fee']) && is_numeric($data['monthly_fee']) ? (float) $data['monthly_fee'] : null,
            setupFee: isset($data['setup_fee']) && is_numeric($data['setup_fee']) ? (float) $data['setup_fee'] : null,
            usageTiers: is_array($data['usage_tiers'] ?? null) ? array_values($data['usage_tiers']) : [],
            volumeLimit: $data['volume_limit'] ?? null,
            contactOnly: (bool) ($data['contact_only'] ?? false),
            checkoutAvailable: (bool) ($data['checkout_available'] ?? false),
            requires: is_array($data['requires'] ?? null) ? $data['requires'] : null,
            eligible: (bool) ($data['eligible'] ?? false),
        );
    }
}
