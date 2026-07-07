<?php

declare(strict_types=1);

namespace Taxora\Sdk\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Taxora\Sdk\Version;

/**
 * PSR-18 client decorator that stamps every outgoing request with the SDK
 * version header so the backend can record which SDK version made the call.
 *
 * Purely additive: it only adds a header and delegates to the inner client.
 */
final readonly class SdkVersionClient implements ClientInterface
{
    public function __construct(private ClientInterface $inner)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $request = $request->withHeader('X-Taxora-SDK-Version', 'taxora-php/' . Version::SDK);

        return $this->inner->sendRequest($request);
    }
}
