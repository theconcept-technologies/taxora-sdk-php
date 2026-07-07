<?php

declare(strict_types=1);

namespace Taxora\Sdk\Endpoints;

use Closure;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Enums\SmartEnrichmentStatus;
use Taxora\Sdk\Exceptions\AuthenticationException;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Exceptions\TimeoutException;
use Taxora\Sdk\Exceptions\ValidationException;
use Taxora\Sdk\Http\ApiKeyMiddleware;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\TokenStorageInterface;
use Taxora\Sdk\ValueObjects\SmartEnrichmentHistoryPage;
use Taxora\Sdk\ValueObjects\SmartEnrichmentJob;
use Taxora\Sdk\ValueObjects\SmartEnrichmentStatistics;
use Taxora\Sdk\ValueObjects\SmartEnrichmentUsage;
use Throwable;

/**
 * Smart Enrichment — reverse VAT lookup (company name + address + country → VAT + confidence).
 *
 *   $job = $client->smartEnrichment()->lookup('Example GmbH', 'AT', street: 'Main Street 10', city: 'Vienna');
 *   if ($job->isProcessing()) { $job = $client->smartEnrichment()->get($job->jobId); }
 *   echo $job->result()?->vatNumber;
 */
final class SmartEnrichmentEndpoint
{
    private const INTERVALS = ['day', 'week', 'month'];

    private readonly Closure $refreshCallback;

    /** @var Closure(float):void */
    private readonly Closure $sleep;

    /**
     * @param callable(float):void|null $sleep sleep function used by waitUntilComplete()
     *                                         (injectable for tests; defaults to usleep)
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $req,
        private readonly StreamFactoryInterface $stream,
        private readonly ApiKeyMiddleware $apiKey,
        private readonly AuthMiddleware $auth,
        private readonly TokenStorageInterface $tokens,
        callable $refreshCallback,
        private readonly string $baseUrl,
        private readonly ApiVersion $apiVersion = ApiVersion::V1,
        ?callable $sleep = null
    ) {
        $this->refreshCallback = Closure::fromCallable($refreshCallback);
        $this->sleep = $sleep !== null
            ? Closure::fromCallable($sleep)
            : static function (float $seconds): void {
                usleep((int) round($seconds * 1_000_000.0));
            };
    }

    /**
     * Single reverse lookup. Returns a confident result synchronously, or a `processing`
     * job (poll with get($job->jobId)) when deeper resolution is needed.
     */
    public function lookup(
        string $companyName,
        string $country,
        ?string $street = null,
        ?string $postalCode = null,
        ?string $city = null
    ): SmartEnrichmentJob {
        $this->assertLookupInput($companyName, $country);

        $uri = $this->uri('/smart-enrichment');
        $body = array_filter([
            'companyName' => $companyName,
            'country' => $country,
            'street' => $street,
            'postalCode' => $postalCode,
            'city' => $city,
        ], static fn ($v) => $v !== null);

        $payload = $this->jsonPost($uri, $body);

        return SmartEnrichmentJob::fromArray($this->extractData($payload));
    }

    /**
     * Bulk batch — always async. Returns a `processing` job; results arrive via the
     * `enrichment.completed` webhook and are retrievable via get($job->jobId).
     *
     * @param list<array<string,mixed>> $items each: companyName, country, [street, postalCode, city]
     */
    public function bulkLookup(array $items): SmartEnrichmentJob
    {
        if ($items === []) {
            throw new InvalidArgumentException('bulkLookup requires at least one item.');
        }

        foreach ($items as $index => $item) {
            try {
                $this->assertLookupInput(
                    is_string($item['companyName'] ?? null) ? $item['companyName'] : '',
                    is_string($item['country'] ?? null) ? $item['country'] : ''
                );
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException(sprintf('items[%d]: %s', $index, $exception->getMessage()), 0, $exception);
            }
        }

        $uri = $this->uri('/smart-enrichment/bulk');
        $payload = $this->jsonPost($uri, ['items' => $items]);

        return SmartEnrichmentJob::fromArray($this->extractData($payload));
    }

    /** Poll a job (single or bulk) by its id. */
    public function get(string $jobId): SmartEnrichmentJob
    {
        if (trim($jobId) === '') {
            throw new InvalidArgumentException('jobId must not be empty.');
        }

        $uri = $this->uri('/smart-enrichment/' . rawurlencode($jobId));
        $payload = $this->jsonGet($uri);

        return SmartEnrichmentJob::fromArray($this->extractData($payload));
    }

    /**
     * Poll get($jobId) until the job leaves its processing/queued state, sleeping
     * $pollIntervalSeconds between polls. Throws a TimeoutException once the accumulated
     * wait would exceed $timeoutSeconds — the job keeps running server-side and can
     * still be polled afterwards.
     *
     * @throws TimeoutException when the job is still processing after the timeout
     */
    public function waitUntilComplete(
        string $jobId,
        float $timeoutSeconds = 120.0,
        float $pollIntervalSeconds = 2.0
    ): SmartEnrichmentJob {
        if ($timeoutSeconds <= 0.0) {
            throw new InvalidArgumentException('timeoutSeconds must be greater than 0.');
        }
        if ($pollIntervalSeconds <= 0.0) {
            throw new InvalidArgumentException('pollIntervalSeconds must be greater than 0.');
        }

        $waited = 0.0;
        $job = $this->get($jobId);

        while ($job->isProcessing()) {
            if ($waited + $pollIntervalSeconds > $timeoutSeconds) {
                throw new TimeoutException(sprintf(
                    'Smart Enrichment job "%s" did not complete within %.1f seconds (still %s).',
                    $jobId,
                    $timeoutSeconds,
                    $job->status->value
                ));
            }

            ($this->sleep)($pollIntervalSeconds);
            $waited += $pollIntervalSeconds;
            $job = $this->get($jobId);
        }

        return $job;
    }

    /**
     * Paginated lookup history (newest first), optionally filtered by a free-text
     * search over the queried company name and the resolved VAT number / matched name.
     * The server caps $perPage at 100.
     */
    public function history(int $page = 1, int $perPage = 25, ?string $search = null): SmartEnrichmentHistoryPage
    {
        $uri = $this->uri('/smart-enrichment/history' . $this->buildQuery([
            'page' => (string) $page,
            'perPage' => (string) $perPage,
            'search' => $search,
        ]));
        $payload = $this->jsonGet($uri);

        return SmartEnrichmentHistoryPage::fromArray($payload);
    }

    /** Current billing-period quota usage. */
    public function usage(): SmartEnrichmentUsage
    {
        $payload = $this->jsonGet($this->uri('/smart-enrichment/usage'));

        return SmartEnrichmentUsage::fromArray($this->extractData($payload));
    }

    /**
     * Download the company's lookups as a CSV (UTF-8 with BOM) in the bulk-input shape
     * plus the resolved VAT columns. All filters are optional: $dateFrom/$dateTo (Y-m-d,
     * on the job's creation date), $minConfidence (0–100), $status (item statuses:
     * found / no_vat_exists / not_found) and $onlyFound (rows with a VAT number only).
     *
     * @param list<SmartEnrichmentStatus|string>|null $status
     * @return string raw CSV bytes
     */
    public function export(
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?float $minConfidence = null,
        ?array $status = null,
        ?bool $onlyFound = null
    ): string {
        $pairs = $this->queryPairs([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'min_confidence' => $minConfidence !== null ? (string) $minConfidence : null,
        ]);

        foreach ($status ?? [] as $value) {
            $value = $value instanceof SmartEnrichmentStatus ? $value->value : $value;
            $pairs[] = rawurlencode('status[]') . '=' . rawurlencode($value);
        }

        if ($onlyFound !== null) {
            $pairs[] = 'only_found=' . ($onlyFound ? '1' : '0');
        }

        $uri = $this->uri('/smart-enrichment/export' . ($pairs === [] ? '' : '?' . implode('&', $pairs)));

        return $this->csvGet($uri);
    }

    /**
     * Aggregated lookup statistics: headline totals, a time series and breakdowns by
     * source, outcome and confidence band. Defaults (server-side) to the last 12 months
     * and a monthly interval. $interval is one of 'day', 'week' or 'month'.
     */
    public function statistics(
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $interval = null
    ): SmartEnrichmentStatistics {
        if ($interval !== null && !in_array($interval, self::INTERVALS, true)) {
            throw new InvalidArgumentException(sprintf(
                'interval must be one of "%s", got "%s".',
                implode('", "', self::INTERVALS),
                $interval
            ));
        }

        $uri = $this->uri('/smart-enrichment/statistics' . $this->buildQuery([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'interval' => $interval,
        ]));
        $payload = $this->jsonGet($uri);

        return SmartEnrichmentStatistics::fromArray($this->extractData($payload));
    }

    // ---------------------------------------------------------------------
    // Input validation
    // ---------------------------------------------------------------------

    private function assertLookupInput(string $companyName, string $country): void
    {
        if (trim($companyName) === '') {
            throw new InvalidArgumentException('companyName must not be empty.');
        }
        if (strlen($country) !== 2) {
            throw new InvalidArgumentException('country must be an ISO 3166-1 alpha-2 code (exactly 2 characters).');
        }
    }

    // ---------------------------------------------------------------------
    // HTTP plumbing (kept in sync with VatEndpoint)
    // ---------------------------------------------------------------------

    private function uri(string $path): string
    {
        return sprintf('%s/%s%s', $this->baseUrl, $this->apiVersion->value, $path);
    }

    /**
     * Encoded key=value pairs for every non-null parameter (insertion order preserved).
     *
     * @param array<string,string|null> $params
     * @return list<string>
     */
    private function queryPairs(array $params): array
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value !== null) {
                $pairs[] = rawurlencode($key) . '=' . rawurlencode($value);
            }
        }

        return $pairs;
    }

    /**
     * Query string ('?…' or '') from non-null parameters.
     *
     * @param array<string,string|null> $params
     */
    private function buildQuery(array $params): string
    {
        $pairs = $this->queryPairs($params);

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    /** @return array<string,mixed> */
    private function jsonGet(string $uri): array
    {
        $response = $this->send(fn () => $this->req->createRequest('GET', $uri));
        $code = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($code !== 200) {
            throw new HttpException($body, $code, $body);
        }

        $payload = $this->decodeJson($body, $code);
        $this->assertSuccessful($payload, $code, $body);

        return $payload;
    }

    /**
     * GET returning a raw (non-JSON) body such as a CSV download. Runs the same
     * auth/401-refresh flow as jsonGet but skips JSON decoding on success.
     */
    private function csvGet(string $uri): string
    {
        $response = $this->send(
            fn () => $this->req->createRequest('GET', $uri)->withHeader('Accept', 'text/csv, application/json'),
        );
        $code = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($code === 422) {
            throw ValidationException::fromResponseBody($body);
        }
        if ($code !== 200) {
            throw new HttpException($body, $code, $body);
        }

        return $body;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function jsonPost(string $uri, array $body): array
    {
        $json = $this->encodeJsonBody($body);
        $response = $this->send(function () use ($uri, $json) {
            return $this->req->createRequest('POST', $uri)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->stream->createStream($json));
        });

        $code = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($code === 422) {
            throw ValidationException::fromResponseBody($body);
        }
        if ($code !== 200 && $code !== 202) {
            throw new HttpException($body, $code, $body);
        }

        $payload = $this->decodeJson($body, $code);
        $this->assertSuccessful($payload, $code, $body);

        return $payload;
    }

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
                throw new AuthenticationException((string) $response->getBody(), 401);
            }

            $this->handleUnauthorized((string) $response->getBody());
        }
    }

    private function ensureValidToken(): void
    {
        $token = $this->tokens->get();
        if ($token !== null && $token->isExpired()) {
            $this->refreshTokenOrFail('Token expired before request');
        }
    }

    private function handleUnauthorized(string $body): void
    {
        $this->refreshTokenOrFail($body);
    }

    private function refreshTokenOrFail(string $body): void
    {
        if ($this->tokens->get() === null) {
            throw new AuthenticationException($body, 401);
        }

        try {
            ($this->refreshCallback)();
        } catch (Throwable $exception) {
            throw new AuthenticationException('Unauthorized and refresh failed: ' . $body, 401, $exception);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function extractData(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $body, int $statusCode): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new HttpException('Failed to decode JSON response from Taxora API.', $statusCode, $body);
        }

        return $decoded;
    }

    /** @param array<string,mixed> $payload */
    private function assertSuccessful(array $payload, int $statusCode, string $body): void
    {
        if (!array_key_exists('success', $payload)) {
            return;
        }

        if ($this->isTruthySuccess($payload['success'] ?? null)) {
            return;
        }

        $message = isset($payload['message']) && is_string($payload['message'])
            ? $payload['message']
            : 'Taxora API indicated a failed response.';

        throw new HttpException($message, $statusCode, $body);
    }

    private function isTruthySuccess(mixed $success): bool
    {
        if (is_bool($success)) {
            return $success;
        }
        if (is_int($success)) {
            return $success === 1;
        }
        if (is_string($success)) {
            $normalized = strtolower($success);
            return $normalized === '1' || $normalized === 'true';
        }

        return false;
    }

    /** @param array<string,mixed> $body */
    private function encodeJsonBody(array $body): string
    {
        try {
            return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Failed to encode request body as JSON: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
