<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * E-invoicing: the seller's own invoice PDF sent along with an e-invoice as its
 * visual representation (`invoice_pdf` of a transaction). The structured
 * e-invoice stays the legal document — see EReportingEndpoint::getTransactionDocument().
 */
final readonly class ComplianceInvoicePdf
{
    /**
     * @param int|null $size size in bytes
     * @param bool $attached true once the PDF was uploaded to the provider together with the e-invoice
     */
    public function __construct(
        public ?string $filename,
        public ?int $size,
        public bool $attached,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            filename: isset($data['filename']) ? (string) $data['filename'] : null,
            size: isset($data['size']) && is_numeric($data['size']) ? (int) $data['size'] : null,
            attached: (bool) ($data['attached'] ?? false),
        );
    }

    /** @return array{filename:?string,size:?int,attached:bool} */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'size' => $this->size,
            'attached' => $this->attached,
        ];
    }
}
