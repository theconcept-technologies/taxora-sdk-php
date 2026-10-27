<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * A country Taxora reaches — bookable self-service ("bookable") or set up on
 * request ("on_request").
 */
final readonly class ComplianceCoverageCountry
{
    /** @param list<string> $services e.g. ['e_invoicing'] */
    public function __construct(
        public string $country,
        public string $availability,
        public array $services,
        public ?string $network,
        public ?string $format,
        public ?string $mandate,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $services = [];
        foreach (is_array($data['services'] ?? null) ? $data['services'] : [] as $service) {
            if (is_string($service)) {
                $services[] = $service;
            }
        }

        return new self(
            country: strtoupper((string) ($data['country'] ?? '')),
            availability: (string) ($data['availability'] ?? ''),
            services: $services,
            network: isset($data['network']) ? (string) $data['network'] : null,
            format: isset($data['format']) ? (string) $data['format'] : null,
            mandate: isset($data['mandate']) ? (string) $data['mandate'] : null,
        );
    }

    public function isBookable(): bool
    {
        return $this->availability === 'bookable';
    }
}
