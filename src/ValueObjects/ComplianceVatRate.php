<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * One VAT rate / DGFiP tax category entry of a reporting country's catalogue.
 * `category` is the DGFiP tax category code (S/AA/AAA/K/G/AE/E/Z); when
 * `requiresVatex` is true, a VATEX exemption code must be supplied as the tax
 * line's `comment`.
 */
final readonly class ComplianceVatRate
{
    public function __construct(
        public float $percent,
        public string $category,
        public ?string $labelKey,
        public ?string $taxName,
        public bool $requiresVatex = false,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            percent: (float) ($data['percent'] ?? 0.0),
            category: (string) ($data['category'] ?? ''),
            labelKey: isset($data['label_key']) ? (string) $data['label_key'] : null,
            taxName: isset($data['tax_name']) ? (string) $data['tax_name'] : null,
            requiresVatex: (bool) ($data['requires_vatex'] ?? false),
        );
    }
}
