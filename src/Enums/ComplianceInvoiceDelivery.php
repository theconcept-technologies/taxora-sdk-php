<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * How a German e-invoice reaches the buyer — the `delivery` transaction field.
 * Germany only; other countries ignore it.
 */
enum ComplianceInvoiceDelivery: string
{
    /** Taxora e-mails the e-invoice to `counterpartyEmail` (server default). */
    case EMAIL = 'email';
    /**
     * No mail to the buyer (a copy goes to the enrollment e-mail); fetch the file with
     * {@see \Taxora\Sdk\Endpoints\EReportingEndpoint::getTransactionDocument()} and pass it on yourself.
     */
    case DOWNLOAD = 'download';

    public function description(): string
    {
        return match ($this) {
            self::EMAIL => 'Sent to the buyer by e-mail',
            self::DOWNLOAD => 'Not sent to the buyer; download the document and pass it on yourself',
        };
    }
}
