<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * What to do with an invoice to a buyer, derived from the Peppol directory
 * ({@see \Taxora\Sdk\Endpoints\EReportingEndpoint::decideDelivery()}).
 */
enum ComplianceDeliveryAction: string
{
    /** The buyer can receive e-invoices: create the transaction, Taxora sends it. */
    case EINVOICE = 'einvoice';

    /** The buyer is not on the network: send your regular invoice yourself (a transaction would end as not_required and is not billed). */
    case REGULAR_INVOICE = 'regular_invoice';

    /** The directory has no definite answer yet: create the transaction anyway, Taxora asks again before sending. */
    case EINVOICE_PENDING = 'einvoice_pending';

    public function sendsEInvoice(): bool
    {
        return $this !== self::REGULAR_INVOICE;
    }
}
