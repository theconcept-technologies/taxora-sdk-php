<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Http\RetryPolicy;
use Taxora\Sdk\Tests\Fixtures\FakeNetworkException;

final class RetryPolicyTest extends TestCase
{
    public function testDefaultsRetryGatewayErrorsOnly(): void
    {
        $policy = new RetryPolicy();

        self::assertTrue($policy->isRetryable(502));
        self::assertTrue($policy->isRetryable(503));
        self::assertTrue($policy->isRetryable(504));

        // Answers the API produced itself are never retried.
        self::assertFalse($policy->isRetryable(500));
        self::assertFalse($policy->isRetryable(429));
        self::assertFalse($policy->isRetryable(422));
        self::assertFalse($policy->isRetryable(404));
    }

    public function testShouldRetryStopsAtMaxAttempts(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3);

        self::assertTrue($policy->shouldRetry(504, 1));
        self::assertTrue($policy->shouldRetry(504, 2));
        self::assertFalse($policy->shouldRetry(504, 3));
        self::assertFalse($policy->shouldRetry(400, 1));
    }

    public function testBackoffGrowsAndIsCapped(): void
    {
        $policy = new RetryPolicy(initialDelayMs: 500, backoffMultiplier: 2.0, maxDelayMs: 5000);

        self::assertSame(500, $policy->delayMsForAttempt(1));
        self::assertSame(1000, $policy->delayMsForAttempt(2));
        self::assertSame(2000, $policy->delayMsForAttempt(3));
        self::assertSame(4000, $policy->delayMsForAttempt(4));
        self::assertSame(5000, $policy->delayMsForAttempt(5));
        self::assertSame(5000, $policy->delayMsForAttempt(50));
    }

    public function testDisabledPolicyNeverRetries(): void
    {
        $policy = RetryPolicy::disabled();

        self::assertSame(1, $policy->maxAttempts);
        self::assertFalse($policy->shouldRetry(504, 1));
    }

    public function testWithoutDelayDoesNotSleep(): void
    {
        $policy = RetryPolicy::withoutDelay();

        self::assertTrue($policy->shouldRetry(504, 1));
        self::assertSame(0, $policy->delayMsForAttempt(1));
    }

    public function testSleeperReceivesTheComputedDelay(): void
    {
        $slept = [];
        $policy = new RetryPolicy(sleeper: static function (int $ms) use (&$slept): void {
            $slept[] = $ms;
        });

        $policy->sleepBeforeRetry(1);
        $policy->sleepBeforeRetry(2);

        self::assertSame([500, 1000], $slept);
    }

    public function testRetryAfterMakes429Retryable(): void
    {
        $policy = new RetryPolicy();

        // Without a Retry-After we would only hammer a rate limit that is already tripped.
        self::assertFalse($policy->isRetryable(429));
        self::assertTrue($policy->isRetryable(429, '2'));
    }

    public function testRetryAfterBeyondTheCapStopsRetrying(): void
    {
        $policy = new RetryPolicy(maxRetryAfterMs: 10_000);

        // Blocking the caller for a minute is worse than handing them the error.
        self::assertFalse($policy->isRetryable(429, '60'));
        self::assertFalse($policy->isRetryable(503, '60'));
        self::assertTrue($policy->isRetryable(503, '5'));
    }

    public function testRetryAfterOverridesTheBackoff(): void
    {
        $policy = new RetryPolicy();

        self::assertSame(2000, $policy->delayMsForAttempt(1, '2'));
        self::assertSame(500, $policy->delayMsForAttempt(1, null));
        self::assertSame(500, $policy->delayMsForAttempt(1, 'not-a-date'));
    }

    public function testRetryAfterAcceptsSecondsAndHttpDates(): void
    {
        $policy = new RetryPolicy();
        $now = new DateTimeImmutable('2026-08-31T12:00:00+00:00');

        self::assertSame(3000, $policy->retryAfterMs('3', $now));
        self::assertSame(5000, $policy->retryAfterMs('Mon, 31 Aug 2026 12:00:05 GMT', $now));
        // A date in the past means "go ahead now".
        self::assertSame(0, $policy->retryAfterMs('Mon, 31 Aug 2026 11:59:00 GMT', $now));
        self::assertNull($policy->retryAfterMs('   ', $now));
        self::assertNull($policy->retryAfterMs('soon', $now));
        self::assertNull($policy->retryAfterMs(null, $now));
    }

    public function testRetryAfterCanBeIgnored(): void
    {
        $policy = new RetryPolicy(respectRetryAfter: false);

        self::assertNull($policy->retryAfterMs('2'));
        self::assertFalse($policy->isRetryable(429, '2'));
        // …and a long Retry-After no longer stops a retry that the status alone allows.
        self::assertTrue($policy->isRetryable(503, '60'));
    }

    public function testNetworkErrorsAreRetryableButMalformedRequestsAreNot(): void
    {
        $policy = new RetryPolicy();
        $request = new Request('POST', 'https://sandbox.taxora.io/v1/vat/validate');

        self::assertTrue($policy->isRetryableException(new FakeNetworkException($request)));
        self::assertFalse($policy->isRetryableException(new RuntimeException('boom')));

        $off = new RetryPolicy(retryOnNetworkErrors: false);
        self::assertFalse($off->isRetryableException(new FakeNetworkException($request)));
    }

    public function testRejectsInvalidConfiguration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RetryPolicy(maxAttempts: 0);
    }
}
