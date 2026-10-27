<?php

declare(strict_types=1);

namespace Taxora\Sdk\Webhooks;

use InvalidArgumentException;

/** @internal `data.object` of a decoded webhook event, checked against the expected event type. */
final class WebhookEventObject
{
    private function __construct()
    {
    }

    /**
     * @param array{id: string, event: string, timestamp: int, data: array<string, mixed>} $event
     * @return array<string, mixed>
     */
    public static function of(array $event, WebhookEventType $expected): array
    {
        if ($event['event'] !== $expected->value) {
            throw new InvalidArgumentException(sprintf(
                'Expected a "%s" webhook event, got "%s".',
                $expected->value,
                $event['event'],
            ));
        }

        $object = $event['data']['object'] ?? null;
        if (!is_array($object)) {
            throw new InvalidArgumentException(sprintf('The "%s" webhook event has no data.object.', $expected->value));
        }

        /** @var array<string, mixed> $object */
        return $object;
    }
}
