<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Result of EReportingEndpoint::confirmCheckout(): the plan state after a
 * finished checkout. All fields except $active are null when the session is
 * unknown, belongs to another company or was not paid.
 */
final readonly class ComplianceSubscriptionState
{
    public function __construct(
        public bool $active,
        public ?string $country,
        public ?string $service,
        public ?string $plan,
        public ?string $status,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            active: (bool) ($data['active'] ?? false),
            country: isset($data['country']) ? strtoupper((string) $data['country']) : null,
            service: isset($data['service']) ? (string) $data['service'] : null,
            plan: isset($data['plan']) ? (string) $data['plan'] : null,
            status: isset($data['status']) ? (string) $data['status'] : null,
        );
    }
}
