<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * Why a VAT number the research DID find was not returned as the result.
 *
 * Only appears on `not_found` results (see SmartEnrichmentResource::$rejectedCandidates), so
 * "a number was found, but the tax authority calls it invalid" can be told apart from "nothing
 * was found at all".
 */
enum SmartEnrichmentRejectionReason: string
{
    /** The tax authority reports the number as not (or no longer) valid. */
    case VAT_INVALID = 'vat_invalid';

    /** The number is valid, but registered to a different company. */
    case NAME_MISMATCH = 'name_mismatch';

    /** The number could not be tied to the requested company. */
    case UNBOUND = 'unbound';

    /** The number could not be checked against the tax authority. */
    case UNVERIFIED = 'unverified';

    /** Rejected for another reason. */
    case REJECTED = 'rejected';

    /** A reason this SDK version does not know yet. */
    case UNKNOWN = 'unknown';

    /** Tolerant mapping: unrecognized values become UNKNOWN instead of throwing. */
    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!is_scalar($value)) {
            return self::UNKNOWN;
        }

        return self::tryFrom(strtolower(trim((string) $value))) ?? self::UNKNOWN;
    }

    public function description(): string
    {
        return match ($this) {
            self::VAT_INVALID => 'The tax authority reports the VAT number as invalid',
            self::NAME_MISMATCH => 'The VAT number is registered to a different company',
            self::UNBOUND => 'The VAT number could not be tied to the requested company',
            self::UNVERIFIED => 'The VAT number could not be verified with the tax authority',
            self::REJECTED => 'The VAT number was not confirmed',
            self::UNKNOWN => 'Unknown rejection reason',
        };
    }
}
