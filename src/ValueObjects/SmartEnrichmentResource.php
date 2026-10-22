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
        /**
         * Only on `not_found`: VAT numbers the research DID find but the official check threw
         * out — e.g. one the tax authority reports as invalid. Lets you tell "found one, it is
         * invalid" apart from "found nothing". Empty for every other status.
         *
         * @var list<SmartEnrichmentRejectedCandidate>
         */
        public array $rejectedCandidates = [],
        /**
         * Only on `found`: the web page the VAT number was read from — usually the company's own
         * Impressum / legal notice. Null when it came from Taxora's own data or a register.
         */
        public ?string $sourceUrl = null,
        /**
         * Only on `found`: the PERSON the tax authority has the VAT ID under, when that is not the
         * trading name — typical for a German sole proprietorship (e.K.). `matchedCompanyName`
         * stays the trading name.
         */
        public ?string $registeredOwner = null,
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
            rejectedCandidates: self::rejectedCandidatesFrom($data['rejectedCandidates'] ?? null),
            sourceUrl: self::webUrl($data['sourceUrl'] ?? null),
            registeredOwner: self::nonEmptyString($data['registeredOwner'] ?? null),
        );
    }

    /** Only an http(s) address — anything else in the field is not a link worth following. */
    private static function webUrl(mixed $value): ?string
    {
        $url = self::nonEmptyString($value);

        return $url !== null && preg_match('#^https?://\S+$#i', $url) === 1 ? $url : null;
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** True when a VAT number was found but rejected by the official check. */
    public function hasRejectedCandidates(): bool
    {
        return $this->rejectedCandidates !== [];
    }

    /** @return list<SmartEnrichmentRejectedCandidate> */
    private static function rejectedCandidatesFrom(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $candidates = [];
        foreach ($value as $entry) {
            $candidate = is_array($entry) ? SmartEnrichmentRejectedCandidate::fromArray($entry) : null;
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
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
            'rejectedCandidates' => array_map(
                static fn (SmartEnrichmentRejectedCandidate $candidate): array => $candidate->toArray(),
                $this->rejectedCandidates,
            ),
            'sourceUrl' => $this->sourceUrl,
            'registeredOwner' => $this->registeredOwner,
        ];
    }
}
