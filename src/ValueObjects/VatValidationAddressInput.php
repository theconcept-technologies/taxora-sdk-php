<?php
declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use InvalidArgumentException;

/**
 * Typed VAT validation address input.
 * Converts to API request fields addressLine1, addressLine2, postalCode, city, countryCode.
 */
final readonly class VatValidationAddressInput
{
    public ?string $addressLine1;
    public ?string $addressLine2;
    public ?string $postalCode;
    public ?string $city;
    public ?string $countryCode;

    public function __construct(
        ?string $addressLine1 = null,
        ?string $addressLine2 = null,
        ?string $postalCode = null,
        ?string $city = null,
        ?string $countryCode = null
    ) {
        $this->addressLine1 = self::normalizeAddressLineValue('addressLine1', $addressLine1);
        $this->addressLine2 = self::normalizeAddressLineValue('addressLine2', $addressLine2);
        $this->postalCode = self::normalizeSimpleStringField('postalCode', $postalCode, 32);
        $this->city = self::normalizeSimpleStringField('city', $city, 120);
        $this->countryCode = self::normalizeCountryCode($countryCode);
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $addressLine1 = null;
        $addressLine2 = null;
        $postalCode = null;
        $city = null;
        $countryCode = null;

        foreach ($input as $key => $value) {
            if ($key === 'addressLine1') {
                $addressLine1 = $value;
                continue;
            }

            if ($key === 'addressLine2') {
                $addressLine2 = $value;
                continue;
            }

            if ($key === 'postalCode') {
                $postalCode = $value;
                continue;
            }

            if ($key === 'city') {
                $city = $value;
                continue;
            }

            if ($key === 'countryCode') {
                $countryCode = $value;
                continue;
            }

            throw new InvalidArgumentException(sprintf('Unsupported address input field "%s".', $key));
        }

        return new self($addressLine1, $addressLine2, $postalCode, $city, $countryCode);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        $payload = [];

        if ($this->addressLine1 !== null) {
            $payload['addressLine1'] = $this->addressLine1;
        }
        if ($this->addressLine2 !== null) {
            $payload['addressLine2'] = $this->addressLine2;
        }

        if ($this->postalCode !== null) {
            $payload['postalCode'] = $this->postalCode;
        }
        if ($this->city !== null) {
            $payload['city'] = $this->city;
        }
        if ($this->countryCode !== null) {
            $payload['countryCode'] = $this->countryCode;
        }

        return $payload;
    }

    private static function normalizeAddressLineValue(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Address input field "%s" must be a string or null.', $field));
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (strlen($value) > 255) {
            throw new InvalidArgumentException(sprintf('%s exceeds maximum length of 255 characters.', $field));
        }

        return $value;
    }

    private static function normalizeSimpleStringField(string $field, mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Address input field "%s" must be a string or null.', $field));
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException(sprintf('%s exceeds maximum length of %d characters.', $field, $maxLength));
        }

        return $value;
    }

    private static function normalizeCountryCode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('Address input field "countryCode" must be a string or null.');
        }

        $value = strtoupper(trim($value));
        if ($value === '') {
            return null;
        }

        if (strlen($value) !== 2) {
            throw new InvalidArgumentException('countryCode must be exactly 2 characters.');
        }

        return $value;
    }
}
