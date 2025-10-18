<?php
declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Override;

/**
 * Immutable collection DTO for arrays of VatResource.
 * Ref: components/schemas/VatCollection (data[], links.self).  [oai_citation:1‡api-docs-production.json](sediment://file_00000000deb861f5857dec4b59e99321)
 *
 * @implements IteratorAggregate<int, VatResource>
 */
final class VatCollection implements IteratorAggregate, Countable
{
    /** @var VatResource[] */
    private array $items = [];
    public readonly ?string $self;

    /** @param array<int, VatResource> $items */
    private function __construct(array $items, ?string $self)
    {
        $this->items = $items;
        $this->self  = $self;
    }

    /** @param array<string,mixed> $payload Raw API response */
    public static function fromResponse(array $payload): self
    {
        $items = [];
        if (isset($payload['data']) && is_array($payload['data'])) {
            foreach ($payload['data'] as $row) {
                $items[] = VatResource::fromArray($row);
            }
        } elseif (array_is_list($payload)) {
            // validate-multiple in prod can return an array of VatResource.  [oai_citation:2‡api-docs-production.json](sediment://file_00000000deb861f5857dec4b59e99321)
            foreach ($payload as $row) {
                $items[] = VatResource::fromArray($row);
            }
        }
        $self = $payload['links']['self'] ?? null;
        return new self($items, $self);
    }

    /**
     * @return ArrayIterator<int,VatResource>
     */
    #[Override]
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return VatResource[] */
    public function all(): array
    {
        return $this->items;
    }
}
