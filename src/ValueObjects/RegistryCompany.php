<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * A company resolved from a national company register (Norway: Brønnøysund
 * Enhetsregisteret) — prefill for
 * {@see \Taxora\Sdk\Endpoints\EReportingEndpoint::createNorwayEnrollment()} and for
 * the buyer fields of a Norwegian e-invoice.
 */
final readonly class RegistryCompany
{
    public function __construct(
        public string $orgNumber,
        public ?string $companyName,
        public ?string $organisationForm,
        public bool $vatRegistered,
        public ?string $vatNumber,
        public bool $enterpriseRegister,
        public bool $bankrupt,
        public bool $underLiquidation,
        public ?string $address,
        public ?string $postalcode,
        public ?string $city,
        public ?string $country,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            orgNumber: (string) ($data['org_number'] ?? ''),
            companyName: isset($data['company_name']) ? (string) $data['company_name'] : null,
            organisationForm: isset($data['organisation_form']) ? (string) $data['organisation_form'] : null,
            vatRegistered: (bool) ($data['vat_registered'] ?? false),
            vatNumber: isset($data['vat_number']) ? (string) $data['vat_number'] : null,
            enterpriseRegister: (bool) ($data['enterprise_register'] ?? false),
            bankrupt: (bool) ($data['bankrupt'] ?? false),
            underLiquidation: (bool) ($data['under_liquidation'] ?? false),
            address: isset($data['address']) ? (string) $data['address'] : null,
            postalcode: isset($data['postalcode']) ? (string) $data['postalcode'] : null,
            city: isset($data['city']) ? (string) $data['city'] : null,
            country: isset($data['country']) ? (string) $data['country'] : null,
        );
    }

    /** Bankrupt or under liquidation — think twice before invoicing. */
    public function isInsolvent(): bool
    {
        return $this->bankrupt || $this->underLiquidation;
    }
}
