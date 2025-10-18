<?php
declare(strict_types=1);


namespace Taxora\Sdk\Http;

use DateTimeImmutable;

final readonly class Token
{
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public DateTimeImmutable $expiresAt
    ) {}

    public function isExpired(DateTimeImmutable $now = new DateTimeImmutable()): bool
    {
        return $this->expiresAt <= $now->modify('+15 seconds');
    }
}