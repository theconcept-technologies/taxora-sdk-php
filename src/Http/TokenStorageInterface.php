<?php
declare(strict_types=1);


namespace Taxora\Sdk\Http;

interface TokenStorageInterface
{
    public function get(): ?Token;
    public function set(Token $token): void;
    public function clear(): void;
}