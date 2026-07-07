<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * The company record that was submitted for one Smart Enrichment lookup
 * (as echoed back by the API, e.g. on history rows).
 */
final readonly class SmartEnrichmentInput
{
    public function __construct(
        public ?string $companyName = null,
        public ?string $street = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $country = null,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            companyName: isset($data['companyName']) ? (string) $data['companyName'] : null,
            street: isset($data['street']) ? (string) $data['street'] : null,
            postalCode: isset($data['postalCode']) ? (string) $data['postalCode'] : null,
            city: isset($data['city']) ? (string) $data['city'] : null,
            country: isset($data['country']) ? (string) $data['country'] : null,
        );
    }
}
