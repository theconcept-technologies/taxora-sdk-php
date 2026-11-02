<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Subtotal, VAT and total of invoice lines, computed the way the e-invoice is built: every line is
 * quantity × price rounded to cents, VAT per line at the rates of its taxes. Use it so the header
 * amounts of {@see \Taxora\Sdk\Endpoints\EReportingEndpoint::createTransaction()} always match the
 * lines (the API refuses an e-invoice whose amounts disagree with its lines).
 *
 *   $totals = ComplianceInvoiceTotals::fromLines($lines);
 *   $client->eReporting()->createTransaction(..., subtotal: $totals->subtotal,
 *       taxAmount: $totals->taxAmount, total: $totals->total, invoiceLines: $lines);
 */
final readonly class ComplianceInvoiceTotals
{
    /**
     * @param string $subtotal sum of the line net amounts, 2 decimals
     * @param string $taxAmount sum of the line VAT amounts, 2 decimals
     * @param string $total subtotal + tax, 2 decimals
     */
    public function __construct(
        public string $subtotal,
        public string $taxAmount,
        public string $total,
    ) {
    }

    /** @param list<ComplianceInvoiceLine> $lines */
    public static function fromLines(array $lines): self
    {
        $net = 0.0;
        $tax = 0.0;
        foreach ($lines as $line) {
            $lineNet = round((float) $line->quantity * (float) $line->price, 2);
            $percent = 0.0;
            foreach ($line->taxes as $lineTax) {
                $percent += (float) $lineTax->percent;
            }
            $net += $lineNet;
            $tax += round($lineNet * $percent / 100.0, 2);
        }
        $net = round($net, 2);
        $tax = round($tax, 2);

        return new self(
            number_format($net, 2, '.', ''),
            number_format($tax, 2, '.', ''),
            number_format($net + $tax, 2, '.', ''),
        );
    }
}
