<?php

declare(strict_types=1);

namespace Taxora\Sdk\Endpoints\Concerns;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Http\RetryPolicy;
use Throwable;

/**
 * Retries an operation while the gateway in front of the API keeps failing.
 *
 * Wrap only read-only operations in this: a 502/503/504 does not tell us whether
 * the API already processed the request, so anything that changes state or costs
 * quota must fail fast instead.
 */
trait RetriesTransientErrors
{
    private readonly RetryPolicy $retryPolicy;

    /**
     * @param string $operation Short label used in the final error message, e.g. "VAT validation".
     * @param Closure():mixed $request
     */
    private function withRetries(string $operation, Closure $request): mixed
    {
        $attempt = 1;

        while (true) {
            try {
                return $request();
            } catch (HttpException $exception) {
                $retryAfter = $exception->getRetryAfter();

                if (!$this->retryPolicy->shouldRetry($exception->getStatusCode(), $attempt, $retryAfter)) {
                    throw $attempt > 1 && $this->retryPolicy->isRetryable($exception->getStatusCode(), $retryAfter)
                        ? HttpException::afterAttempts($operation, $attempt, $exception)
                        : $exception;
                }

                $this->retryPolicy->sleepBeforeRetry($attempt, $retryAfter);
                $attempt++;
            } catch (Throwable $exception) {
                // Connection reset, client-side timeout, DNS hiccup: no answer ever
                // arrived, so this is as safe to repeat as a gateway timeout. The
                // transport exception is rethrown unchanged once we give up, so
                // callers keep seeing their HTTP client's own error type.
                if (!$this->retryPolicy->shouldRetryException($exception, $attempt)) {
                    throw $exception;
                }

                $this->retryPolicy->sleepBeforeRetry($attempt);
                $attempt++;
            }
        }
    }

    /** Raw `Retry-After` of a response, when it carried one. */
    private function retryAfterOf(ResponseInterface $response): ?string
    {
        $value = trim($response->getHeaderLine('Retry-After'));

        return $value === '' ? null : $value;
    }
}
