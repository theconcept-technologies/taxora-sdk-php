<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Result of a CSV transaction import: how many transactions were created, how
 * many rows were skipped as idempotent duplicates, and per-row error messages.
 */
final readonly class ComplianceImportResult
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public int $created,
        public int $skippedDuplicates,
        public array $errors,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $errors = [];
        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $error) {
                if (is_scalar($error)) {
                    $errors[] = (string) $error;
                }
            }
        }

        return new self(
            created: (int) ($data['created'] ?? 0),
            skippedDuplicates: (int) ($data['skipped_duplicates'] ?? 0),
            errors: $errors,
        );
    }
}
