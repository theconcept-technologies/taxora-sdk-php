<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\ComplianceService;

/**
 * A bookable country offering with the company's current state for it.
 */
final readonly class ComplianceOffering
{
    /**
     * @param string $country upper-case ISO 3166-1 alpha-2 code
     * @param bool $viaStripe the active plan was bought via self-service checkout (vs. granted by Taxora)
     * @param list<ComplianceOfferingPlan> $plans
     */
    public function __construct(
        public string $country,
        public ComplianceService $service,
        public string $currency,
        public bool $active,
        public bool $viaStripe,
        public ?string $plan,
        public ?string $status,
        public bool $enrolled,
        public array $plans,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $plans = [];
        foreach (is_array($data['plans'] ?? null) ? $data['plans'] : [] as $plan) {
            if (is_array($plan)) {
                /** @var array<string,mixed> $plan */
                $plans[] = ComplianceOfferingPlan::fromArray($plan);
            }
        }

        return new self(
            country: strtoupper((string) ($data['country'] ?? '')),
            service: ComplianceService::fromValue($data['service'] ?? null),
            currency: (string) ($data['currency'] ?? 'EUR'),
            active: (bool) ($data['active'] ?? false),
            viaStripe: (bool) ($data['via_stripe'] ?? false),
            plan: isset($data['plan']) ? (string) $data['plan'] : null,
            status: isset($data['status']) ? (string) $data['status'] : null,
            enrolled: (bool) ($data['enrolled'] ?? false),
            plans: $plans,
        );
    }

    /** The plan with the given key, or null. */
    public function findPlan(string $key): ?ComplianceOfferingPlan
    {
        foreach ($this->plans as $plan) {
            if ($plan->key === $key) {
                return $plan;
            }
        }

        return null;
    }
}
