<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/** How many companies the local tax numbers report would list, and how many of them have a tax number. */
final readonly class LocalTaxNumberSummary
{
    public function __construct(
        public int $companies,
        public int $withTaxNumber,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            companies: is_numeric($data['companies'] ?? null) ? (int) $data['companies'] : 0,
            withTaxNumber: is_numeric($data['withTaxNumber'] ?? null) ? (int) $data['withTaxNumber'] : 0,
        );
    }

    /** @return array{companies:int,withTaxNumber:int} */
    public function toArray(): array
    {
        return [
            'companies' => $this->companies,
            'withTaxNumber' => $this->withTaxNumber,
        ];
    }
}
