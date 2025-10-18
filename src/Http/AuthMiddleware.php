<?php
declare(strict_types=1);


namespace Taxora\Sdk\Http;

use Psr\Http\Message\RequestInterface;

final readonly class AuthMiddleware
{
    public function __construct(private TokenStorageInterface $store) {}

    public function __invoke(RequestInterface $request): RequestInterface
    {
        $token = $this->store->get();
        if (!$token) { return $request; } // login/refresh calls
        return $request->withHeader('Authorization', $token->tokenType.' '.$token->accessToken);
    }
}