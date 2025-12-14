<?php
declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Represents the nested line information inside a provider_document.
 */
final readonly class ProviderDocumentLine
{
    /**
     * @param array<string,mixed>|null $meta
     */
    public function __construct(
        public ?int $id,
        public ?string $vat_uid,
        public ?int $row_number,
        public ?string $entry_identifier,
        public ?string $reference,
        public ?array $meta,
    ) {
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        return new self(
            id: isset($data['id']) ? (int) $data['id'] : null,
            vat_uid: self::stringOrNull($data['vat_uid'] ?? null),
            row_number: isset($data['row_number']) ? (int) $data['row_number'] : null,
            entry_identifier: self::stringOrNull($data['entry_identifier'] ?? null),
            reference: self::stringOrNull($data['reference'] ?? null),
            meta: self::metaOrNull($data['meta'] ?? null),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vat_uid' => $this->vat_uid,
            'row_number' => $this->row_number,
            'entry_identifier' => $this->entry_identifier,
            'reference' => $this->reference,
            'meta' => $this->meta,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
        } elseif (is_numeric($value)) {
            $value = (string) $value;
        } else {
            return null;
        }

        return $value === '' ? null : $value;
    }

    /**
     * @param mixed $meta
     * @return array<string,mixed>|null
     */
    private static function metaOrNull(mixed $meta): ?array
    {
        if (is_string($meta) && json_validate($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : null;
        }

        return is_array($meta) ? $meta : null;
    }
}
