<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * The amounts of a non-EUR transaction converted to EUR at the ECB reference
 * rate of the invoice date (or the last published rate before it) —
 * `eur_equivalent` of a transaction. A reference value for bookkeeping; the
 * invoice itself stays in its own currency. Amounts are decimal strings with
 * two decimals, exactly as returned by the API.
 */
final readonly class ComplianceEurEquivalent
{
    /**
     * @param string $rate units of the invoice currency per 1 EUR (ECB reference rate)
     * @param string|null $rateDate the date (Y-m-d) the ECB published the rate used
     * @param string $source where the rate comes from — currently always "ECB"
     */
    public function __construct(
        public string $subtotal,
        public string $taxAmount,
        public string $total,
        public string $rate,
        public ?string $rateDate,
        public string $source,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            subtotal: (string) ($data['subtotal'] ?? '0'),
            taxAmount: (string) ($data['tax_amount'] ?? '0'),
            total: (string) ($data['total'] ?? '0'),
            rate: (string) ($data['rate'] ?? ''),
            rateDate: isset($data['rate_date']) ? (string) $data['rate_date'] : null,
            source: (string) ($data['source'] ?? 'ECB'),
        );
    }

    /** @return array{subtotal:string,tax_amount:string,total:string,rate:string,rate_date:?string,source:string} */
    public function toArray(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->taxAmount,
            'total' => $this->total,
            'rate' => $this->rate,
            'rate_date' => $this->rateDate,
            'source' => $this->source,
        ];
    }
}
