<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\ComplianceTaxReportState;

/**
 * A DGFiP tax report: the per-transaction entry of the aggregated daily ledger
 * sent to the PPF. Monetary values are kept as decimal strings exactly as
 * returned by the API to preserve precision.
 */
final readonly class ComplianceTaxReport
{
    public function __construct(
        public int $id,
        public ?int $companyId,
        public ?int $complianceTransactionId,
        public ?int $ledgerId,
        public ?string $country,
        public ?string $regime,
        public ComplianceTaxReportState $state,
        public ?string $stateLabel,
        public bool $isTerminal,
        public ?string $providerTaxReportId,
        public ?string $providerLedgerId,
        public string $baseAmount,
        public string $taxAmount,
        public string $totalAmount,
        public ?string $refusalReason,
        public ?string $registeredAt,
        public ?string $refusedAt,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $state = ComplianceTaxReportState::fromValue($data['state'] ?? 'unknown');

        return new self(
            id: (int) ($data['id'] ?? 0),
            companyId: isset($data['company_id']) ? (int) $data['company_id'] : null,
            complianceTransactionId: isset($data['compliance_transaction_id']) ? (int) $data['compliance_transaction_id'] : null,
            ledgerId: isset($data['ledger_id']) ? (int) $data['ledger_id'] : null,
            country: isset($data['country']) ? (string) $data['country'] : null,
            regime: isset($data['regime']) ? (string) $data['regime'] : null,
            state: $state,
            stateLabel: isset($data['state_label']) ? (string) $data['state_label'] : null,
            isTerminal: (bool) ($data['is_terminal'] ?? $state->isTerminal()),
            providerTaxReportId: isset($data['provider_tax_report_id']) ? (string) $data['provider_tax_report_id'] : null,
            providerLedgerId: isset($data['provider_ledger_id']) ? (string) $data['provider_ledger_id'] : null,
            baseAmount: (string) ($data['base_amount'] ?? '0'),
            taxAmount: (string) ($data['tax_amount'] ?? '0'),
            totalAmount: (string) ($data['total_amount'] ?? '0'),
            refusalReason: isset($data['refusal_reason']) ? (string) $data['refusal_reason'] : null,
            registeredAt: isset($data['registered_at']) ? (string) $data['registered_at'] : null,
            refusedAt: isset($data['refused_at']) ? (string) $data['refused_at'] : null,
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
            updatedAt: isset($data['updated_at']) ? (string) $data['updated_at'] : null,
        );
    }
}
