<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use Taxora\Sdk\Enums\SmartEnrichmentRejectionReason;

/**
 * A VAT number the research named, but the official check threw out.
 */
final readonly class SmartEnrichmentRejectedCandidate
{
    public function __construct(
        public string $vatNumber,
        public SmartEnrichmentRejectionReason $reason,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return self|null null when the entry carries no VAT number (nothing to report)
     */
    public static function fromArray(array $data): ?self
    {
        $vatNumber = isset($data['vatNumber']) && is_scalar($data['vatNumber'])
            ? trim((string) $data['vatNumber'])
            : '';

        if ($vatNumber === '') {
            return null;
        }

        return new self(
            vatNumber: $vatNumber,
            reason: SmartEnrichmentRejectionReason::fromValue($data['reason'] ?? null),
        );
    }

    /** @return array{vatNumber: string, reason: string} */
    public function toArray(): array
    {
        return [
            'vatNumber' => $this->vatNumber,
            'reason' => $this->reason->value,
        ];
    }
}
