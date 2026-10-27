<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Enums\ComplianceTransactionState;
use Taxora\Sdk\Webhooks\ComplianceLedgerStateChangedEvent;
use Taxora\Sdk\Webhooks\ComplianceTransactionStateChangedEvent;
use Taxora\Sdk\Webhooks\WebhookEventType;
use Taxora\Sdk\Webhooks\WebhookSignature;

final class ComplianceWebhookEventsTest extends TestCase
{
    private const SECRET = 'whsec_test';
    private const NOW = 1700000000;

    public function testTransactionStateChangedFromSignedPayload(): void
    {
        $body = (string) json_encode([
            'id' => '6f1c2f9e-0000-4000-8000-000000000001',
            'event' => 'compliance.transaction.state_changed',
            'timestamp' => self::NOW,
            'data' => ['object' => [
                'id' => 88,
                'state' => 'submitted',
                'provider_state' => 'sent',
                'country' => 'no',
                'invoice_number' => 'NO-2026-0001',
            ]],
        ]);
        $header = WebhookSignature::generateHeader($body, self::SECRET, self::NOW);
        $event = WebhookSignature::constructEvent($body, $header, self::SECRET, now: self::NOW);

        self::assertSame(WebhookEventType::COMPLIANCE_TRANSACTION_STATE_CHANGED, WebhookEventType::tryFrom($event['event']));

        $changed = ComplianceTransactionStateChangedEvent::fromEvent($event);
        self::assertSame('6f1c2f9e-0000-4000-8000-000000000001', $changed->id);
        self::assertSame(self::NOW, $changed->timestamp);
        self::assertSame(88, $changed->transactionId);
        self::assertSame(ComplianceTransactionState::SUBMITTED, $changed->state);
        self::assertSame('sent', $changed->providerState);
        self::assertSame('no', $changed->country);
        self::assertSame('NO-2026-0001', $changed->invoiceNumber);
    }

    public function testLedgerStateChanged(): void
    {
        $ledger = ComplianceLedgerStateChangedEvent::fromEvent([
            'id' => 'evt-ledger',
            'event' => 'compliance.ledger.state_changed',
            'timestamp' => self::NOW,
            'data' => ['object' => ['id' => 3, 'state' => 'registered', 'ledger_type' => 'transactions', 'ledger_date' => '2026-10-01']],
        ]);

        self::assertSame('evt-ledger', $ledger->id);
        self::assertSame(3, $ledger->ledgerId);
        self::assertSame('registered', $ledger->state);
        self::assertSame('transactions', $ledger->ledgerType);
        self::assertSame('2026-10-01', $ledger->ledgerDate);
    }

    public function testRejectsWrongEventTypeAndMissingObject(): void
    {
        try {
            ComplianceTransactionStateChangedEvent::fromEvent([
                'id' => 'x', 'event' => 'vat.validated', 'timestamp' => self::NOW, 'data' => ['object' => []],
            ]);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('compliance.transaction.state_changed', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        ComplianceLedgerStateChangedEvent::fromEvent([
            'id' => 'x', 'event' => 'compliance.ledger.state_changed', 'timestamp' => self::NOW, 'data' => [],
        ]);
    }

    public function testEventTypeCoversEveryBackendEvent(): void
    {
        self::assertSame([
            'vat.validated',
            'vat.state_changed',
            'compliance.ledger.state_changed',
            'compliance.transaction.state_changed',
            'enrichment.completed',
            'enrichment.updated',
            'webhook.test',
        ], array_column(WebhookEventType::cases(), 'value'));
        self::assertNull(WebhookEventType::tryFrom('some.future_event'));
    }
}
