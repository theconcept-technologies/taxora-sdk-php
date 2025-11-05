<?php
declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\VatState;

/**
 * Immutable DTO mapped from OpenAPI VatResource.
 * Fields per spec: uuid, vat_uid, state, country_code, company_name, company_address,
 * requested_company_name, checked_at, score, breakdown[].
 * Ref: components/schemas/VatResource.  [oai_citation:0‡api-docs-sandbox.json](sediment://file_00000000578861f5acaecc8e4f531a99)
 */
final readonly class VatResource
{
    public function __construct(
        public ?string $uuid,
        public ?string $vat_uid,
        public ?VatState $state,
        public ?string $country_code,
        public ?string $company_name,
        public ?CompanyAddress $company_address,
        public ?string $requested_company_name,
        public ?\DateTimeImmutable $checked_at,
        public ?float $score,
        /** @var ScoreBreakdown[]|null */
        public ?array $breakdown,
        public ?string $environment = null,
        public ?string $provider = null,
        /** @var string[]|null */
        public ?array $used_providers = null,
        public ?string $provider_vat_state = null,
        public ?string $provider_note = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uuid: $data['uuid'] ?? null,
            vat_uid: $data['vat_uid'] ?? null,
            state: isset($data['state']) ? self::mapState($data['state']) : null,
            country_code: $data['country_code'] ?? null,
            company_name: $data['company_name'] ?? null,
            company_address: CompanyAddress::from($data['company_address'] ?? null),
            requested_company_name: $data['requested_company_name'] ?? null,
            checked_at: isset($data['checked_at']) ? new \DateTimeImmutable($data['checked_at']) : null,
            score: isset($data['score']) ? (float)$data['score'] : null,
            breakdown: self::hydrateBreakdown($data['breakdown'] ?? null),
            environment: isset($data['environment']) && is_string($data['environment']) ? $data['environment'] : null,
            provider: isset($data['provider']) && is_string($data['provider']) ? $data['provider'] : null,
            used_providers: self::sanitizeStringArray($data['used_providers'] ?? null),
            provider_vat_state: isset($data['provider_vat_state']) && is_string($data['provider_vat_state']) ? $data['provider_vat_state'] : null,
            provider_note: isset($data['provider_note']) && is_string($data['provider_note']) ? $data['provider_note'] : null,
        );
    }

    /**
     * @param mixed $breakdown
     * @return ScoreBreakdown[]|null
     */
    private static function hydrateBreakdown(mixed $breakdown): ?array
    {
        if (!is_array($breakdown)) {
            return null;
        }

        $items = [];
        foreach ($breakdown as $row) {
            if (is_array($row)) {
                $items[] = ScoreBreakdown::fromArray($row);
            }
        }

        return $items === [] ? null : $items;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'uuid'                    => $this->uuid,
            'vat_uid'                 => $this->vat_uid,
            'state'                   => $this->state?->value,
            'country_code'            => $this->country_code,
            'company_name'            => $this->company_name,
            'company_address'         => $this->company_address?->toArray(),
            'requested_company_name'  => $this->requested_company_name,
            'checked_at'              => $this->checked_at?->format(DATE_ATOM),
            'score'                   => $this->score,
            'breakdown'               => $this->breakdown === null
                ? null
                : array_map(static fn (ScoreBreakdown $item) => $item->toArray(), $this->breakdown),
            'environment'             => $this->environment,
            'provider'                => $this->provider,
            'used_providers'          => $this->used_providers,
            'provider_vat_state'      => $this->provider_vat_state,
            'provider_note'           => $this->provider_note,
        ];
    }

    /**
     * @param mixed $values
     * @return string[]|null
     */
    private static function sanitizeStringArray(mixed $values): ?array
    {
        if (is_string($values) && json_validate($values)) {
            return json_decode($values, true);
        }

        if (!is_array($values)) {
            return null;
        }
        $out = [];
        foreach ($values as $v) {
            if (is_string($v) && $v !== '') {
                $out[] = $v;
            }
        }
        return !empty($out) ? $out : null;
    }

    private static function mapState(mixed $state): ?VatState
    {
        if ($state instanceof VatState) {
            return $state;
        }

        if ($state === null) {
            return null;
        }

        $value = strtolower((string) $state);
        foreach (VatState::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Build a link to the Taxora app VAT history entry for this resource.
     * Always uses the production app host (app.taxora.io) and switches path by environment.
     * - LIVE (default) for production or when missing
     * - SANDBOX for sandbox
     */
    public function getBackendLink(): ?string
    {
        if ($this->uuid === null || $this->uuid === '') {
            return null;
        }

        $envRaw = is_string($this->environment) ? strtoupper(trim($this->environment)) : null;
        $envSegment = $envRaw === 'SANDBOX' ? 'SANDBOX' : 'LIVE';

        return sprintf('https://app.taxora.io/vat-history/%s/%s', $envSegment, $this->uuid);
    }
}
