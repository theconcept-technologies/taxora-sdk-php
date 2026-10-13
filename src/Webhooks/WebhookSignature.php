<?php

declare(strict_types=1);

namespace Taxora\Sdk\Webhooks;

use JsonException;
use Taxora\Sdk\Exceptions\WebhookSignatureVerificationException;

/**
 * Verifies Taxora's opt-in webhook signatures.
 *
 * Header: `Taxora-Signature: t=<unix>,v1=<hex>[,v1=<hex>…]`,
 * v1 = hex(HMAC-SHA256(key = secret incl. "whsec_" prefix, message = "{t}.{raw_body}")).
 *
 * ```php
 * $event = WebhookSignature::constructEvent(
 *     file_get_contents('php://input'),          // raw body — never re-encoded JSON
 *     $_SERVER['HTTP_TAXORA_SIGNATURE'] ?? null,
 *     getenv('TAXORA_WEBHOOK_SECRET'),
 * );
 * ```
 */
final class WebhookSignature
{
    public const HEADER = 'Taxora-Signature';
    public const ID_HEADER = 'Taxora-Webhook-Id';
    public const DEFAULT_TOLERANCE = 300;

    private const SCHEME = 'v1';

    private function __construct()
    {
    }

    /**
     * Returns true or throws. $secret may be a list (accept old + new secret while you rotate on your side).
     * A tolerance of 0 disables the timestamp check — not recommended, it re-opens replays.
     *
     * @param string|list<string> $secret
     *
     * @throws WebhookSignatureVerificationException
     */
    public static function verify(
        string $rawBody,
        ?string $header,
        string|array $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): bool {
        $secrets = self::normalizeSecrets($secret);
        ['timestamp' => $timestamp, 'signatures' => $signatures] = self::parseHeader($header);

        $now ??= time();
        if ($tolerance > 0 && abs($now - $timestamp) > $tolerance) {
            throw new WebhookSignatureVerificationException(
                sprintf('Timestamp outside the tolerance window (%ds). Possible replay — or check the server clock.', $tolerance),
                WebhookSignatureVerificationException::REASON_TIMESTAMP_OUTSIDE_TOLERANCE,
            );
        }

        foreach ($secrets as $candidateSecret) {
            $expected = self::computeSignature($rawBody, $timestamp, $candidateSecret);
            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        throw new WebhookSignatureVerificationException(
            'No signature matches the expected signature for this payload. Pass the raw request body '
            . '(not re-encoded JSON) and the full secret including the "whsec_" prefix.',
            WebhookSignatureVerificationException::REASON_NO_MATCHING_SIGNATURE,
        );
    }

    /**
     * Verifies, then decodes the event: ['id' => string, 'event' => string, 'timestamp' => int, 'data' => ['object' => array]].
     *
     * @param string|list<string> $secret
     *
     * @return array{id: string, event: string, timestamp: int, data: array<string, mixed>}
     *
     * @throws WebhookSignatureVerificationException
     */
    public static function constructEvent(
        string $rawBody,
        ?string $header,
        string|array $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): array {
        self::verify($rawBody, $header, $secret, $tolerance, $now);

        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WebhookSignatureVerificationException(
                'Webhook payload is not valid JSON.',
                WebhookSignatureVerificationException::REASON_INVALID_PAYLOAD,
            );
        }

        if (
            !is_array($event)
            || !is_string($event['id'] ?? null)
            || !is_string($event['event'] ?? null)
            || !is_int($event['timestamp'] ?? null)
            || !is_array($event['data'] ?? null)
        ) {
            throw new WebhookSignatureVerificationException(
                'Webhook payload does not have the expected { id, event, timestamp, data } shape.',
                WebhookSignatureVerificationException::REASON_INVALID_PAYLOAD,
            );
        }

        /** @var array{id: string, event: string, timestamp: int, data: array<string, mixed>} $event */
        return $event;
    }

    /**
     * @return array{timestamp: int, signatures: list<string>}
     *
     * @throws WebhookSignatureVerificationException
     */
    public static function parseHeader(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            throw new WebhookSignatureVerificationException(
                sprintf('Missing %s header. Is signing enabled for this webhook endpoint?', self::HEADER),
                WebhookSignatureVerificationException::REASON_MISSING_HEADER,
            );
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === self::SCHEME && preg_match('/^[0-9a-f]{64}$/i', $value) === 1) {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new WebhookSignatureVerificationException(
                sprintf('Malformed %s header — expected "t=<unix>,v1=<hex>".', self::HEADER),
                WebhookSignatureVerificationException::REASON_MALFORMED_HEADER,
            );
        }

        return ['timestamp' => $timestamp, 'signatures' => $signatures];
    }

    public static function computeSignature(string $rawBody, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * Builds a valid header for a payload — for testing your own webhook handler without calling Taxora.
     *
     * @param string|list<string> $secret
     */
    public static function generateHeader(string $rawBody, string|array $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $parts = ['t=' . $timestamp];
        foreach (self::normalizeSecrets($secret) as $s) {
            $parts[] = self::SCHEME . '=' . self::computeSignature($rawBody, $timestamp, $s);
        }

        return implode(',', $parts);
    }

    /**
     * @param string|list<string> $secret
     *
     * @return non-empty-list<string>
     */
    private static function normalizeSecrets(string|array $secret): array
    {
        $secrets = array_values(array_filter(
            is_string($secret) ? [$secret] : $secret,
            static fn (mixed $s): bool => is_string($s) && $s !== '',
        ));

        if ($secrets === []) {
            throw new WebhookSignatureVerificationException(
                'No webhook signing secret given. Copy it from the Taxora dashboard (Developer → Webhooks).',
                WebhookSignatureVerificationException::REASON_MISSING_SECRET,
            );
        }

        return $secrets;
    }
}
