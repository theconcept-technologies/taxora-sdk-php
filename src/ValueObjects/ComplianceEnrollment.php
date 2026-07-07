<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\ComplianceEnrollmentStatus;

/**
 * A compliance enrollment: the link between a Taxora company and a provider
 * account (B2Brouter) for one reporting regime (e.g. DGFiP Flux 10 in France).
 */
final readonly class ComplianceEnrollment
{
    /**
     * @param array<string,mixed> $regimeConfig regime-specific settings, e.g. naf_code / enterprise_size / type_operation
     */
    public function __construct(
        public int $id,
        public ?int $companyId,
        public ?string $country,
        public ?string $regime,
        public ?string $provider,
        public ComplianceEnrollmentStatus $status,
        public ?string $statusLabel,
        public ?string $statusError,
        public ?string $providerAccountId,
        public ?string $taxId,
        public ?string $companyRegisterId,
        public ?string $companyRegisterScheme,
        public array $regimeConfig,
        public ?string $reportingStartDate,
        public ?string $notificationEmail,
        public bool $autoSend,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            companyId: isset($data['company_id']) ? (int) $data['company_id'] : null,
            country: isset($data['country']) ? (string) $data['country'] : null,
            regime: isset($data['regime']) ? (string) $data['regime'] : null,
            provider: isset($data['provider']) ? (string) $data['provider'] : null,
            status: ComplianceEnrollmentStatus::fromValue($data['status'] ?? 'unknown'),
            statusLabel: isset($data['status_label']) ? (string) $data['status_label'] : null,
            statusError: isset($data['status_error']) ? (string) $data['status_error'] : null,
            providerAccountId: isset($data['provider_account_id']) ? (string) $data['provider_account_id'] : null,
            taxId: isset($data['tax_id']) ? (string) $data['tax_id'] : null,
            companyRegisterId: isset($data['company_register_id']) ? (string) $data['company_register_id'] : null,
            companyRegisterScheme: isset($data['company_register_scheme']) ? (string) $data['company_register_scheme'] : null,
            regimeConfig: is_array($data['regime_config'] ?? null) ? $data['regime_config'] : [],
            reportingStartDate: isset($data['reporting_start_date']) ? (string) $data['reporting_start_date'] : null,
            notificationEmail: isset($data['notification_email']) ? (string) $data['notification_email'] : null,
            autoSend: (bool) ($data['auto_send'] ?? false),
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
            updatedAt: isset($data['updated_at']) ? (string) $data['updated_at'] : null,
        );
    }
}
