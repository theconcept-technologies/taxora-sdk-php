<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

use InvalidArgumentException;

/**
 * The legal e-invoice of a transaction (usually EHF / Peppol UBL XML) exactly as
 * it was sent to the buyer — returned by EReportingEndpoint::getTransactionDocument().
 */
final readonly class ComplianceTransactionDocument
{
    /**
     * @param string $content the raw document bytes
     * @param string $contentType the response Content-Type, e.g. "application/xml"
     * @param string $filename from Content-Disposition (falls back to "e-invoice-{id}.{ext}")
     */
    public function __construct(
        public string $content,
        public string $contentType,
        public string $filename,
    ) {
    }

    /**
     * Write the document to $path (e.g. your archive) and return the number of bytes written.
     */
    public function saveTo(string $path): int
    {
        $written = @file_put_contents($path, $this->content);
        if ($written === false) {
            throw new InvalidArgumentException(sprintf('Could not write the document to "%s".', $path));
        }

        return $written;
    }

    public function size(): int
    {
        return strlen($this->content);
    }
}
