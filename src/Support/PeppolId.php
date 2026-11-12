<?php

declare(strict_types=1);

namespace Taxora\Sdk\Support;

use InvalidArgumentException;

/**
 * A Peppol participant id, "scheme:value" (e.g. "0208:0123456749"). A bare value
 * takes the usual scheme of the given country — the same defaults the server
 * applies for the Peppol e-invoicing countries (BE 0208 = enterprise number,
 * NL 0106 = KvK, AT 9914, IE 9935, DK 0184 = CVR, SE 0007, FI 0216, EE 0191,
 * GB 9932; NO 0192 = organisation number, see {@see NorwegianOrgNumber}).
 *
 * Mirrors the server-side parsing, so an id the server cannot route is rejected
 * before a request is sent.
 */
final class PeppolId
{
    /** Usual Peppol participant scheme per country, used when an id comes without one. */
    public const DEFAULT_SCHEMES = [
        'BE' => '0208',
        'NL' => '0106',
        'AT' => '9914',
        'IE' => '9935',
        'DK' => '0184',
        'SE' => '0007',
        'FI' => '0216',
        'EE' => '0191',
        'GB' => '9932',
        'NO' => NorwegianOrgNumber::PEPPOL_SCHEME,
    ];

    private function __construct()
    {
    }

    /** The usual scheme of a company in $country, or null when there is none. */
    public static function defaultScheme(?string $country): ?string
    {
        return self::DEFAULT_SCHEMES[strtoupper(trim((string) $country))] ?? null;
    }

    /**
     * Split an id into scheme and value. "0208:0123.456.749" and — for BE —
     * "0123 456 749" both become ['scheme' => '0208', 'value' => '0123456749']
     * (spaces and dots are removed from the value).
     *
     * @return array{scheme: string, value: string}|null null when no scheme is given and none is known for $country
     */
    public static function parse(string $id, ?string $country = null): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        if (preg_match('/^(\d{4})\s*:\s*(\S.*)$/', $id, $m) === 1) {
            return ['scheme' => $m[1], 'value' => self::compact($m[2])];
        }

        $scheme = self::defaultScheme($country);

        return $scheme !== null ? ['scheme' => $scheme, 'value' => self::compact($id)] : null;
    }

    /** True when the id has a scheme or $country has a usual one. */
    public static function isValid(string $id, ?string $country = null): bool
    {
        return self::parse($id, $country) !== null;
    }

    /**
     * The id as "scheme:value".
     *
     * @throws InvalidArgumentException when it has no scheme and none is known for $country
     */
    public static function format(string $id, ?string $country = null): string
    {
        $parsed = self::parse($id, $country);
        if ($parsed === null) {
            throw new InvalidArgumentException('Give the Peppol id with its scheme, e.g. 0208:0123456749.');
        }

        return $parsed['scheme'] . ':' . $parsed['value'];
    }

    private static function compact(string $value): string
    {
        return (string) preg_replace('/[\s.]+/', '', $value);
    }
}
