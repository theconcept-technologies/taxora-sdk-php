<?php

declare(strict_types=1);

namespace Taxora\Sdk\Exceptions;

/**
 * Derives a short, human readable exception message from an API response body.
 *
 * A response body is never used verbatim as an exception message: the gateways
 * in front of the API (DigitalOcean App Platform, load balancers, proxies)
 * answer 5xx with a full HTML error page, and that page would otherwise end up
 * in every log line, error mail and stack trace of the integrating application.
 *
 * Only the human readable message of a JSON error body is used; everything else
 * (HTML, empty bodies, binary payloads) falls back to the HTTP status line. The
 * untouched body always stays available via HttpException::getResponseBody().
 */
final class ApiErrorMessage
{
    private const MAX_LENGTH = 500;

    /** Plain text bodies are only surfaced when they are this short. */
    private const MAX_PLAIN_LENGTH = 200;

    /** @var array<int, string> */
    private const REASON_PHRASES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        408 => 'Request Timeout',
        409 => 'Conflict',
        413 => 'Payload Too Large',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    /**
     * The message for a failed request: the API message when the body carries
     * one, otherwise a description of the HTTP status.
     */
    public static function describe(?string $body, int $statusCode): string
    {
        return self::fromJsonBody($body)
            ?? self::fromPlainBody($body)
            ?? self::fromStatusCode($statusCode);
    }

    /**
     * The failure detail without the surrounding sentence: the API message when
     * there is one, otherwise just the status ("HTTP 504 Gateway Timeout").
     */
    public static function detail(?string $body, int $statusCode): string
    {
        return self::fromJsonBody($body)
            ?? self::fromPlainBody($body)
            ?? self::statusPhrase($statusCode);
    }

    /** The human readable message of a JSON error body, or null. */
    public static function fromJsonBody(?string $body): ?string
    {
        if ($body === null || trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }

        foreach (['message', 'error'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key])) {
                $message = self::sanitize($decoded[$key]);
                if ($message !== null) {
                    return $message;
                }
            }
        }

        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            return self::fromErrorBag($decoded['errors']);
        }

        return null;
    }

    /**
     * A short body without any markup (e.g. "upstream request timeout") is safe
     * to surface as is; anything longer or HTML/JSON shaped is not.
     */
    public static function fromPlainBody(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $body));
        if ($value === '' || mb_strlen($value) > self::MAX_PLAIN_LENGTH) {
            return null;
        }

        if (str_contains($value, '<') || str_contains($value, '{')) {
            return null;
        }

        return $value;
    }

    public static function fromStatusCode(int $statusCode): string
    {
        return sprintf('Taxora API request failed (%s).', self::statusPhrase($statusCode));
    }

    /** e.g. "HTTP 504 Gateway Timeout". */
    public static function statusPhrase(int $statusCode): string
    {
        $phrase = self::REASON_PHRASES[$statusCode] ?? null;

        return sprintf('HTTP %d%s', $statusCode, $phrase !== null ? ' ' . $phrase : '');
    }

    /** @param array<array-key, mixed> $errors */
    private static function fromErrorBag(array $errors): ?string
    {
        foreach ($errors as $field => $messages) {
            $messages = is_array($messages) ? $messages : [$messages];
            foreach ($messages as $message) {
                if (!is_string($message)) {
                    continue;
                }
                $message = self::sanitize($message);
                if ($message === null) {
                    continue;
                }

                return is_string($field) && $field !== '' ? $field . ': ' . $message : $message;
            }
        }

        return null;
    }

    /** Single line, length capped, never an HTML document. */
    private static function sanitize(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return null;
        }

        if (preg_match('/<!doctype\s+html|<html[\s>]/i', $value) === 1) {
            return null;
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_LENGTH - 1) . '…';
        }

        return $value;
    }
}
