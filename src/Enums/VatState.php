<?php

namespace Taxora\Sdk\Enums;

enum VatState: string
{
    case VALID = 'valid';
    case INVALID = 'invalid';
    case FRAUD = 'fraud';
    case UNKNOWN = 'unknown';

    public static function getFailedStates(): array
    {
        return [
            self::INVALID->value,
            self::FRAUD->value,
            self::UNKNOWN->value,
        ];
    }

    public function description(): string
    {
        return match ($this) {
            self::VALID => 'Valid',
            self::INVALID => 'Invalid',
            self::FRAUD => 'Fraud',
            self::UNKNOWN => 'Unknown',
        };
    }
}
