<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\PeppolLookupStatus;

/**
 * E-invoicing: the Peppol directory answer about the buyer when it held an
 * invoice back (`delivery_check` of a transaction):
 *
 *  - status "not_reachable" — the buyer cannot receive e-invoices; the
 *    transaction is in state not_required (nothing sent, nothing billed);
 *  - status "pending" — the directory could not answer yet; Taxora asks again
 *    automatically and sends the invoice once the buyer is confirmed reachable.
 */
final readonly class ComplianceDeliveryCheck
{
    /**
     * @param bool|null $reachable null while the answer is pending
     * @param array<string,mixed> $raw the full delivery_check object (its keys may grow)
     */
    public function __construct(
        public PeppolLookupStatus $status,
        public ?bool $reachable,
        public ?string $checkedAt,
        public array $raw = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            status: PeppolLookupStatus::fromValue($data['status'] ?? null),
            reachable: isset($data['reachable']) ? (bool) $data['reachable'] : null,
            checkedAt: isset($data['checked_at']) ? (string) $data['checked_at'] : null,
            raw: $data,
        );
    }
}
