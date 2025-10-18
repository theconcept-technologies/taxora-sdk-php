<?php
declare(strict_types=1);

namespace Taxora\Sdk\Http;

use DateTimeImmutable;
use Override;
use Psr\SimpleCache\CacheInterface;

/**
 * Stores the bearer token in any PSR-16 cache (files, Redis, APCu…).
 * Key default is namespaced so multiple apps can co-exist.
 */
final readonly class Psr16TokenStorage implements TokenStorageInterface
{
    public function __construct(
        private CacheInterface $cache,
        private string $cacheKey = 'taxora_sdk_token'
    ) {
    }

    public function get(): ?Token
    {
        $data = $this->cache->get($this->cacheKey);
        if (!is_array($data) || empty($data['accessToken']) || empty($data['tokenType']) || empty($data['expiresAt'])) {
            return null;
        }
        return new Token($data['accessToken'], $data['tokenType'], new DateTimeImmutable($data['expiresAt']));
    }

    public function set(Token $token): void
    {
        $this->cache->set($this->cacheKey, [
            'accessToken' => $token->accessToken,
            'tokenType' => $token->tokenType,
            'expiresAt' => $token->expiresAt->format(DATE_ATOM),
        ], $token->expiresAt->getTimestamp() - time());
    }

    #[ Override]
    public function clear(): void
    {
        $this->cache->delete($this->cacheKey);
    }
}