<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use InvalidArgumentException;

/**
 * One tax applied to an invoice line of a compliance transaction (input).
 *
 * `category` is the DGFiP tax category code (S/AA/AAA/K/G/AE/E/Z) — see
 * {@see \Taxora\Sdk\Endpoints\EReportingEndpoint::getVatRates()} for the
 * country's canonical catalogue. Category `E` (exempt) requires the VATEX
 * exemption code in `comment`.
 */
final readonly class ComplianceLineTax
{
    public function __construct(
        public string $name,
        public float|int|string $percent,
        public string $category,
        public ?string $comment = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Tax name must not be empty.');
        }
        if (trim($category) === '') {
            throw new InvalidArgumentException('Tax category must not be empty.');
        }
        if (is_string($percent) && !is_numeric($percent)) {
            throw new InvalidArgumentException('Tax percent must be numeric.');
        }
    }

    /** Wire representation (one entry of `taxes_attributes`). @return array<string,mixed> */
    public function toArray(): array
    {
        $tax = [
            'name' => $this->name,
            'percent' => $this->percent,
            'category' => $this->category,
        ];

        if ($this->comment !== null) {
            $tax['comment'] = $this->comment;
        }

        return $tax;
    }
}
