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
     * @param string $roundingDifference expected total minus computed total (0.00 when no expected total was given)
     */
    public function __construct(
        public string $subtotal,
        public string $taxAmount,
        public string $total,
        public string $roundingDifference = '0.00',
    ) {
    }

    /**
     * @param list<ComplianceInvoiceLine> $lines
     * @param float|int|string|null $expectedTotal the total your own system shows the buyer. The totals always follow the
     *                                             lines (the e-invoice is built from them); this only checks that your total
     *                                             is within rounding distance and reports the difference in cents.
     * @param float $tolerance largest accepted difference to the expected total
     */
    public static function fromLines(array $lines, float|int|string|null $expectedTotal = null, float $tolerance = 0.05): self
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

        $total = round($net + $tax, 2);
        $difference = 0.0;
        if ($expectedTotal !== null) {
            if (!is_numeric($expectedTotal)) {
                throw new \InvalidArgumentException('The expected total must be numeric.');
            }
            $difference = round((float) $expectedTotal - $total, 2);
            if (abs($difference) > $tolerance) {
                throw new \InvalidArgumentException(sprintf(
                    'The expected total %s differs from the invoice lines (%s) by %s, more than the rounding tolerance of %s. Check quantities, prices and tax rates.',
                    number_format((float) $expectedTotal, 2, '.', ''),
                    number_format($total, 2, '.', ''),
                    number_format($difference, 2, '.', ''),
                    number_format($tolerance, 2, '.', ''),
                ));
            }
        }

        return new self(
            number_format($net, 2, '.', ''),
            number_format($tax, 2, '.', ''),
            number_format($total, 2, '.', ''),
            number_format($difference, 2, '.', ''),
        );
    }
}
