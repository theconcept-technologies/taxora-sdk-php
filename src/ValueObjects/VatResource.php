<?php
declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\VatState;

/**
 * Immutable DTO mapped from OpenAPI VatResource.
 * Fields per spec: uuid, vat_uid, state, country_code, company_name, company_address,
 * requested_company_name, checked_at, score, breakdown[], provider_last_checked_at, provider_document.
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
        public ?string $requested_company_name_original = null,
        /** @var array<string, string|null>|null */
        public ?array $requested_input_address = null,
        public ?string $score_source = null,
        /** @var array<int, array{source?: string, score?: float}>|null */
        public ?array $score_attempts = null,
        public ?string $environment = null,
        public ?string $provider = null,
        /** @var string[]|null */
        public ?array $used_providers = null,
        public ?string $provider_vat_state = null,
        public ?string $provider_note = null,
        public ?\DateTimeImmutable $provider_last_checked_at = null,
        public ?ProviderDocument $provider_document = null,
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
            requested_company_name_original: isset($data['requested_company_name_original']) && is_string($data['requested_company_name_original'])
                ? $data['requested_company_name_original']
                : null,
            requested_input_address: self::normalizeRequestedInputAddress($data['requested_input_address'] ?? null),
            checked_at: isset($data['checked_at']) ? new \DateTimeImmutable($data['checked_at']) : null,
            score: isset($data['score']) ? (float)$data['score'] : null,
            score_source: isset($data['score_source']) && is_string($data['score_source']) ? $data['score_source'] : null,
            score_attempts: self::normalizeScoreAttempts($data['score_attempts'] ?? null),
            breakdown: self::hydrateBreakdown($data['breakdown'] ?? null),
            environment: isset($data['environment']) && is_string($data['environment']) ? $data['environment'] : null,
            provider: isset($data['provider']) && is_string($data['provider']) ? $data['provider'] : null,
            used_providers: self::sanitizeStringArray($data['used_providers'] ?? null),
            provider_vat_state: isset($data['provider_vat_state']) && is_string($data['provider_vat_state']) ? $data['provider_vat_state'] : null,
            provider_note: isset($data['provider_note']) && is_string($data['provider_note']) ? $data['provider_note'] : null,
            provider_last_checked_at: isset($data['provider_last_checked_at']) ? new \DateTimeImmutable($data['provider_last_checked_at']) : null,
            provider_document: ProviderDocument::fromArray($data['provider_document'] ?? null),
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
            'requested_company_name_original' => $this->requested_company_name_original,
            'requested_input_address' => $this->requested_input_address,
            'checked_at'              => $this->checked_at?->format(DATE_ATOM),
            'score'                   => $this->score,
            'score_source'            => $this->score_source,
            'score_attempts'          => $this->score_attempts,
            'breakdown'               => $this->breakdown === null
                ? null
                : array_map(static fn (ScoreBreakdown $item) => $item->toArray(), $this->breakdown),
            'environment'             => $this->environment,
            'provider'                => $this->provider,
            'used_providers'          => $this->used_providers,
            'provider_vat_state'      => $this->provider_vat_state,
            'provider_note'           => $this->provider_note,
            'provider_last_checked_at' => $this->provider_last_checked_at?->format(DATE_ATOM),
            'provider_document'       => $this->provider_document?->toArray(),
        ];
    }

    /**
     * @param mixed $values
     * @return string[]|null
     */
    private static function sanitizeStringArray(mixed $values): ?array
    {
        if (is_string($values) && json_validate($values)) {
            $values = json_decode($values, true);
        }

        if (!is_array($values)) {
            return null;
        }
        $out = [];
        foreach ($values as $v) {
            if (is_string($v)) {
                $v = trim($v);
                if ($v !== '') {
                    $out[] = $v;
                }
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
     * @param mixed $value
     * @return array<string, string|null>|null
     */
    private static function normalizeRequestedInputAddress(mixed $value): ?array
    {
        if (is_string($value) && json_validate($value)) {
            $value = json_decode($value, true);
        }

        if (!is_array($value)) {
            return null;
        }

        $normalized = [];
        foreach ($value as $key => $fieldValue) {
            if (!is_string($key) || !self::isAddressFieldKey($key)) {
                continue;
            }

            if ($fieldValue === null) {
                $normalized[$key] = null;
                continue;
            }

            if (!is_string($fieldValue)) {
                continue;
            }

            $trimmed = trim($fieldValue);
            if ($trimmed === '') {
                $normalized[$key] = null;
                continue;
            }

            if ($key === 'countryCode') {
                $trimmed = strtoupper($trimmed);
            }

            $normalized[$key] = $trimmed;
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param mixed $value
     * @return array<int, array{source?: string, score?: float}>|null
     */
    private static function normalizeScoreAttempts(mixed $value): ?array
    {
        if (is_string($value) && json_validate($value)) {
            $value = json_decode($value, true);
        }

        if (!is_array($value)) {
            return null;
        }

        $normalized = [];
        foreach ($value as $attempt) {
            if (!is_array($attempt)) {
                continue;
            }

            $item = [];
            if (isset($attempt['source']) && is_string($attempt['source'])) {
                $source = trim($attempt['source']);
                if ($source !== '') {
                    $item['source'] = $source;
                }
            }

            if (isset($attempt['score']) && is_numeric($attempt['score'])) {
                $item['score'] = (float) $attempt['score'];
            }

            if ($item !== []) {
                $normalized[] = $item;
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    private static function isAddressFieldKey(string $key): bool
    {
        if ($key === 'postalCode' || $key === 'city' || $key === 'countryCode') {
            return true;
        }

        return preg_match('/^addressLine[1-9]\d*$/', $key) === 1;
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
