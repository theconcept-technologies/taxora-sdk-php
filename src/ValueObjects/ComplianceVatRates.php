<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * The canonical VAT rates & DGFiP tax categories of one reporting country
 * (the seller's country — not the counterparty's).
 */
final readonly class ComplianceVatRates
{
    /**
     * @param list<ComplianceVatRate> $rates
     */
    public function __construct(
        public string $country,
        public string $currency,
        public array $rates,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $rates = [];
        if (isset($data['rates']) && is_array($data['rates'])) {
            foreach ($data['rates'] as $rate) {
                if (is_array($rate)) {
                    $rates[] = ComplianceVatRate::fromArray($rate);
                }
            }
        }

        return new self(
            country: (string) ($data['country'] ?? ''),
            currency: (string) ($data['currency'] ?? 'EUR'),
            rates: $rates,
        );
    }
}
