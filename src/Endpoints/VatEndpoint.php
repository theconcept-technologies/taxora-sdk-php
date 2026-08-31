<?php

declare(strict_types=1);

namespace Taxora\Sdk\Endpoints;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Enums\Language;
use Taxora\Sdk\Endpoints\Concerns\RetriesTransientErrors;
use Taxora\Sdk\Exceptions\AuthenticationException;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Exceptions\ValidationException;
use Taxora\Sdk\Http\ApiKeyMiddleware;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\RetryPolicy;
use Taxora\Sdk\Http\TokenStorageInterface;
use Taxora\Sdk\ValueObjects\VatValidationAddressInput;
use Taxora\Sdk\ValueObjects\VatResource;
use Taxora\Sdk\ValueObjects\VatCertificateExport;
use Taxora\Sdk\ValueObjects\VatCollection;
use Throwable;

final class VatEndpoint
{
    use RetriesTransientErrors;

    private readonly Closure $refreshCallback;

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
        ?RetryPolicy $retryPolicy = null
    ) {
        $this->refreshCallback = Closure::fromCallable($refreshCallback);
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy();
    }

    /** Validate a single VAT → VatResource */
    public function validate(
        string $vatUid,
        ?string $companyName = null,
        ?string $provider = null,
        VatValidationAddressInput|array|null $addressInput = null
    ): VatResource {
        $uri = $this->uri('/vat/validate');
        $body = array_filter([
            'vat_uid' => $vatUid,
            'company_name' => $companyName,
            'provider' => $provider,
        ], fn ($v) => $v !== null);
        $body = [...$body, ...$this->normalizeAddressInput($addressInput)];

        $payload = $this->withRetries('VAT validation', fn () => $this->jsonPost($uri, $body));
        $data = $this->extractData($payload);
        if ($companyName !== null && !isset($data['requested_company_name'])) {
            $data['requested_company_name'] = $companyName;
        }

        return VatResource::fromArray($data);
    }

    /** Validate multiple VATs → VatCollection */
    public function validateMultiple(array $vatUids, ?array $companyNames = null, ?string $provider = null): VatCollection
    {
        $uri = $this->uri('/vat/validate-multiple');
        $body = array_filter([
            'vat_uids'      => array_values($vatUids),
            'company_names' => $companyNames !== null ? array_values($companyNames) : null,
            'provider'      => $provider,
        ], fn ($v) => $v !== null);

        $payload = $this->withRetries('VAT validation', fn () => $this->jsonPost($uri, $body));
        if ($companyNames !== null) {
            $companyNames = array_values($companyNames);
            if (isset($payload['data']) && is_array($payload['data'])) {
                foreach ($payload['data'] as $index => $row) {
                    if (!isset($companyNames[$index]) || !is_array($row) || isset($row['requested_company_name'])) {
                        continue;
                    }
                    $payload['data'][$index]['requested_company_name'] = $companyNames[$index];
                }
            } elseif (array_is_list($payload)) {
                foreach ($payload as $index => $row) {
                    if (!isset($companyNames[$index]) || !is_array($row) || isset($row['requested_company_name'])) {
                        continue;
                    }
                    $payload[$index]['requested_company_name'] = $companyNames[$index];
                }
            }
        }

        return VatCollection::fromResponse($payload);
    }

    /** Quick format check for one VAT (returns raw array) */
    public function validateSchema(string $vatUid): array
    {
        $uri = $this->uri('/vat/validate-schema');

        return $this->withRetries('VAT schema check', fn () => $this->jsonPost($uri, ['vat_uid' => $vatUid]));
    }

    /** Current state for one VAT → VatResource */
    public function state(string $vatUid): VatResource
    {
        $uri = $this->uri('/vat/state');
        $payload = $this->withRetries('VAT state lookup', fn () => $this->jsonPost($uri, ['vat_uid' => $vatUid]));
        $payload = $this->extractData($payload);

        return VatResource::fromArray($payload);
    }

    /** History (optionally filtered by VAT) → VatCollection */
    public function history(?string $vatUid = null): VatCollection
    {
        $uri = $this->uri('/vat/history');
        $body = [];
        if ($vatUid !== null) {
            $body = ['vat_uid' => $vatUid];
        }

        $r = $this->withRetries('VAT history request', fn () => $this->jsonPost($uri, $body));

        return VatCollection::fromResponse($r);
    }

    /** Search → VatCollection */
    public function search(?string $term = null, ?int $perPage = null): VatCollection
    {
        $q = [];
        if ($term !== null) {
            $q['search']  = $term;
        }
        if ($perPage !== null) {
            $q['perPage'] = $perPage;
        }
        $uri = $this->uri('/vat/search');
        $r = $this->withRetries('VAT search', fn () => $this->jsonPost($uri, $q));

        return VatCollection::fromResponse($r);
    }

    /** Certificate PDF (binary string) */
    public function certificate(string $uuid, ?Language $lang = null): string
    {
        $uri = $this->uri('/vat/certificate');
        $body = array_filter(['uuid' => $uuid, 'lang' => $lang?->value], fn ($v) => $v !== null);
        return $this->withRetries('VAT certificate download', fn () => $this->binaryPost($uri, $body));
    }

    /** Bulk export (returns export_id etc.) */
    public function certificatesBulkExport(DateTimeInterface|string $fromDate, DateTimeInterface|string $toDate, ?array $countries = null, ?Language $lang = null): VatCertificateExport
    {
        $uri = $this->uri('/vat/certificates/bulk-export');
        $body = array_filter([
            'from_date' => $this->formatDate($fromDate),
            'to_date'   => $this->formatDate($toDate),
            'countries' => $countries,
            'lang'      => $lang?->value,
        ], fn ($v) => $v !== null);
        $payload = $this->jsonPost($uri, $body); // 202 Accepted with export_id
        $data = $this->extractData($payload);

        return VatCertificateExport::fromArray($data);
    }

    /** List export (returns export_id etc.) */
    public function certificatesListExport(DateTimeInterface|string $fromDate, DateTimeInterface|string $toDate, ?array $countries = null, ?Language $lang = null): VatCertificateExport
    {
        $uri = $this->uri('/vat/certificates/list-export');
        $body = array_filter([
            'from_date' => $this->formatDate($fromDate),
            'to_date'   => $this->formatDate($toDate),
            'countries' => $countries,
            'lang'      => $lang?->value,
        ], fn ($v) => $v !== null);
        $payload = $this->jsonPost($uri, $body); // 202 Accepted with export_id
        $data = $this->extractData($payload);

        return VatCertificateExport::fromArray($data);
    }

    /** Bulk download ZIP or PDF (binary string) */
    public function downloadBulkExport(string $exportId): string
    {
        $uri = $this->uri('/vat/certificates/download/' . rawurlencode($exportId));

        return $this->withRetries('VAT export download', fn () => $this->binaryGet($uri));
    }

    /* ---------- internals (shared) ---------- */

    private function uri(string $path): string
    {
        return sprintf('%s/%s%s', $this->baseUrl, $this->apiVersion->value, $path);
    }

    private function jsonGet(string $uri): array
    {
        $response = $this->send(fn () => $this->req->createRequest('GET', $uri));
        $code = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($code !== 200) {
            throw HttpException::fromResponse($code, $body, retryAfter: $this->retryAfterOf($response));
        }

        $payload = $this->decodeJson($body, $code);
        $this->assertSuccessful($payload, $code, $body);

        return $payload;
    }

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
            throw HttpException::fromResponse($code, $body, retryAfter: $this->retryAfterOf($response));
        }

        $payload = $this->decodeJson($body, $code);
        $this->assertSuccessful($payload, $code, $body);

        return $payload;
    }

    private function binaryGet(string $uri): string
    {
        $response = $this->send(fn () => $this->req->createRequest('GET', $uri));
        $code = $response->getStatusCode();
        if ($code !== 200) {
            throw HttpException::fromResponse($code, (string) $response->getBody(), retryAfter: $this->retryAfterOf($response));
        }
        return (string) $response->getBody();
    }

    private function binaryPost(string $uri, array $body): string
    {
        $json = $this->encodeJsonBody($body);
        $response = $this->send(function () use ($uri, $json) {
            return $this->req->createRequest('POST', $uri)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->stream->createStream($json));
        });

        $code = $response->getStatusCode();
        if ($code !== 200) {
            throw HttpException::fromResponse($code, (string) $response->getBody(), retryAfter: $this->retryAfterOf($response));
        }
        return (string) $response->getBody();
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
            throw AuthenticationException::fromResponse($body);
        }

        try {
            ($this->refreshCallback)();
        } catch (Throwable $exception) {
            throw AuthenticationException::refreshFailed($body, $exception);
        }
    }

    /** @param array<string,mixed> $payload */
    private function extractData(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return $payload;
    }

    private function decodeJson(string $body, int $statusCode): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new HttpException('Failed to decode JSON response from Taxora API.', $statusCode, $body);
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $payload
     */
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

    private function formatDate(DateTimeInterface|string $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Date string cannot be empty.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Date string must be in Y-m-d format.');
        }

        return $value;
    }

    /** @param VatValidationAddressInput|array<string,mixed>|null $addressInput */
    private function normalizeAddressInput(VatValidationAddressInput|array|null $addressInput): array
    {
        if ($addressInput === null) {
            return [];
        }

        if ($addressInput instanceof VatValidationAddressInput) {
            return $addressInput->toArray();
        }

        return VatValidationAddressInput::fromArray($addressInput)->toArray();
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
