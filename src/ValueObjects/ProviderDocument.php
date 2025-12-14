<?php
declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Represents the provider_document structure attached to VAT resources.
 */
final readonly class ProviderDocument
{
    /**
     * @param array<string,mixed>|null $meta
     */
    public function __construct(
        public ?int $id,
        public ?string $provider,
        public ?string $document_type,
        public ?string $state,
        public ?\DateTimeImmutable $document_date,
        public ?string $mime,
        public ?int $size,
        public ?string $hash,
        public ?array $meta,
        public ?ProviderDocumentLine $line,
    ) {
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        return new self(
            id: isset($data['id']) ? (int) $data['id'] : null,
            provider: self::stringOrNull($data['provider'] ?? null),
            document_type: self::stringOrNull($data['document_type'] ?? null),
            state: self::stringOrNull($data['state'] ?? null),
            document_date: self::dateOrNull($data['document_date'] ?? null),
            mime: self::stringOrNull($data['mime'] ?? null),
            size: isset($data['size']) ? (int) $data['size'] : null,
            hash: self::stringOrNull($data['hash'] ?? null),
            meta: self::metaOrNull($data['meta'] ?? null),
            line: ProviderDocumentLine::fromArray($data['line'] ?? null),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'document_type' => $this->document_type,
            'state' => $this->state,
            'document_date' => $this->document_date?->format('Y-m-d'),
            'mime' => $this->mime,
            'size' => $this->size,
            'hash' => $this->hash,
            'meta' => $this->meta,
            'line' => $this->line?->toArray(),
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

    private static function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
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
