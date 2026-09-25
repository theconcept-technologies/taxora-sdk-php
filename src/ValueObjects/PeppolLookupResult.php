<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\PeppolLookupStatus;

/**
 * Result of a Peppol directory lookup: can the participant (e.g. a Norwegian
 * buyer, by organisation number) receive e-invoices over Peppol?
 *
 * `reachable` is null while the lookup is still PENDING — poll again after a
 * few seconds. Definite answers are cached server-side for 24h.
 */
final readonly class PeppolLookupResult
{
    /**
     * @param list<string> $documentTypes e.g. "xml.ubl.invoice.bis3", "xml.ubl.credit_note.bis3"
     */
    public function __construct(
        public PeppolLookupStatus $status,
        public ?bool $reachable,
        public ?string $country,
        public ?string $scheme,
        public ?string $id,
        public array $documentTypes,
        public ?string $transportTypeCode,
        public ?string $checkedAt,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $documentTypes = [];
        if (isset($data['document_types']) && is_array($data['document_types'])) {
            foreach ($data['document_types'] as $type) {
                if (is_scalar($type)) {
                    $documentTypes[] = (string) $type;
                }
            }
        }

        return new self(
            status: PeppolLookupStatus::fromValue($data['status'] ?? 'unknown'),
            reachable: isset($data['reachable']) ? (bool) $data['reachable'] : null,
            country: isset($data['country']) ? (string) $data['country'] : null,
            scheme: isset($data['scheme']) ? (string) $data['scheme'] : null,
            id: isset($data['id']) ? (string) $data['id'] : null,
            documentTypes: $documentTypes,
            transportTypeCode: isset($data['transport_type_code']) ? (string) $data['transport_type_code'] : null,
            checkedAt: isset($data['checked_at']) ? (string) $data['checked_at'] : null,
        );
    }

    /** The participant advertises the given Peppol document type. */
    public function supportsDocumentType(string $documentType): bool
    {
        return in_array($documentType, $this->documentTypes, true);
    }
}
