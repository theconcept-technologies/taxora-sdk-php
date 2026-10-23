<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * One company of the local tax numbers report: every company checked (VAT checks and Smart
 * Enrichment) once, with its local tax number. Empty cells come back as null.
 */
final readonly class LocalTaxNumberRow
{
    public function __construct(
        public ?string $companyName = null,
        /** The name you submitted, when it differs from the registered one. */
        public ?string $requestedName = null,
        public ?string $country = null,
        public ?string $vatNumber = null,
        /** 'valid' | 'invalid' | 'unconfirmed' | 'wrong_company' | 'no_vat_id' | 'not_found' */
        public ?string $vatStatus = null,
        /** The company's local tax / business number (NIP, SIREN, Partita IVA, Steuernummer …). */
        public ?string $taxNumber = null,
        /** The number's national name, e.g. 'NIP', 'SIREN', 'Steuernummer'. */
        public ?string $taxNumberName = null,
        /** 'vat_id' (read off the confirmed VAT ID) | 'company_website' (published by the company). */
        public ?string $taxNumberSource = null,
        /** 'vat_check' | 'smart_enrichment' */
        public ?string $source = null,
        public ?string $checkedAt = null,
        /** The VAT check uuid or the Smart Enrichment job id. */
        public ?string $checkId = null,
    ) {
    }

    /** @param array<string,string|null> $data one CSV row keyed by column name */
    public static function fromArray(array $data): self
    {
        $cell = static function (string $key) use ($data): ?string {
            $value = $data[$key] ?? null;
            if (!is_string($value)) {
                return null;
            }
            // trim() also strips the leading TAB the API puts before digit-only tax numbers
            // (it keeps Excel from turning "00743110157" into 743110157).
            $value = trim($value);

            return $value === '' ? null : $value;
        };

        return new self(
            companyName: $cell('companyName'),
            requestedName: $cell('requestedName'),
            country: $cell('country'),
            vatNumber: $cell('vatNumber'),
            vatStatus: $cell('vatStatus'),
            taxNumber: $cell('taxNumber'),
            taxNumberName: $cell('taxNumberName'),
            taxNumberSource: $cell('taxNumberSource'),
            source: $cell('source'),
            checkedAt: $cell('checkedAt'),
            checkId: $cell('checkId'),
        );
    }

    /** @return array<string,string|null> */
    public function toArray(): array
    {
        return [
            'companyName' => $this->companyName,
            'requestedName' => $this->requestedName,
            'country' => $this->country,
            'vatNumber' => $this->vatNumber,
            'vatStatus' => $this->vatStatus,
            'taxNumber' => $this->taxNumber,
            'taxNumberName' => $this->taxNumberName,
            'taxNumberSource' => $this->taxNumberSource,
            'source' => $this->source,
            'checkedAt' => $this->checkedAt,
            'checkId' => $this->checkId,
        ];
    }
}
