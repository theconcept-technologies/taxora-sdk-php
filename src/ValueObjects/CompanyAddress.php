<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Structured representation of the VAT resource company_address field.
 * API currently returns a JSON encoded string; we try to decode it and expose
 * useful fields while keeping the raw payload available for consumers.
 */
final readonly class CompanyAddress
{
    public ?string $fullAddress;
    public ?string $name;
    public ?string $street;
    public ?string $postalCode;
    public ?string $city;
    public ?string $state;
    public ?string $country;
    public ?string $raw;

    private function __construct(
        ?string $fullAddress,
        ?string $name,
        ?string $street,
        ?string $postalCode,
        ?string $city,
        ?string $state,
        ?string $country,
        ?string $raw
    ) {
        $this->fullAddress = $fullAddress;
        $this->name        = $name;
        $this->street      = $street;
        $this->postalCode  = $postalCode;
        $this->city        = $city;
        $this->state       = $state;
        $this->country     = $country;
        $this->raw         = $raw;
    }

    public static function from(mixed $value): ?self
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof self) {
            return $value;
        }

        if (is_array($value)) {
            return self::fromArray($value, null);
        }

        if (!is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        $decoded = self::decodeJson($raw);
        if ($decoded !== null) {
            return self::fromArray($decoded, $raw);
        }

        // Fallback to treating the string as a plain address line.
        return new self($raw, null, null, null, null, null, null, $raw);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'full_address' => $this->fullAddress,
            'name'         => $this->name,
            'street'       => $this->street,
            'postal_code'  => $this->postalCode,
            'city'         => $this->city,
            'state'        => $this->state,
            'country'      => $this->country,
            'raw'          => $this->raw,
        ];
    }

    public function __toString(): string
    {
        return $this->fullAddress
            ?? $this->raw
            ?? '';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function fromArray(array $data, ?string $raw): self
    {
        $normalized = self::normalizeKeys($data);

        $fullAddress = self::extractString($normalized, 'full_address');
        $name        = self::extractString($normalized, 'name');
        $street      = self::extractString($normalized, 'street');
        $postalCode  = self::extractString($normalized, 'postal_code');
        $city        = self::extractString($normalized, 'city');
        $state       = self::extractString($normalized, 'state');
        $country     = self::extractString($normalized, 'country');

        if ($fullAddress === null && $raw !== null) {
            $fullAddress = $raw;
        }

        $computedRaw = $raw ?? self::encodeJson($normalized);

        return new self(
            $fullAddress,
            $name,
            $street,
            $postalCode,
            $city,
            $state,
            $country,
            $computedRaw
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function decodeJson(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function normalizeKeys(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $key = strtolower(str_replace('-', '_', $key));
            $normalized[$key] = is_scalar($value) ? self::extractString([$key => $value], $key) : null;
        }

        return $normalized;
    }

    private static function extractString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
        } elseif (is_numeric($value)) {
            $value = (string) $value;
        } else {
            return null;
        }

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function encodeJson(array $data): ?string
    {
        $filtered = array_filter(
            $data,
            static fn($value) => is_scalar($value) && trim((string) $value) !== ''
        );

        if ($filtered === []) {
            return null;
        }

        try {
            return json_encode($filtered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return null;
        }
    }
}
