<?php
declare(strict_types=1);


namespace Taxora\Sdk\Http;

use Override;

final class InMemoryTokenStorage implements TokenStorageInterface
{
    private ?Token $token = null;
    public function get(): ?Token { return $this->token; }
    public function set(Token $token): void { $this->token = $token; }
    public function clear(): void { $this->token = null; }
}