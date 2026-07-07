<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * Provisioning status of a compliance enrollment (provider account + regime activation).
 */
enum ComplianceEnrollmentStatus: string
{
    case PENDING_DATA = 'pending_data';
    case ACCOUNT_CREATED = 'account_created';
    case REGIME_ACTIVATED = 'regime_activated';
    case READY = 'ready';
    case SUSPENDED = 'suspended';
    case ERROR_ACCOUNT = 'error_account';
    case ERROR_ACTIVATION = 'error_activation';
    case UNKNOWN = 'unknown';

    /** Tolerant mapping: unrecognized values become UNKNOWN instead of throwing. */
    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        foreach (self::cases() as $case) {
            if ($case->value === $normalized) {
                return $case;
            }
        }

        return self::UNKNOWN;
    }

    /** The enrollment failed provisioning and needs attention (retry / support). */
    public function isError(): bool
    {
        return $this === self::ERROR_ACCOUNT || $this === self::ERROR_ACTIVATION;
    }

    public function description(): string
    {
        return match ($this) {
            self::PENDING_DATA => 'Awaiting merchant data before provisioning can start',
            self::ACCOUNT_CREATED => 'Provider account exists, regime not yet activated',
            self::REGIME_ACTIVATED => 'Compliance regime activated at the provider, ready to submit transactions',
            self::READY => 'Enrollment fully active and reporting',
            self::SUSPENDED => 'Enrollment temporarily suspended',
            self::ERROR_ACCOUNT => 'Provider account creation failed',
            self::ERROR_ACTIVATION => 'Regime activation at the provider failed',
            self::UNKNOWN => 'Unknown status',
        };
    }
}
