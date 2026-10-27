<?php

declare(strict_types=1);

namespace Taxora\Sdk\Endpoints;

use Closure;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Exceptions\AuthenticationException;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Exceptions\ValidationException;
use Taxora\Sdk\Http\ApiKeyMiddleware;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\RetryPolicy;
use Taxora\Sdk\Http\TokenStorageInterface;
use Taxora\Sdk\ValueObjects\UsageSummary;
use Taxora\Sdk\ValueObjects\UsageSummaryPage;
use Throwable;

/**
 * Billing: read-only usage summaries of the company's billable modules
 * (e-reporting / e-invoicing transactions, Smart Enrichment checks) per billing
 * period. Requires a billable module (403 otherwise).
 *
 *   $page    = $client->billing()->listUsageSummaries();
 *   $details = $client->billing()->getUsageSummaries('2026-09'); // with events
 */
final class BillingEndpoint
{
    use Concerns\RetriesTransientErrors;

    private readonly Closure $refreshCallback;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $req,
        private readonly ApiKeyMiddleware $apiKey,
        private readonly AuthMiddleware $auth,
        private readonly TokenStorageInterface $tokens,
        callable $refreshCallback,
        private readonly string $baseUrl,
        private readonly ApiVersion $apiVersion = ApiVersion::V1,
        ?RetryPolicy $retryPolicy = null,
    ) {
        $this->refreshCallback = Closure::fromCallable($refreshCallback);
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy();
    }

    /**
     * Paginated usage summaries, newest billing period first. The server clamps
     * $perPage to 1-100.
     */
    public function listUsageSummaries(int $page = 1, int $perPage = 25): UsageSummaryPage
    {
        $uri = sprintf(
            '%s/%s/billing/usage-summaries?page=%d&per_page=%d',
            $this->baseUrl,
            $this->apiVersion->value,
            max(1, $page),
            $perPage,
        );

        return UsageSummaryPage::fromArray($this->extractData($this->jsonGet($uri)));
    }

    /**
     * Every usage summary of one billing period (YYYY-MM), one per feature, with
     * its usage events. A period without usage yields an {@see HttpException}
     * with status 404.
     *
     * @return list<UsageSummary>
     */
    public function getUsageSummaries(string $period): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new InvalidArgumentException('period must be a billing period in YYYY-MM format, e.g. 2026-09.');
        }

        $uri = sprintf('%s/%s/billing/usage-summaries/%s', $this->baseUrl, $this->apiVersion->value, $period);
        $payload = $this->jsonGet($uri);
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

        $summaries = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                /** @var array<string,mixed> $row */
                $summaries[] = UsageSummary::fromArray($row);
            }
        }

        return $summaries;
    }

    // ---------------------------------------------------------------------
    // HTTP plumbing (kept in sync with EReportingEndpoint)
    // ---------------------------------------------------------------------

    /**
     * Read-only, so a transient gateway failure is retried (see RetryPolicy).
     *
     * @return array<string,mixed>
     */
    private function jsonGet(string $uri): array
    {
        /** @var array<string,mixed> */
        return $this->withRetries('billing request', function () use ($uri): array {
            $response = $this->send(fn () => $this->req->createRequest('GET', $uri));
            $code = $response->getStatusCode();
            $body = (string) $response->getBody();

            if ($code === 422) {
                throw ValidationException::fromResponseBody($body);
            }
            if ($code !== 200) {
                throw HttpException::fromResponse($code, $body, retryAfter: $this->retryAfterOf($response));
            }

            $payload = json_decode($body, true);
            if (!is_array($payload)) {
                throw new HttpException('Failed to decode JSON response from Taxora API.', $code, $body);
            }
            /** @var array<string,mixed> $payload */
            if (array_key_exists('success', $payload) && !in_array($payload['success'], [true, 1, '1', 'true'], true)) {
                $message = isset($payload['message']) && is_string($payload['message'])
                    ? $payload['message']
                    : 'Taxora API indicated a failed response.';
                throw new HttpException($message, $code, $body);
            }

            return $payload;
        });
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function extractData(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            /** @var array<string,mixed> */
            return $payload['data'];
        }

        return $payload;
    }

    /** @param callable():\Psr\Http\Message\RequestInterface $factory */
    private function send(callable $factory): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            $this->ensureValidToken();

            $request = $factory();
            $request = ($this->apiKey)($request);
            $request = ($this->auth)($request);

            $response = $this->http->sendRequest($request);
            if ($response->getStatusCode() !== 401) {
                return $response;
            }

            if ($attempt++ >= 1) {
                throw AuthenticationException::fromResponse((string) $response->getBody());
            }

            $this->refreshTokenOrFail((string) $response->getBody());
        }
    }

    private function ensureValidToken(): void
    {
        $token = $this->tokens->get();
        if ($token !== null && $token->isExpired()) {
            $this->refreshTokenOrFail('Token expired before request');
        }
    }

    private function refreshTokenOrFail(string $body): void
    {
        if ($this->tokens->get() === null) {
            throw AuthenticationException::fromResponse($body);
        }

        try {
            ($this->refreshCallback)();
        } catch (Throwable $exception) {
            throw AuthenticationException::refreshFailed($body, $exception);
        }
    }
}
