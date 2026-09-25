<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Enums\ComplianceDocumentType;
use Taxora\Sdk\Enums\ComplianceService;
use Taxora\Sdk\Enums\PeppolLookupStatus;
use Taxora\Sdk\ValueObjects\ComplianceEnrollment;
use Taxora\Sdk\ValueObjects\ComplianceTransaction;
use Taxora\Sdk\ValueObjects\PeppolLookupResult;
use Taxora\Sdk\ValueObjects\RegistryCompany;

final class ComplianceEInvoicingValueObjectsTest extends TestCase
{
    public function testComplianceServiceParsesTolerantly(): void
    {
        self::assertSame(ComplianceService::E_REPORTING, ComplianceService::fromValue('e_reporting'));
        self::assertSame(ComplianceService::E_INVOICING, ComplianceService::fromValue(' E_INVOICING '));
        self::assertSame(ComplianceService::E_INVOICING, ComplianceService::fromValue(ComplianceService::E_INVOICING));
        self::assertSame(ComplianceService::UNKNOWN, ComplianceService::fromValue('e_archiving'));
        self::assertNotSame('', ComplianceService::E_INVOICING->description());
    }

    public function testPeppolLookupStatusSemantics(): void
    {
        self::assertTrue(PeppolLookupStatus::REACHABLE->isTerminal());
        self::assertTrue(PeppolLookupStatus::REACHABLE->isSuccess());
        self::assertTrue(PeppolLookupStatus::NOT_REACHABLE->isTerminal());
        self::assertFalse(PeppolLookupStatus::NOT_REACHABLE->isSuccess());
        self::assertFalse(PeppolLookupStatus::PENDING->isTerminal());
        self::assertFalse(PeppolLookupStatus::PENDING->isSuccess());
        self::assertSame(PeppolLookupStatus::UNKNOWN, PeppolLookupStatus::fromValue('maybe'));
        self::assertFalse(PeppolLookupStatus::UNKNOWN->isTerminal());
        self::assertNotSame('', PeppolLookupStatus::PENDING->description());
    }

    public function testDocumentTypeParsesTolerantly(): void
    {
        self::assertSame(ComplianceDocumentType::INVOICE, ComplianceDocumentType::fromValue('invoice'));
        self::assertSame(ComplianceDocumentType::CREDIT_NOTE, ComplianceDocumentType::fromValue('credit_note'));
        self::assertSame(ComplianceDocumentType::UNKNOWN, ComplianceDocumentType::fromValue('debit_note'));
        self::assertNotSame('', ComplianceDocumentType::CREDIT_NOTE->description());
    }

    public function testRegistryCompanyFromArray(): void
    {
        $company = RegistryCompany::fromArray([
            'org_number' => '923609016',
            'company_name' => 'EQUINOR ASA',
            'organisation_form' => 'ASA',
            'vat_registered' => true,
            'vat_number' => 'NO923609016MVA',
            'enterprise_register' => true,
            'bankrupt' => false,
            'under_liquidation' => true,
            'address' => 'Forusbeen 50',
            'postalcode' => '4035',
            'city' => 'STAVANGER',
            'country' => 'NO',
        ]);

        self::assertSame('923609016', $company->orgNumber);
        self::assertSame('EQUINOR ASA', $company->companyName);
        self::assertSame('ASA', $company->organisationForm);
        self::assertTrue($company->vatRegistered);
        self::assertSame('NO923609016MVA', $company->vatNumber);
        self::assertTrue($company->enterpriseRegister);
        self::assertFalse($company->bankrupt);
        self::assertTrue($company->underLiquidation);
        self::assertTrue($company->isInsolvent());
        self::assertSame('Forusbeen 50', $company->address);
        self::assertSame('4035', $company->postalcode);
        self::assertSame('STAVANGER', $company->city);
        self::assertSame('NO', $company->country);
    }

    public function testRegistryCompanyDefaultsMissingFields(): void
    {
        $company = RegistryCompany::fromArray(['org_number' => '974760673']);

        self::assertNull($company->vatNumber);
        self::assertFalse($company->vatRegistered);
        self::assertFalse($company->isInsolvent());
        self::assertNull($company->address);
    }

    public function testPeppolLookupResultFromArray(): void
    {
        $result = PeppolLookupResult::fromArray([
            'status' => 'reachable',
            'reachable' => true,
            'country' => 'NO',
            'scheme' => '0192',
            'id' => '923609016',
            'document_types' => ['xml.ubl.invoice.bis3', 'xml.ubl.credit_note.bis3', ['junk']],
            'transport_type_code' => 'peppol',
            'checked_at' => '2026-09-25T10:00:00+00:00',
        ]);

        self::assertSame(PeppolLookupStatus::REACHABLE, $result->status);
        self::assertTrue($result->reachable);
        self::assertSame('0192', $result->scheme);
        self::assertSame(['xml.ubl.invoice.bis3', 'xml.ubl.credit_note.bis3'], $result->documentTypes, 'non-scalar entries are dropped');
        self::assertTrue($result->supportsDocumentType('xml.ubl.credit_note.bis3'));
        self::assertFalse($result->supportsDocumentType('xml.ubl.order.bis3'));
        self::assertSame('peppol', $result->transportTypeCode);
        self::assertSame('2026-09-25T10:00:00+00:00', $result->checkedAt);
    }

    public function testEnrollmentWithoutServiceFieldKeepsNull(): void
    {
        $enrollment = ComplianceEnrollment::fromArray(['id' => 1, 'status' => 'ready']);

        self::assertNull($enrollment->service, 'older servers omit the service field');
    }

    public function testTransactionFromOlderServerDefaultsNewFields(): void
    {
        $tx = ComplianceTransaction::fromArray(['id' => 5, 'state' => 'pending', 'transaction_type' => 'b2c_outbound']);

        self::assertNull($tx->counterpartyRegisterId);
        self::assertNull($tx->documentType);
        self::assertFalse($tx->isCreditNote());
        self::assertNull($tx->providerState);
        self::assertSame([], $tx->invoiceLines);
    }
}
