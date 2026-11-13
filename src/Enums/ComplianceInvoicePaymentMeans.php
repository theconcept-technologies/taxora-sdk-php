<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * How an invoice is (or was) paid — the `paymentMethod` transaction field. It becomes
 * the payment means code (EN 16931 BT-81, UNTDID 4461) in the e-invoice.
 *
 * Rule of thumb for shops: if the customer paid at checkout through a payment provider
 * (PayPal, Klarna, Stripe, Adyen, Mollie, Apple Pay, …), use ONLINE_PAYMENT and name the
 * provider in `paymentMethodText`, plus `paidAmount` for what was already paid. Use the card
 * cases only when the card itself is the payment means you report (e.g. a card terminal).
 *
 * For orders already paid, set `paidAmount`: the invoice then shows the amount as
 * prepaid and nothing as due.
 */
enum ComplianceInvoicePaymentMeans: string
{
    /**
     * SEPA credit transfer in EUR: the buyer transfers to your IBAN ("Überweisung").
     * Germany: the default when an IBAN is stored on the enrollment. Code 58.
     */
    case SEPA_CREDIT_TRANSFER = 'sepa_credit_transfer';

    /**
     * Bank transfer outside SEPA, e.g. a domestic NOK transfer in Norway, or a transfer
     * in another non-euro currency. Code 30.
     */
    case CREDIT_TRANSFER = 'credit_transfer';

    /**
     * SEPA direct debit: you collect from the buyer's account under a SEPA mandate
     * ("Lastschrift", "Bankeinzug"). Code 59.
     */
    case SEPA_DIRECT_DEBIT = 'sepa_direct_debit';

    /**
     * Direct debit outside SEPA, e.g. Norwegian AvtaleGiro / eFaktura direct debit. Code 49.
     */
    case DIRECT_DEBIT = 'direct_debit';

    /**
     * Credit card charged directly (Visa, Mastercard, American Express), e.g. at a card
     * terminal. Name the scheme in `paymentMethodText` ("Visa"). Code 54.
     * Not accepted for a German XRechnung (card details required) — use ONLINE_PAYMENT there.
     */
    case CREDIT_CARD = 'credit_card';

    /**
     * Debit / bank card (girocard / EC-Karte, Maestro, V Pay, Visa Debit, BankAxept).
     * Code 48. Not accepted for a German XRechnung — use ONLINE_PAYMENT there.
     */
    case DEBIT_CARD = 'debit_card';

    /**
     * Payment through an online payment service or checkout provider: PayPal, Klarna
     * (pay now / pay later / instalments), Stripe, Adyen, Mollie, Apple Pay, Google Pay,
     * Amazon Pay, Vipps / MobilePay, Twint, iDEAL, Sofort, giropay, Trustly — also a card
     * payment made at checkout through such a provider. Requires `paymentMethodText` with
     * the provider's name ("PayPal", "Klarna", "Kreditkarte via Stripe"). Code ZZZ.
     */
    case ONLINE_PAYMENT = 'online_payment';

    /** Cash, e.g. cash on delivery ("Nachnahme") or at a shop counter. Code 10. */
    case CASH = 'cash';

    /** Cheque. Code 20. */
    case CHEQUE = 'cheque';

    /**
     * Anything else: gift card or voucher, store credit, set-off against a credit note
     * ("Verrechnung"), payment by a third party. Describe it in `paymentMethodText`. Code ZZZ.
     */
    case OTHER = 'other';

    /** `paymentMethodText` is required (e.g. "PayPal"). */
    public function needsText(): bool
    {
        return $this === self::ONLINE_PAYMENT || $this === self::OTHER;
    }

    /** Short English label, e.g. for a select box. */
    public function description(): string
    {
        return match ($this) {
            self::SEPA_CREDIT_TRANSFER => 'SEPA credit transfer (bank transfer in EUR)',
            self::CREDIT_TRANSFER => 'Bank transfer outside SEPA (e.g. NOK)',
            self::SEPA_DIRECT_DEBIT => 'SEPA direct debit',
            self::DIRECT_DEBIT => 'Direct debit outside SEPA (e.g. AvtaleGiro)',
            self::CREDIT_CARD => 'Credit card (Visa, Mastercard, Amex)',
            self::DEBIT_CARD => 'Debit card (girocard, Maestro, BankAxept)',
            self::ONLINE_PAYMENT => 'Online payment service (PayPal, Klarna, Stripe, Apple Pay, …)',
            self::CASH => 'Cash / cash on delivery',
            self::CHEQUE => 'Cheque',
            self::OTHER => 'Other (voucher, gift card, set-off, …)',
        };
    }

    /** The EN 16931 payment means code that ends up in the e-invoice. */
    public function code(): string
    {
        return match ($this) {
            self::SEPA_CREDIT_TRANSFER => '58',
            self::CREDIT_TRANSFER => '30',
            self::SEPA_DIRECT_DEBIT => '59',
            self::DIRECT_DEBIT => '49',
            self::CREDIT_CARD => '54',
            self::DEBIT_CARD => '48',
            self::ONLINE_PAYMENT, self::OTHER => 'ZZZ',
            self::CASH => '10',
            self::CHEQUE => '20',
        };
    }
}
