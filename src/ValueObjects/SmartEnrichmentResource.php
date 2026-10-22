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
        /**
         * Only on `found`: every page the VAT number was read from, `sourceUrl` first — each
         * research pass that found the same number contributes its page. At most 5.
         *
         * @var list<string>
         */
        public array $sourceUrls = [],
        /**
         * A domestic tax number (e.g. a German Steuernummer, digits only) the company's own page
         * shows INSTEAD of a VAT ID — often under the VAT ID heading by mistake. Not a VAT ID.
         */
        public ?string $taxNumber = null,
        /** Only on `found`: the tax authority's verdict on the number; null when no official check ran. */
        public ?bool $vatValid = null,
        /** Only on `found`: 0–100, the requested name against the register's; null when no name was disclosed. */
        public ?float $aiScore = null,
        /**
         * Only on `found`: 'ok' (name matches the register) or 'mismatch' (it does not — another
         * company, unless $registeredOwner is set: then update your record). Null when no name was disclosed.
         */
        public ?string $nameMatch = null,
        /** Only on `found`: the name the register has the number under. */
        public ?string $officialName = null,
        /**
         * Only on `found`: the registered address, field by field (street, postal_code, city, country).
         *
         * @var array<string,string>|null
         */
        public ?array $officialAddress = null,
    ) {
    }

    /** True when the register knows the number under a different name than the one requested. */
    public function nameMismatch(): bool
    {
        return $this->nameMatch === 'mismatch';
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
            sourceUrls: self::webUrls($data['sourceUrls'] ?? null),
            taxNumber: self::nonEmptyString($data['taxNumber'] ?? null),
            vatValid: is_bool($data['vatValid'] ?? null) ? $data['vatValid'] : null,
            aiScore: is_numeric($data['aiScore'] ?? null) ? (float) $data['aiScore'] : null,
            nameMatch: in_array($data['nameMatch'] ?? null, ['ok', 'mismatch'], true) ? $data['nameMatch'] : null,
            officialName: self::nonEmptyString($data['officialName'] ?? null),
            officialAddress: self::addressFrom($data['officialAddress'] ?? null),
        );
    }

    /** @return array<string,string>|null only the known address parts, as strings */
    private static function addressFrom(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $address = [];
        foreach (['street', 'postal_code', 'city', 'country'] as $key) {
            $part = self::nonEmptyString($value[$key] ?? null);
            if ($part !== null) {
                $address[$key] = $part;
            }
        }

        return $address === [] ? null : $address;
    }

    /** Only an http(s) address — anything else in the field is not a link worth following. */
    private static function webUrl(mixed $value): ?string
    {
        $url = self::nonEmptyString($value);

        return $url !== null && preg_match('#^https?://\S+$#i', $url) === 1 ? $url : null;
    }

    /** @return list<string> */
    private static function webUrls(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $urls = [];
        foreach ($value as $entry) {
            $url = self::webUrl($entry);
            if ($url !== null && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
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
            'sourceUrls' => $this->sourceUrls,
            'taxNumber' => $this->taxNumber,
            'vatValid' => $this->vatValid,
            'aiScore' => $this->aiScore,
            'nameMatch' => $this->nameMatch,
            'officialName' => $this->officialName,
            'officialAddress' => $this->officialAddress,
        ];
    }
}
