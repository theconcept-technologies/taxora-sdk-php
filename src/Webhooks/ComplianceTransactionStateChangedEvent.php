<?php

declare(strict_types=1);

namespace Taxora\Sdk\Webhooks;

use Taxora\Sdk\Enums\ComplianceTransactionState;

/**
 * Typed `compliance.transaction.state_changed` webhook event. Sent for every real
 * change of a transaction — Taxora's own state (submitted, error, not_required)
 * and the provider's delivery state (accepted, refused, paid, …); never twice
 * for the same state and never for the transient `sending`.
 *
 * Fields added later (internal reference, previous states, buyer rejection,
 * errors, delivery check) default to null / false for older deliveries.
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
     * @param string|null $country lowercase ISO 3166-1 alpha-2 code, e.g. "no"
     * @param string|null $internalReference your own reference of the transaction
     * @param ComplianceTransactionState|null $previousState Taxora state before this change (null when unknown / first state)
     * @param string|null $previousProviderState provider state before this change
     * @param bool $buyerRejected the buyer refused the e-invoice — issue a credit note
     * @param string|null $rejectionReason the buyer's reason for the rejection, when given
     * @param string|null $submissionError why sending failed (state error)
     * @param string|null $deliveryCheck Peppol directory result, e.g. "not_reachable" when the state is not_required
     */
    public function __construct(
        public string $id,
        public int $timestamp,
        public int $transactionId,
        public ComplianceTransactionState $state,
        public ?string $providerState,
        public ?string $country,
        public ?string $invoiceNumber,
        public ?string $internalReference = null,
        public ?ComplianceTransactionState $previousState = null,
        public ?string $previousProviderState = null,
        public bool $buyerRejected = false,
        public ?string $rejectionReason = null,
        public ?string $submissionError = null,
        public ?string $deliveryCheck = null,
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
            internalReference: isset($object['internal_reference']) ? (string) $object['internal_reference'] : null,
            previousState: isset($object['previous_state']) ? ComplianceTransactionState::fromValue($object['previous_state']) : null,
            previousProviderState: isset($object['previous_provider_state']) ? (string) $object['previous_provider_state'] : null,
            buyerRejected: (bool) ($object['buyer_rejected'] ?? false),
            rejectionReason: isset($object['rejection_reason']) ? (string) $object['rejection_reason'] : null,
            submissionError: isset($object['submission_error']) ? (string) $object['submission_error'] : null,
            deliveryCheck: isset($object['delivery_check']) ? (string) $object['delivery_check'] : null,
        );
    }

    /** The buyer refused the e-invoice — correct it with a credit note. */
    public function isBuyerRejected(): bool
    {
        return $this->buyerRejected;
    }

    /** Nothing was sent because the buyer cannot receive e-invoices — send your PDF by e-mail yourself. */
    public function isNotRequired(): bool
    {
        return $this->state === ComplianceTransactionState::NOT_REQUIRED;
    }
}
