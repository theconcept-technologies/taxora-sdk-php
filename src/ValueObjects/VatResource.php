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
        ];
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
}
