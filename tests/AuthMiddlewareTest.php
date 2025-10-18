<?php
declare(strict_types=1);

use Http\Factory\Guzzle\RequestFactory;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\InMemoryTokenStorage;
use Taxora\Sdk\Http\Token;

final class AuthMiddlewareTest extends TestCase
{
    public function testAddsAuthorizationHeaderWhenTokenStored(): void
    {
        $store = new InMemoryTokenStorage();
        $store->set(new Token('abc123', 'Bearer', new \DateTimeImmutable('+1 hour')));

        $middleware = new AuthMiddleware($store);
        $request = (new RequestFactory())->createRequest('GET', 'https://example.com/resource');

        $result = $middleware($request);

        self::assertSame(['Bearer abc123'], $result->getHeader('Authorization'));
    }

    public function testLeavesRequestUnchangedWhenNoTokenAvailable(): void
    {
        $store = new InMemoryTokenStorage();
        $middleware = new AuthMiddleware($store);
        $request = (new RequestFactory())->createRequest('POST', 'https://example.com/login');

        $result = $middleware($request);

        self::assertFalse($result->hasHeader('Authorization'));
    }
}
