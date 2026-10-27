<?php

declare(strict_types=1);

namespace Taxora\Sdk\Webhooks;

/**
 * Typed `compliance.ledger.state_changed` webhook event: a DGFiP ledger (the
 * aggregated daily e-reporting batch, France) changed state.
 *
 * ```php
 * $ledger = ComplianceLedgerStateChangedEvent::fromEvent($event);
 * $ledger->ledgerId; $ledger->state; $ledger->ledgerDate;
 * ```
 */
final readonly class ComplianceLedgerStateChangedEvent
{
    /**
     * @param string $id delivery UUID (= Taxora-Webhook-Id header), stable across retries — dedupe on it
     * @param int $timestamp unix seconds when the event was created
     * @param string $state ledger state as sent by the API (e.g. registered, refused)
     * @param string|null $ledgerDate Y-m-d
     */
    public function __construct(
        public string $id,
        public int $timestamp,
        public int $ledgerId,
        public string $state,
        public ?string $ledgerType,
        public ?string $ledgerDate,
    ) {
    }

    /**
     * @param array{id: string, event: string, timestamp: int, data: array<string, mixed>} $event as returned by WebhookSignature::constructEvent()
     */
    public static function fromEvent(array $event): self
    {
        $object = WebhookEventObject::of($event, WebhookEventType::COMPLIANCE_LEDGER_STATE_CHANGED);

        return new self(
            id: $event['id'],
            timestamp: $event['timestamp'],
            ledgerId: (int) ($object['id'] ?? 0),
            state: (string) ($object['state'] ?? ''),
            ledgerType: isset($object['ledger_type']) ? (string) $object['ledger_type'] : null,
            ledgerDate: isset($object['ledger_date']) ? (string) $object['ledger_date'] : null,
        );
    }
}
