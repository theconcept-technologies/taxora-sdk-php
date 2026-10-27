<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * One billable usage event of a usage summary — only included by
 * BillingEndpoint::getUsageSummaries($period).
 */
final readonly class UsageEvent
{
    /**
     * @param string|null $resourceType what was billed, e.g. a compliance transaction
     */
    public function __construct(
        public int $id,
        public ?int $usageSummaryId,
        public string $feature,
        public ?string $country,
        public ?string $regime,
        public string $billingPeriod,
        public ?string $resourceType,
        public ?int $resourceId,
        public ?string $stripeUsageRecordId,
        public ?string $stripeReportedAt,
        public ?string $createdAt,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            usageSummaryId: isset($data['usage_summary_id']) ? (int) $data['usage_summary_id'] : null,
            feature: (string) ($data['feature'] ?? ''),
            country: isset($data['country']) ? (string) $data['country'] : null,
            regime: isset($data['regime']) ? (string) $data['regime'] : null,
            billingPeriod: (string) ($data['billing_period'] ?? ''),
            resourceType: isset($data['resource_type']) ? (string) $data['resource_type'] : null,
            resourceId: isset($data['resource_id']) ? (int) $data['resource_id'] : null,
            stripeUsageRecordId: isset($data['stripe_usage_record_id']) ? (string) $data['stripe_usage_record_id'] : null,
            stripeReportedAt: isset($data['stripe_reported_at']) ? (string) $data['stripe_reported_at'] : null,
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
        );
    }
}
