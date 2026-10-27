<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * GET /compliance/offerings — bookable compliance plans per country, every
 * country Taxora reaches, and which services the company has unlocked.
 */
final readonly class ComplianceOfferings
{
    /**
     * @param list<ComplianceOffering> $offerings
     * @param list<ComplianceCoverageCountry> $coverage
     * @param bool $eReportingAccess e-reporting is unlocked anywhere (grant, self-service plan or legacy price)
     * @param bool $eInvoicingAccess e-invoicing is unlocked anywhere
     * @param array<string,array<string,bool>> $accessByCountry e.g. ['FR' => ['e_reporting' => true, 'e_invoicing' => false]]
     */
    public function __construct(
        public array $offerings,
        public array $coverage,
        public bool $eReportingAccess,
        public bool $eInvoicingAccess,
        public array $accessByCountry,
    ) {
    }

    /** @param array<string,mixed> $payload the response's `data` object ({data, coverage, access, access_by_country}) */
    public static function fromArray(array $payload): self
    {
        $offerings = [];
        foreach (is_array($payload['data'] ?? null) ? $payload['data'] : [] as $row) {
            if (is_array($row)) {
                /** @var array<string,mixed> $row */
                $offerings[] = ComplianceOffering::fromArray($row);
            }
        }

        $coverage = [];
        foreach (is_array($payload['coverage'] ?? null) ? $payload['coverage'] : [] as $row) {
            if (is_array($row)) {
                /** @var array<string,mixed> $row */
                $coverage[] = ComplianceCoverageCountry::fromArray($row);
            }
        }

        $access = is_array($payload['access'] ?? null) ? $payload['access'] : [];

        $accessByCountry = [];
        foreach (is_array($payload['access_by_country'] ?? null) ? $payload['access_by_country'] : [] as $country => $services) {
            if (!is_array($services)) {
                continue;
            }
            $flags = [];
            foreach ($services as $service => $value) {
                $flags[(string) $service] = (bool) $value;
            }
            $accessByCountry[strtoupper((string) $country)] = $flags;
        }

        return new self(
            offerings: $offerings,
            coverage: $coverage,
            eReportingAccess: (bool) ($access['e_reporting'] ?? false),
            eInvoicingAccess: (bool) ($access['e_invoicing'] ?? false),
            accessByCountry: $accessByCountry,
        );
    }

    /** The offering for a country (ISO alpha-2, any case), or null when it cannot be booked self-service. */
    public function forCountry(string $country): ?ComplianceOffering
    {
        $country = strtoupper(trim($country));
        foreach ($this->offerings as $offering) {
            if ($offering->country === $country) {
                return $offering;
            }
        }

        return null;
    }

    /** Whether $service ("e_reporting" / "e_invoicing") is unlocked for $country. */
    public function hasAccess(string $country, string $service): bool
    {
        return $this->accessByCountry[strtoupper(trim($country))][$service] ?? false;
    }
}
