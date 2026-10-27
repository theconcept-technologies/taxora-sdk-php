<?php

declare(strict_types=1);

namespace Taxora\Sdk\Webhooks;

use Taxora\Sdk\Enums\ComplianceTransactionState;

/**
 * Typed `compliance.transaction.state_changed` webhook event:
 *
 * ```php
 * $event = WebhookSignature::constructEvent($rawBody, $header, $secret);
 * if ($event['event'] === WebhookEventType::COMPLIANCE_TRANSACTION_STATE_CHANGED->value) {
 *     $changed = ComplianceTransactionStateChangedEvent::fromEvent($event);
 *     $changed->transactionId; $changed->state; $changed->providerState;
 * }
 * ```
 */
final readonly class ComplianceTransactionStateChangedEvent
{
    /**
     * @param string $id delivery UUID (= Taxora-Webhook-Id header), stable across retries — dedupe on it
     * @param int $timestamp unix seconds when the event was created
     * @param string|null $providerState provider-side document state (e.g. sent, error, accepted, refused, paid)
     */
    public function __construct(
        public string $id,
        public int $timestamp,
        public int $transactionId,
        public ComplianceTransactionState $state,
        public ?string $providerState,
        public ?string $country,
        public ?string $invoiceNumber,
    ) {
    }

    /**
     * @param array{id: string, event: string, timestamp: int, data: array<string, mixed>} $event as returned by WebhookSignature::constructEvent()
     */
    public static function fromEvent(array $event): self
    {
        $object = WebhookEventObject::of($event, WebhookEventType::COMPLIANCE_TRANSACTION_STATE_CHANGED);

        return new self(
            id: $event['id'],
            timestamp: $event['timestamp'],
            transactionId: (int) ($object['id'] ?? 0),
            state: ComplianceTransactionState::fromValue($object['state'] ?? null),
            providerState: isset($object['provider_state']) ? (string) $object['provider_state'] : null,
            country: isset($object['country']) ? (string) $object['country'] : null,
            invoiceNumber: isset($object['invoice_number']) ? (string) $object['invoice_number'] : null,
        );
    }
}
