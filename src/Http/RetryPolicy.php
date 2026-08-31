<?php

declare(strict_types=1);

namespace Taxora\Sdk\Http;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Throwable;

/**
 * How the SDK retries transient failures.
 *
 * Only gateway-level failures are retried by default (502/503/504): those come
 * from the infrastructure in front of the API, not from the API itself, and a
 * second attempt a moment later usually succeeds. Everything else — 4xx, and
 * any answer the API produced itself — is surfaced immediately.
 *
 * On top of that, a 429 is retried when the API answers with a `Retry-After`
 * telling us how long to wait, and connection-level failures (reset connection,
 * client-side timeout, DNS hiccup) are retried as well — those never reached the
 * API's answer either.
 *
 * Retries are opt-in per operation and are only applied to read-only calls
 * (lookups, downloads, polling). Calls that change state or cost quota — booking
 * a transaction, starting an enrichment job, triggering a certificate export —
 * are never retried automatically, because a gateway timeout does not tell us
 * whether the API already processed the request.
 */
final class RetryPolicy
{
    /** @var list<int> */
    public const DEFAULT_RETRYABLE_STATUS_CODES = [502, 503, 504];

    /** Retried only when the response tells us how long to wait. */
    private const RETRY_AFTER_ONLY_STATUS_CODES = [429];

    /**
     * @param int $maxAttempts Total attempts including the first one (1 disables retrying).
     * @param list<int> $retryableStatusCodes
     * @param bool $respectRetryAfter Honour a `Retry-After` header instead of the backoff, and retry
     *                                 a 429 that carries one.
     * @param int $maxRetryAfterMs Longest wait we accept from `Retry-After`; a longer one means we
     *                             give up instead of blocking the caller for it.
     * @param bool $retryOnNetworkErrors Retry connection-level failures (PSR-18 transport errors).
     * @param Closure(int):void|null $sleeper Receives the delay in milliseconds; defaults to usleep().
     */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $initialDelayMs = 500,
        public readonly float $backoffMultiplier = 2.0,
        public readonly int $maxDelayMs = 5000,
        public readonly array $retryableStatusCodes = self::DEFAULT_RETRYABLE_STATUS_CODES,
        public readonly bool $respectRetryAfter = true,
        public readonly int $maxRetryAfterMs = 10_000,
        public readonly bool $retryOnNetworkErrors = true,
        private readonly ?Closure $sleeper = null,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be at least 1.');
        }
        if ($initialDelayMs < 0 || $maxDelayMs < 0) {
            throw new InvalidArgumentException('Delays must not be negative.');
        }
        if ($backoffMultiplier < 1.0) {
            throw new InvalidArgumentException('backoffMultiplier must be at least 1.0.');
        }
    }

    /** No retrying at all — every failure is surfaced on the first attempt. */
    public static function disabled(): self
    {
        return new self(maxAttempts: 1);
    }

    /** Retry immediately, without waiting (mainly useful in tests). */
    public static function withoutDelay(int $maxAttempts = 3): self
    {
        return new self(maxAttempts: $maxAttempts, initialDelayMs: 0, maxDelayMs: 0);
    }

    public function isRetryable(int $statusCode, ?string $retryAfterHeader = null): bool
    {
        $retryAfterMs = $this->retryAfterMs($retryAfterHeader);

        // The API asked for a longer pause than we are willing to block for:
        // hand the caller its 429/503 now instead of sitting on the request.
        if ($retryAfterMs !== null && $retryAfterMs > $this->maxRetryAfterMs) {
            return false;
        }

        if (in_array($statusCode, $this->retryableStatusCodes, true)) {
            return true;
        }

        return $retryAfterMs !== null && in_array($statusCode, self::RETRY_AFTER_ONLY_STATUS_CODES, true);
    }

    /** @param int $attempt Number of attempts made so far (1 = the first one just failed). */
    public function shouldRetry(int $statusCode, int $attempt, ?string $retryAfterHeader = null): bool
    {
        return $attempt < $this->maxAttempts && $this->isRetryable($statusCode, $retryAfterHeader);
    }

    /**
     * A connection-level failure: the request never got an answer, so repeating
     * it is as safe as repeating a gateway timeout. Malformed requests (PSR-18
     * RequestExceptionInterface) are a caller bug and are not retried.
     */
    public function isRetryableException(Throwable $exception): bool
    {
        return $this->retryOnNetworkErrors
            && $exception instanceof ClientExceptionInterface
            && !$exception instanceof RequestExceptionInterface;
    }

    public function shouldRetryException(Throwable $exception, int $attempt): bool
    {
        return $attempt < $this->maxAttempts && $this->isRetryableException($exception);
    }

    /**
     * The wait the API asked for, in milliseconds — seconds or HTTP-date form —
     * or null when there is none, it cannot be parsed, or the header is ignored.
     */
    public function retryAfterMs(?string $header, ?DateTimeImmutable $now = null): ?int
    {
        if (!$this->respectRetryAfter || $header === null) {
            return null;
        }

        $header = trim($header);
        if ($header === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $header) === 1) {
            return (int) $header * 1000;
        }

        // IMF-fixdate ("Mon, 31 Aug 2026 12:00:05 GMT"); DATE_RFC7231 itself is
        // deprecated as of PHP 8.5, so the format is spelled out here.
        $date = DateTimeImmutable::createFromFormat('D, d M Y H:i:s T', $header)
            ?: DateTimeImmutable::createFromFormat(DATE_RFC2822, $header);
        if ($date === false) {
            return null;
        }

        $seconds = $date->getTimestamp() - ($now ?? new DateTimeImmutable())->getTimestamp();

        return max(0, $seconds) * 1000;
    }

    /** The wait before the next attempt: what the API asked for, else exponential backoff. */
    public function delayMsForAttempt(int $attempt, ?string $retryAfterHeader = null): int
    {
        $retryAfterMs = $this->retryAfterMs($retryAfterHeader);
        if ($retryAfterMs !== null) {
            return min($retryAfterMs, $this->maxRetryAfterMs);
        }

        if ($this->initialDelayMs === 0) {
            return 0;
        }

        $factor = $this->backoffMultiplier ** (float) max(0, $attempt - 1);
        $delay = (int) round((float) $this->initialDelayMs * $factor);

        return max(0, min($delay, $this->maxDelayMs));
    }

    public function sleepBeforeRetry(int $attempt, ?string $retryAfterHeader = null): void
    {
        $delayMs = $this->delayMsForAttempt($attempt, $retryAfterHeader);
        if ($delayMs <= 0) {
            return;
        }

        if ($this->sleeper !== null) {
            ($this->sleeper)($delayMs);

            return;
        }

        usleep($delayMs * 1000);
    }
}
