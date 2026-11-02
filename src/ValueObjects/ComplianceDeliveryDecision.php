<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\ComplianceDeliveryAction;
use Taxora\Sdk\Enums\PeppolLookupStatus;

/**
 * The outcome of {@see \Taxora\Sdk\Endpoints\EReportingEndpoint::decideDelivery()}:
 * the action for one buyer plus the directory answer it is based on.
 */
final readonly class ComplianceDeliveryDecision
{
    public function __construct(
        public ComplianceDeliveryAction $action,
        public PeppolLookupResult $lookup,
    ) {
    }

    public static function fromLookup(PeppolLookupResult $lookup): self
    {
        return new self(
            match ($lookup->status) {
                PeppolLookupStatus::REACHABLE => ComplianceDeliveryAction::EINVOICE,
                PeppolLookupStatus::NOT_REACHABLE => ComplianceDeliveryAction::REGULAR_INVOICE,
                default => ComplianceDeliveryAction::EINVOICE_PENDING,
            },
            $lookup,
        );
    }
}
