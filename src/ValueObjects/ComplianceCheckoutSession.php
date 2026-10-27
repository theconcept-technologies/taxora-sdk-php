<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use InvalidArgumentException;

/**
 * A started self-service checkout (POST /compliance/checkout): send the buyer to
 * $url. After payment Stripe redirects back to the Taxora portal; confirm with
 * EReportingEndpoint::confirmCheckout($id) to activate the plan right away (the
 * payment webhook activates it as well).
 */
final readonly class ComplianceCheckoutSession
{
    public function __construct(
        public string $id,
        public string $url,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $id = trim((string) ($data['id'] ?? ''));
        $url = trim((string) ($data['url'] ?? ''));
        if ($id === '' || $url === '') {
            throw new InvalidArgumentException('Checkout response is missing id or url.');
        }

        return new self($id, $url);
    }
}
