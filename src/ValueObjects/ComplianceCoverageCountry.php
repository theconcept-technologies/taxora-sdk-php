<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * A country Taxora reaches — bookable self-service ("bookable") or set up on
 * request ("on_request").
 */
final readonly class ComplianceCoverageCountry
{
    /**
     * @param list<string> $services e.g. ['e_invoicing']
     * @param string $support 'yes', 'cross_border_only' (only invoices across the border are
     *                        reached) or 'coming_soon' (not live yet, can be requested)
     */
    public function __construct(
        public string $country,
        public string $availability,
        public array $services,
        public ?string $network,
        public ?string $format,
        public ?string $mandate,
        public string $support = 'yes',
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
            support: isset($data['support']) && is_string($data['support']) ? $data['support'] : 'yes',
        );
    }

    public function isBookable(): bool
    {
        return $this->availability === 'bookable';
    }

    /** Fully served (not only cross-border, not "coming soon"). */
    public function isFullySupported(): bool
    {
        return $this->support === 'yes';
    }
}
