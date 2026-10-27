<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Billed usage of one feature (e.g. "ereporting_transaction") in one billing
 * period (YYYY-MM) — GET /billing/usage-summaries.
 */
final readonly class UsageSummary
{
    /**
     * @param string $billingPeriod YYYY-MM
     * @param string|null $billableAmount decimal string as returned by the API (e.g. "49.00"); null until priced
     * @param string|null $state e.g. "open" — free-form so new states don't break parsing
     * @param list<UsageEvent> $events only filled by BillingEndpoint::getUsageSummaries($period); empty in the list
     */
    public function __construct(
        public int $id,
        public ?int $companyId,
        public string $feature,
        public ?string $country,
        public ?string $regime,
        public string $billingPeriod,
        public int $eventCount,
        public ?string $billableAmount,
        public ?string $state,
        public ?string $stateLabel,
        public ?string $periodClosedAt,
        public ?string $billingTriggeredAt,
        public ?string $billedAt,
        public ?string $stripeInvoiceId,
        public ?string $stripeSubscriptionItemId,
        public ?string $billingNotes,
        public array $events,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $events = [];
        foreach (is_array($data['events'] ?? null) ? $data['events'] : [] as $event) {
            if (is_array($event)) {
                /** @var array<string,mixed> $event */
                $events[] = UsageEvent::fromArray($event);
            }
        }

        $amount = $data['billable_amount'] ?? null;

        return new self(
            id: (int) ($data['id'] ?? 0),
            companyId: isset($data['company_id']) ? (int) $data['company_id'] : null,
            feature: (string) ($data['feature'] ?? ''),
            country: isset($data['country']) ? (string) $data['country'] : null,
            regime: isset($data['regime']) ? (string) $data['regime'] : null,
            billingPeriod: (string) ($data['billing_period'] ?? ''),
            eventCount: (int) ($data['event_count'] ?? 0),
            billableAmount: is_string($amount) || is_int($amount) || is_float($amount) ? (string) $amount : null,
            state: isset($data['state']) ? (string) $data['state'] : null,
            stateLabel: isset($data['state_label']) ? (string) $data['state_label'] : null,
            periodClosedAt: isset($data['period_closed_at']) ? (string) $data['period_closed_at'] : null,
            billingTriggeredAt: isset($data['billing_triggered_at']) ? (string) $data['billing_triggered_at'] : null,
            billedAt: isset($data['billed_at']) ? (string) $data['billed_at'] : null,
            stripeInvoiceId: isset($data['stripe_invoice_id']) ? (string) $data['stripe_invoice_id'] : null,
            stripeSubscriptionItemId: isset($data['stripe_subscription_item_id']) ? (string) $data['stripe_subscription_item_id'] : null,
            billingNotes: isset($data['billing_notes']) ? (string) $data['billing_notes'] : null,
            events: $events,
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
            updatedAt: isset($data['updated_at']) ? (string) $data['updated_at'] : null,
        );
    }
}
