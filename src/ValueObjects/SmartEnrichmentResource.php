<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\SmartEnrichmentMode;
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
        public ?SmartEnrichmentMode $mode = null,  // the search mode this lookup actually ran in
        /**
         * What each AI provider independently concluded, when more than one searched. Two entries
         * reporting the same vatNumber is the strongest confirmation this layer produces.
         *
         * @var list<array<string,mixed>>
         */
        public array $providerVerdicts = [],
        /**
         * What the API made of the address you submitted. The actionable part is `warnings`:
         * 'postal_code_city_mismatch' / 'postal_code_unassigned' / 'postal_code_format_invalid'
         * mean the postal code in your source record is wrong, and `derivedPlace` is where it
         * actually points — worth writing back into your own data.
         *
         * @var array<string,mixed>|null
         */
        public ?array $addressQuality = null,
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
            mode: isset($data['mode']) ? SmartEnrichmentMode::tryFrom((string) $data['mode']) : null,
            providerVerdicts: is_array($data['providerVerdicts'] ?? null)
                ? array_values(array_filter($data['providerVerdicts'], 'is_array'))
                : [],
            addressQuality: is_array($data['addressQuality'] ?? null) ? $data['addressQuality'] : null,
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
            'mode' => $this->mode?->value,
            'providerVerdicts' => $this->providerVerdicts,
            'addressQuality' => $this->addressQuality,
        ];
    }
}
