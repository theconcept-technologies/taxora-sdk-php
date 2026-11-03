<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use InvalidArgumentException;
use Taxora\Sdk\Enums\ComplianceTaxCategory;

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
    public string $category;

    public function __construct(
        public string $name,
        public float|int|string $percent,
        ComplianceTaxCategory|string $category,
        public ?string $comment = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Tax name must not be empty.');
        }
        if (is_string($percent) && !is_numeric($percent)) {
            throw new InvalidArgumentException('Tax percent must be numeric.');
        }
        if ((float) $percent < 0 || (float) $percent > 100) {
            throw new InvalidArgumentException('Tax percent must be between 0 and 100.');
        }

        $parsed = ComplianceTaxCategory::fromInput($category);
        // Stored as the code (upper case), so a lower-case "s" or the enum both end up as "S".
        $this->category = $parsed->value;

        if ($comment !== null && strlen($comment) > 255) {
            throw new InvalidArgumentException('Tax comment must not exceed 255 characters.');
        }
        if ($parsed->requiresExemptionCode() && ($comment === null || trim($comment) === '')) {
            throw new InvalidArgumentException('An exempt (category E) tax needs the exemption code (VATEX) as comment.');
        }
    }

    /**
     * A taxed rate above 0 % (category S). The rate is the seller's to know — it is checked against the
     * country's catalogue by the API. For 0 % use {@see zeroRated()}, {@see exempt()} or {@see reverseCharge()}.
     */
    public static function rate(float|int|string $percent, string $name = 'VAT'): self
    {
        if (!is_numeric($percent) || (float) $percent <= 0) {
            throw new InvalidArgumentException('rate() needs a percent above 0; for 0 % use zeroRated(), exempt() or reverseCharge().');
        }

        return new self($name, $percent, ComplianceTaxCategory::STANDARD);
    }

    /** 0 % but taxable (category Z). */
    public static function zeroRated(string $name = 'VAT'): self
    {
        return new self($name, 0, ComplianceTaxCategory::ZERO);
    }

    /** Exempt from VAT (category E); the VATEX exemption code is mandatory. */
    public static function exempt(string $exemptionCode, string $name = 'VAT'): self
    {
        return new self($name, 0, ComplianceTaxCategory::EXEMPT, $exemptionCode);
    }

    /** Reverse charge (category AE); the mandatory legal text is added by Taxora. */
    public static function reverseCharge(string $name = 'VAT'): self
    {
        return new self($name, 0, ComplianceTaxCategory::REVERSE_CHARGE);
    }

    /**
     * Rebuild a tax from its wire shape (a stored `taxes_attributes` entry).
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            percent: is_numeric($data['percent'] ?? null) ? $data['percent'] : 0,
            category: (string) ($data['category'] ?? ''),
            comment: isset($data['comment']) && $data['comment'] !== '' ? (string) $data['comment'] : null,
        );
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
