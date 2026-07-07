<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\SmartEnrichmentStatus;

/**
 * Result of resolving one reverse-VAT lookup (company name + address → VAT + confidence).
 *
 * Mirrors the API response item shape (camelCase keys).
 */
final readonly class SmartEnrichmentResource
{
    public function __construct(
        public SmartEnrichmentStatus $status,
        public ?string $vatNumber = null,
        public ?string $vatType = null,            // 'ust_idnr' | 'steuernummer' | 'vat_id' | ...
        public ?float $confidence = null,          // 0–100
        public ?string $matchedCompanyName = null,
        public ?string $matchedAddress = null,     // official/registered address of the match
        public ?string $country = null,
        public ?string $source = null,             // 'corpus' | 'registry:fr' | 'ai_web' | ...
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            status: SmartEnrichmentStatus::fromValue($data['status'] ?? 'unknown'),
            vatNumber: isset($data['vatNumber']) ? (string) $data['vatNumber'] : null,
            vatType: isset($data['vatType']) ? (string) $data['vatType'] : null,
            confidence: isset($data['confidence']) ? (float) $data['confidence'] : null,
            matchedCompanyName: isset($data['matchedCompanyName']) ? (string) $data['matchedCompanyName'] : null,
            matchedAddress: isset($data['matchedAddress']) ? (string) $data['matchedAddress'] : null,
            country: isset($data['country']) ? (string) $data['country'] : null,
            source: isset($data['source']) ? (string) $data['source'] : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'vatNumber' => $this->vatNumber,
            'vatType' => $this->vatType,
            'confidence' => $this->confidence,
            'matchedCompanyName' => $this->matchedCompanyName,
            'matchedAddress' => $this->matchedAddress,
            'country' => $this->country,
            'source' => $this->source,
        ];
    }
}
