<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * Tax category of an invoice line (UNCL5305 / DGFiP codes). Which categories and rates a country
 * accepts is in {@see \Taxora\Sdk\Endpoints\EReportingEndpoint::getVatRates()}; Norway uses
 * S (25 / 15 / 12 %), Z, E (with a VATEX code) and AE (reverse charge).
 */
enum ComplianceTaxCategory: string
{
    case STANDARD = 'S';
    case REDUCED = 'AA';
    case SUPER_REDUCED = 'AAA';
    case INTRA_COMMUNITY = 'K';
    case EXPORT = 'G';
    case REVERSE_CHARGE = 'AE';
    case EXEMPT = 'E';
    case ZERO = 'Z';

    /** Strict mapping for input: throws on a code the API does not know. */
    public static function fromInput(self|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $category = self::tryFrom(strtoupper(trim($value)));
        if ($category === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown tax category "%s". Valid: %s.',
                $value,
                implode(', ', array_map(static fn (self $c): string => $c->value, self::cases())),
            ));
        }

        return $category;
    }

    /** Exempt lines must name the exemption reason (VATEX code) in the tax comment. */
    public function requiresExemptionCode(): bool
    {
        return $this === self::EXEMPT;
    }
}
