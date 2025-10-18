<?php
declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use InvalidArgumentException;

/**
 * Represents the response of the VAT certificates bulk export endpoint.
 */
final readonly class VatCertificateExport
{
    public function __construct(
        public string $exportId,
        public ?string $message
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $exportId = (string) ($data['export_id'] ?? '');
        $exportId = trim($exportId);
        if ($exportId === '') {
            throw new InvalidArgumentException('Export response is missing export_id.');
        }

        $message = isset($data['message']) ? trim((string) $data['message']) : null;
        if ($message === '') {
            $message = null;
        }

        return new self($exportId, $message);
    }

    /**
     * @return array{export_id:string,message:?string}
     */
    public function toArray(): array
    {
        return [
            'export_id' => $this->exportId,
            'message' => $this->message,
        ];
    }
}
