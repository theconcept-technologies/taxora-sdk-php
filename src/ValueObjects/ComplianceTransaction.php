<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\ComplianceTransactionState;
use Taxora\Sdk\Enums\ComplianceTransactionType;

/**
 * A recorded compliance (e-reporting) transaction. Monetary values are kept as
 * decimal strings exactly as returned by the API to preserve precision.
 */
final readonly class ComplianceTransaction
{
    public function __construct(
        public int $id,
        public ?int $companyId,
        public ?int $complianceEnrollmentId,
        public ?string $country,
        public ?string $regime,
        public ComplianceTransactionType $transactionType,
        public ?string $transactionTypeLabel,
        public ComplianceTransactionState $state,
        public ?string $stateLabel,
        public ?string $invoiceNumber,
        public ?string $invoiceDate,
        public ?string $dueDate,
        public ?string $currency,
        public string $subtotal,
        public string $taxAmount,
        public string $total,
        public ?string $counterpartyName,
        public ?string $counterpartyCountry,
        public ?string $counterpartyVatNumber,
        public bool $isPaid,
        public ?string $paidAt,
        public ?string $providerInvoiceId,
        public ?string $submissionError,
        public ?string $reportedAt,
        public ?ComplianceTaxReport $taxReport,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            companyId: isset($data['company_id']) ? (int) $data['company_id'] : null,
            complianceEnrollmentId: isset($data['compliance_enrollment_id']) ? (int) $data['compliance_enrollment_id'] : null,
            country: isset($data['country']) ? (string) $data['country'] : null,
            regime: isset($data['regime']) ? (string) $data['regime'] : null,
            transactionType: ComplianceTransactionType::fromValue($data['transaction_type'] ?? 'unknown'),
            transactionTypeLabel: isset($data['transaction_type_label']) ? (string) $data['transaction_type_label'] : null,
            state: ComplianceTransactionState::fromValue($data['state'] ?? 'unknown'),
            stateLabel: isset($data['state_label']) ? (string) $data['state_label'] : null,
            invoiceNumber: isset($data['invoice_number']) ? (string) $data['invoice_number'] : null,
            invoiceDate: isset($data['invoice_date']) ? (string) $data['invoice_date'] : null,
            dueDate: isset($data['due_date']) ? (string) $data['due_date'] : null,
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            subtotal: (string) ($data['subtotal'] ?? '0'),
            taxAmount: (string) ($data['tax_amount'] ?? '0'),
            total: (string) ($data['total'] ?? '0'),
            counterpartyName: isset($data['counterparty_name']) ? (string) $data['counterparty_name'] : null,
            counterpartyCountry: isset($data['counterparty_country']) ? (string) $data['counterparty_country'] : null,
            counterpartyVatNumber: isset($data['counterparty_vat_number']) ? (string) $data['counterparty_vat_number'] : null,
            isPaid: (bool) ($data['is_paid'] ?? false),
            paidAt: isset($data['paid_at']) ? (string) $data['paid_at'] : null,
            providerInvoiceId: isset($data['provider_invoice_id']) ? (string) $data['provider_invoice_id'] : null,
            submissionError: isset($data['submission_error']) ? (string) $data['submission_error'] : null,
            reportedAt: isset($data['reported_at']) ? (string) $data['reported_at'] : null,
            taxReport: is_array($data['tax_report'] ?? null) ? ComplianceTaxReport::fromArray($data['tax_report']) : null,
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
            updatedAt: isset($data['updated_at']) ? (string) $data['updated_at'] : null,
        );
    }
}
