<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\ComplianceDocumentType;
use Taxora\Sdk\Enums\ComplianceTransactionState;
use Taxora\Sdk\Enums\ComplianceTransactionType;

/**
 * A recorded compliance (e-reporting) transaction. Monetary values are kept as
 * decimal strings exactly as returned by the API to preserve precision.
 *
 * The trailing fields (buyer register id/address, document type, credit-note
 * reference, provider state, invoice lines) were added with e-invoicing
 * (Norway / Peppol) and default to null / [] when an older server omits them.
 */
final readonly class ComplianceTransaction
{
    /**
     * @param string|null $providerState latest provider-side delivery state (e.g. sent, accepted, refused, paid) — richer than $state for e-invoicing
     * @param list<array<string,mixed>> $invoiceLines the stored invoice lines in wire shape (invoice_lines_attributes / taxes_attributes)
     */
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
        public ?string $counterpartyRegisterId = null,
        public ?string $counterpartyAddress = null,
        public ?string $counterpartyCity = null,
        public ?string $counterpartyPostalcode = null,
        public ?string $counterpartyEmail = null,
        public ?string $buyerReference = null,
        public ?ComplianceDocumentType $documentType = null,
        public ?string $amendedNumber = null,
        public ?string $amendedDate = null,
        public ?string $providerState = null,
        public array $invoiceLines = [],
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
            counterpartyRegisterId: isset($data['counterparty_register_id']) ? (string) $data['counterparty_register_id'] : null,
            counterpartyAddress: isset($data['counterparty_address']) ? (string) $data['counterparty_address'] : null,
            counterpartyCity: isset($data['counterparty_city']) ? (string) $data['counterparty_city'] : null,
            counterpartyPostalcode: isset($data['counterparty_postalcode']) ? (string) $data['counterparty_postalcode'] : null,
            counterpartyEmail: isset($data['counterparty_email']) ? (string) $data['counterparty_email'] : null,
            buyerReference: isset($data['buyer_reference']) ? (string) $data['buyer_reference'] : null,
            documentType: isset($data['document_type']) ? ComplianceDocumentType::fromValue($data['document_type']) : null,
            amendedNumber: isset($data['amended_number']) ? (string) $data['amended_number'] : null,
            amendedDate: isset($data['amended_date']) ? (string) $data['amended_date'] : null,
            providerState: isset($data['provider_state']) ? (string) $data['provider_state'] : null,
            invoiceLines: self::invoiceLinesOf($data),
        );
    }

    /** The transaction is a credit note correcting an earlier invoice. */
    public function isCreditNote(): bool
    {
        return $this->documentType === ComplianceDocumentType::CREDIT_NOTE;
    }

    /**
     * provider_payload.invoice.invoice_lines_attributes, keeping only well-formed rows.
     *
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private static function invoiceLinesOf(array $data): array
    {
        $payload = $data['provider_payload'] ?? null;
        $invoice = is_array($payload) ? ($payload['invoice'] ?? null) : null;
        $lines = is_array($invoice) ? ($invoice['invoice_lines_attributes'] ?? null) : null;

        if (!is_array($lines)) {
            return [];
        }

        $result = [];
        foreach ($lines as $line) {
            if (is_array($line)) {
                /** @var array<string,mixed> $line */
                $result[] = $line;
            }
        }

        return $result;
    }
}
