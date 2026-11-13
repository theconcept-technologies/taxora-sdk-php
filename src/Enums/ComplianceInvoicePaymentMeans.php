<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * How an invoice is (or was) paid — the `paymentMethod` transaction field. It becomes
 * the payment means code (EN 16931 BT-81) in the e-invoice.
 *
 * For shop orders that are already paid, combine it with `paidAmount`: the invoice then
 * shows the amount as prepaid and nothing as due.
 */
enum ComplianceInvoicePaymentMeans: string
{
    /** SEPA credit transfer. Germany: the default when an IBAN is stored on the enrollment. */
    case SEPA_CREDIT_TRANSFER = 'sepa_credit_transfer';
    /** Credit transfer outside SEPA. */
    case CREDIT_TRANSFER = 'credit_transfer';
    case SEPA_DIRECT_DEBIT = 'sepa_direct_debit';
    case DIRECT_DEBIT = 'direct_debit';
    /** Not accepted for a German XRechnung (card details needed) — use ONLINE_PAYMENT there. */
    case CREDIT_CARD = 'credit_card';
    /** Not accepted for a German XRechnung (card details needed) — use ONLINE_PAYMENT there. */
    case DEBIT_CARD = 'debit_card';
    /** PayPal, Klarna, Stripe, Apple Pay … — name the service in `paymentMethodText`. */
    case ONLINE_PAYMENT = 'online_payment';
    case CASH = 'cash';
    case CHEQUE = 'cheque';
    /** Anything else — describe it in `paymentMethodText`. */
    case OTHER = 'other';

    /** `paymentMethodText` is required (e.g. "PayPal"). */
    public function needsText(): bool
    {
        return $this === self::ONLINE_PAYMENT || $this === self::OTHER;
    }

    public function description(): string
    {
        return match ($this) {
            self::SEPA_CREDIT_TRANSFER => 'SEPA credit transfer',
            self::CREDIT_TRANSFER => 'Credit transfer',
            self::SEPA_DIRECT_DEBIT => 'SEPA direct debit',
            self::DIRECT_DEBIT => 'Direct debit',
            self::CREDIT_CARD => 'Credit card',
            self::DEBIT_CARD => 'Debit card',
            self::ONLINE_PAYMENT => 'Online payment service (PayPal, Klarna, …)',
            self::CASH => 'Cash',
            self::CHEQUE => 'Cheque',
            self::OTHER => 'Other',
        };
    }
}
