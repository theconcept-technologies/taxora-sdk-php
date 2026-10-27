<?php

declare(strict_types=1);

namespace Taxora\Sdk\Webhooks;

/**
 * The `event` names Taxora sends. Compare against `$event['event']` from
 * {@see WebhookSignature::constructEvent()}; tolerant parsing via tryFrom()
 * keeps new event types from breaking your handler.
 */
enum WebhookEventType: string
{
    case VAT_VALIDATED = 'vat.validated';
    case VAT_STATE_CHANGED = 'vat.state_changed';
    /** A DGFiP ledger (France e-reporting) changed state — {@see ComplianceLedgerStateChangedEvent}. */
    case COMPLIANCE_LEDGER_STATE_CHANGED = 'compliance.ledger.state_changed';
    /** A compliance transaction changed state (sent, error, …) — {@see ComplianceTransactionStateChangedEvent}. */
    case COMPLIANCE_TRANSACTION_STATE_CHANGED = 'compliance.transaction.state_changed';
    case ENRICHMENT_COMPLETED = 'enrichment.completed';
    /** A row of an already completed enrichment changed afterwards (follow-up research). */
    case ENRICHMENT_UPDATED = 'enrichment.updated';
    /** Sent by "Send test event" in the dashboard / POST /v1/webhooks/{id}/test. */
    case WEBHOOK_TEST = 'webhook.test';
}
