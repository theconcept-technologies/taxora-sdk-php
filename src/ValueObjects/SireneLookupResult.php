<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Company data resolved from the French SIRENE registry (by SIRET, SIREN or
 * French VAT number) — prefill for {@see \Taxora\Sdk\Endpoints\EReportingEndpoint::createEnrollment()}.
 */
final readonly class SireneLookupResult
{
    public function __construct(
        public ?string $companyName,
        public ?string $siren,
        public ?string $siret,
        public ?string $nafCode,
        public ?string $nafFull,
        public ?string $enterpriseSize,
        public ?string $address,
        public ?string $city,
        public ?string $postalcode,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            companyName: isset($data['company_name']) ? (string) $data['company_name'] : null,
            siren: isset($data['siren']) ? (string) $data['siren'] : null,
            siret: isset($data['siret']) ? (string) $data['siret'] : null,
            nafCode: isset($data['naf_code']) ? (string) $data['naf_code'] : null,
            nafFull: isset($data['naf_full']) ? (string) $data['naf_full'] : null,
            enterpriseSize: isset($data['enterprise_size']) ? (string) $data['enterprise_size'] : null,
            address: isset($data['address']) ? (string) $data['address'] : null,
            city: isset($data['city']) ? (string) $data['city'] : null,
            postalcode: isset($data['postalcode']) ? (string) $data['postalcode'] : null,
        );
    }
}
