<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use InvalidArgumentException;

/**
 * One invoice line of a compliance transaction (input). Every line needs at
 * least one {@see ComplianceLineTax}; `unit` is the optional B2Brouter unit
 * code (e.g. 9 = "unit").
 */
final readonly class ComplianceInvoiceLine
{
    /**
     * @param list<ComplianceLineTax> $taxes
     */
    public function __construct(
        public string $description,
        public float|int|string $quantity,
        public float|int|string $price,
        public array $taxes,
        public ?int $unit = null,
    ) {
        if (trim($description) === '') {
            throw new InvalidArgumentException('Invoice line description must not be empty.');
        }
        if ($taxes === []) {
            throw new InvalidArgumentException('Invoice line requires at least one tax.');
        }
        foreach ($taxes as $index => $tax) {
            if (!$tax instanceof ComplianceLineTax) {
                throw new InvalidArgumentException(sprintf('taxes[%d] must be a %s instance.', $index, ComplianceLineTax::class));
            }
        }
        if (is_string($quantity) && !is_numeric($quantity)) {
            throw new InvalidArgumentException('Invoice line quantity must be numeric.');
        }
        if (is_string($price) && !is_numeric($price)) {
            throw new InvalidArgumentException('Invoice line price must be numeric.');
        }
    }

    /** Wire representation (one entry of `invoice_lines_attributes`). @return array<string,mixed> */
    public function toArray(): array
    {
        $line = [
            'description' => $this->description,
            'quantity' => $this->quantity,
            'price' => $this->price,
        ];

        if ($this->unit !== null) {
            $line['unit'] = $this->unit;
        }

        $line['taxes_attributes'] = array_map(
            static fn (ComplianceLineTax $tax): array => $tax->toArray(),
            $this->taxes,
        );

        return $line;
    }
}
