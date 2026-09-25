<?php

declare(strict_types=1);

namespace Taxora\Sdk\Support;

use InvalidArgumentException;

/**
 * Norwegian organisation number (organisasjonsnummer): 9 digits, the last one a
 * modulus-11 check digit (weights 3,2,7,6,5,4,3,2). It is also the Peppol
 * participant identifier under scheme 0192 and — with the "NO" prefix and the
 * "MVA" suffix — the Norwegian VAT number of an MVA-registered entity.
 *
 * Mirrors the server-side validation, so invalid numbers are rejected before
 * a request is sent.
 */
final class NorwegianOrgNumber
{
    /** Peppol identifier scheme for Norwegian organisation numbers. */
    public const PEPPOL_SCHEME = '0192';

    private const WEIGHTS = [3, 2, 7, 6, 5, 4, 3, 2];

    private function __construct()
    {
    }

    /**
     * Strip whitespace, dots, dashes, a "0192:" Peppol prefix, the "NO" prefix and
     * the "MVA" suffix — "NO 923 609 016 MVA", "923609016MVA" and "923 609 016"
     * all normalise to "923609016". The result is not validated; use isValid().
     */
    public static function normalize(string $value): string
    {
        $v = strtoupper(trim($value));
        $v = preg_replace('/[\s.\-]/', '', $v) ?? '';

        if (str_starts_with($v, self::PEPPOL_SCHEME . ':')) {
            $v = substr($v, 5);
        }
        if (str_starts_with($v, 'NO')) {
            $v = substr($v, 2);
        }
        if (str_ends_with($v, 'MVA')) {
            $v = substr($v, 0, -3);
        }

        return $v;
    }

    /** 9 digits (after normalisation) with a valid modulus-11 check digit. */
    public static function isValid(string $value): bool
    {
        $digits = self::normalize($value);

        if (preg_match('/^\d{9}$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;
        foreach (self::WEIGHTS as $i => $weight) {
            $sum += (int) $digits[$i] * $weight;
        }

        $check = 11 - ($sum % 11);
        if ($check === 11) {
            $check = 0;
        }

        // Remainder 1 → check digit 10 is impossible; such numbers are never issued.
        return $check !== 10 && $check === (int) $digits[8];
    }

    /**
     * The normalised 9-digit organisation number.
     *
     * @throws InvalidArgumentException when the number is not valid
     */
    public static function toDigits(string $value): string
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException('Invalid Norwegian organisation number (9 digits with a valid check digit).');
        }

        return self::normalize($value);
    }

    /**
     * "NO923609016MVA" — the Norwegian VAT number of an MVA-registered entity.
     *
     * @throws InvalidArgumentException when the number is not valid
     */
    public static function toVatNumber(string $value): string
    {
        return 'NO' . self::toDigits($value) . 'MVA';
    }
}
