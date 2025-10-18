<?php
declare(strict_types=1);

namespace Taxora\Sdk\Endpoints;

use DateTimeImmutable;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Exceptions\AuthenticationException;
use Taxora\Sdk\Exceptions\HttpException;
use Taxora\Sdk\Http\ApiKeyMiddleware;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\Token;
use Taxora\Sdk\Http\TokenStorageInterface;

final class AuthEndpoint
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $req,
        private readonly StreamFactoryInterface $stream,
        private readonly ApiKeyMiddleware $apiKey,
        private readonly AuthMiddleware $auth,
        private readonly TokenStorageInterface $store,
        private readonly string $baseUrl,
        private readonly ApiVersion $apiVersion = ApiVersion::V1
    ) {
    }

    /** Perform login and persist the received token. */
    public function login(string $email, string $password, ?string $device = null): Token
    {
        $uri = sprintf('%s/api/%s/login', $this->baseUrl, $this->apiVersion->value);
        $body = [
            'email' => $email,
            'password' => $password,
            'device_name' => $this->resolveDevice($device),
        ];

        /** @psalm-suppress PossiblyFalseArgument */
        $req = $this->req->createRequest('POST', $uri)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->stream->createStream(json_encode($body, JSON_UNESCAPED_SLASHES)));

        $req = ($this->apiKey)($req);
        // Login does not include bearer header

        $res = $this->http->sendRequest($req);
        $status = $res->getStatusCode();
        if ($status === 401) {
            throw new AuthenticationException((string)$res->getBody(), 401);
        }
        if ($status !== 200) {
            throw new HttpException((string)$res->getBody(), $status, (string)$res->getBody());
        }

        $payload = (array) json_decode((string)$res->getBody(), true);
        if (!$this->isSuccessfulResponse($payload)) {
            throw new AuthenticationException('Authentication failed.', 401);
        }
        $token = $this->hydrateToken($payload);
        $this->store->set($token);

        return $token;
    }

    private static ?string $defaultDeviceId = null;

    /** Refresh the token using the current bearer token. */
    public function refresh(): Token
    {
        $uri = sprintf('%s/api/%s/refresh', $this->baseUrl, $this->apiVersion->value);
        $req = $this->req->createRequest('POST', $uri)
            ->withHeader('Content-Type', 'application/json');

        $req = ($this->apiKey)($req);
        $req = ($this->auth)($req);

        $res = $this->http->sendRequest($req);
        $status = $res->getStatusCode();
        if ($status === 401) {
            throw new AuthenticationException((string)$res->getBody(), 401);
        }
        if ($status !== 200) {
            throw new HttpException((string)$res->getBody(), $status, (string)$res->getBody());
        }

        $payload = (array) json_decode((string)$res->getBody(), true);
        $token = $this->hydrateToken($payload);
        $this->store->set($token);

        return $token;
    }

    private function hydrateToken(array $payload): Token
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        $accessToken = (string)($payload['access_token'] ?? '');
        $accessToken = preg_replace('/^Bearer\s+/i', '', $accessToken) ?? $accessToken;
        $tokenType = (string)($payload['token_type'] ?? 'Bearer');
        $expiresIn = (int)($payload['expires_in'] ?? 3600);

        return new Token(
            accessToken: $accessToken,
            tokenType: $tokenType,
            expiresAt: (new DateTimeImmutable())->modify('+'.$expiresIn.' seconds')
        );
    }

    private function resolveDevice(?string $device): string
    {
        $device = trim($device ?? '');
        if ($device !== '') {
            return $device;
        }

        if (self::$defaultDeviceId !== null) {
            return self::$defaultDeviceId;
        }

        $hostname = gethostname();
        if (!is_string($hostname) || $hostname === '') {
            $hostname = 'php-sdk';
        }

        $slug = preg_replace('/[^a-z0-9]+/i', '-', $hostname);
        $slug = strtolower(trim((string) $slug, '-'));
        if ($slug === '') {
            $slug = 'php-sdk';
        }

        return self::$defaultDeviceId = $slug;
    }

    private function isSuccessfulResponse(array $payload): bool
    {
        $success = $payload['success'] ?? null;
        if (is_bool($success)) {
            return $success;
        }
        if (is_int($success)) {
            return $success === 1;
        }
        if (is_string($success)) {
            $success = strtolower($success);
            return $success === '1' || $success === 'true';
        }

        return false;
    }
}
