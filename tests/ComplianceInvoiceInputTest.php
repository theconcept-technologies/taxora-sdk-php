<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Endpoints\EReportingEndpoint;
use Taxora\Sdk\Enums\ApiVersion;
use Taxora\Sdk\Enums\ComplianceTaxCategory;
use Taxora\Sdk\Http\ApiKeyMiddleware;
use Taxora\Sdk\Http\AuthMiddleware;
use Taxora\Sdk\Http\InMemoryTokenStorage;
use Taxora\Sdk\Tests\Fixtures\SequenceHttpClient;
use Taxora\Sdk\ValueObjects\ComplianceInvoiceLine;
use Taxora\Sdk\ValueObjects\ComplianceInvoiceTotals;
use Taxora\Sdk\ValueObjects\ComplianceLineTax;

/**
 * Input that cannot be right is refused before a request: tax categories, exemption codes,
 * currency and country codes, due dates and credit-note references. Totals can be computed
 * from the lines so the header always matches them.
 */
final class ComplianceInvoiceInputTest extends TestCase
{
    public function testTaxCategoryAcceptsEnumAndAnyCaseAndNormalisesToTheCode(): void
    {
        self::assertSame('S', (new ComplianceLineTax('MVA', 25, ComplianceTaxCategory::STANDARD))->category);
        self::assertSame('AE', (new ComplianceLineTax('MVA', 0, 'ae'))->category);
    }

    public function testUnknownTaxCategoryIsRefusedWithTheValidList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tax category "X". Valid: S, AA, AAA, K, G, AE, E, Z.');

        new ComplianceLineTax('MVA', 25, 'X');
    }

    public function testExemptTaxNeedsTheExemptionCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exemption code (VATEX)');

        new ComplianceLineTax('MVA', 0, ComplianceTaxCategory::EXEMPT);
    }

    public function testExemptTaxWithAnExemptionCodeIsAccepted(): void
    {
        self::assertSame('vatex-eu-132', (new ComplianceLineTax('MVA', 0, 'E', 'vatex-eu-132'))->comment);
    }

    #[DataProvider('badPercents')]
    public function testTaxPercentMustBeBetween0And100(float|int|string $percent): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ComplianceLineTax('MVA', $percent, 'S');
    }

    /** @return array<string, array{float|int|string}> */
    public static function badPercents(): array
    {
        return ['negative' => [-1], 'above 100' => [100.5], 'text' => ['abc']];
    }

    public function testTotalsAreComputedFromTheLinesLikeTheEInvoiceIsBuilt(): void
    {
        $lines = [
            new ComplianceInvoiceLine('Chair', 3, 111.11, [new ComplianceLineTax('MVA', 25, 'S')]),
            new ComplianceInvoiceLine('Book', 2, 10.00, [new ComplianceLineTax('MVA', 12, 'S')]),
            new ComplianceInvoiceLine('Export', 1, 5.00, [new ComplianceLineTax('MVA', 0, 'Z')]),
        ];

        $totals = ComplianceInvoiceTotals::fromLines($lines);

        self::assertSame('358.33', $totals->subtotal);
        self::assertSame('85.73', $totals->taxAmount);   // 83.33 + 2.40 + 0
        self::assertSame('444.06', $totals->total);
    }

    public function testCurrencyAndCountryAreUpperCasedInTheBody(): void
    {
        $http = new SequenceHttpClient([$this->created()]);

        $this->endpoint($http)->createTransaction(...$this->args(['currency' => 'nok', 'counterpartyCountry' => 'no']));

        $body = json_decode((string) $http->requests[0]->getBody(), true);
        self::assertSame('NOK', $body['currency']);
        self::assertSame('NO', $body['counterparty_country']);
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidDocuments')]
    public function testImpossibleDocumentsAreRefusedWithoutARequest(array $override, string $message): void
    {
        $http = new SequenceHttpClient([]);

        try {
            $this->endpoint($http)->createTransaction(...$this->args($override));
            self::fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertCount(0, $http->requests);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidDocuments(): array
    {
        return [
            'currency with digits' => [['currency' => 'N0K'], 'three letters'],
            'country with 3 letters' => [['counterpartyCountry' => 'NOR'], 'two letters'],
            'due date before invoice date' => [['dueDate' => '2027-01-01'], 'dueDate must not be before'],
            'credit note without reference' => [['isCreditNote' => true], 'needs amendedNumber'],
            'reference without credit note' => [['amendedNumber' => 'NO-1'], 'only for credit notes'],
            'credit note reusing its number' => [['isCreditNote' => true, 'amendedNumber' => 'NO-2'], 'cannot reuse'],
            'corrected invoice dated later' => [['isCreditNote' => true, 'amendedNumber' => 'NO-1', 'amendedDate' => '2027-02-01'], 'must not be after'],
        ];
    }

    /** @return array<string, mixed> */
    private function args(array $override = []): array
    {
        return $override + [
            'enrollmentId' => 21,
            'transactionType' => 'b2b_domestic_outbound',
            'invoiceNumber' => 'NO-2',
            'invoiceDate' => '2027-01-04',
            'subtotal' => 1000,
            'total' => 1250,
            'taxAmount' => 250,
            'invoiceLines' => [new ComplianceInvoiceLine('Vare', 1, 1000, [new ComplianceLineTax('MVA', 25, 'S')])],
            'counterpartyRegisterId' => '923609016',
            'counterpartyName' => 'Kunde AS',
        ];
    }

    private function created(): Response
    {
        return new Response(201, ['Content-Type' => 'application/json'], json_encode([
            'success' => true,
            'data' => [
                'id' => 1, 'compliance_enrollment_id' => 21, 'country' => 'no', 'regime' => 'peppol_bis3',
                'transaction_type' => 'b2b_domestic_outbound', 'state' => 'submitted', 'invoice_number' => 'NO-2',
                'invoice_date' => '2027-01-04', 'currency' => 'NOK', 'subtotal' => '1000', 'tax_amount' => '250', 'total' => '1250',
            ],
        ]));
    }

    private function endpoint(SequenceHttpClient $http): EReportingEndpoint
    {
        $tokens = new InMemoryTokenStorage();

        return new EReportingEndpoint(
            http: $http,
            req: new RequestFactory(),
            stream: new StreamFactory(),
            apiKey: new ApiKeyMiddleware('test-key'),
            auth: new AuthMiddleware($tokens),
            tokens: $tokens,
            refreshCallback: static function (): void {
            },
            baseUrl: 'https://sandbox.taxora.io',
            apiVersion: ApiVersion::V1,
        );
    }

    public function testTaxFactoriesSetTheRightCategory(): void
    {
        self::assertSame(['S', 25], [ComplianceLineTax::rate(25)->category, ComplianceLineTax::rate(25)->percent]);
        self::assertSame('Z', ComplianceLineTax::zeroRated()->category);
        self::assertSame('AE', ComplianceLineTax::reverseCharge()->category);

        $exempt = ComplianceLineTax::exempt('VATEX-EU-132');
        self::assertSame('E', $exempt->category);
        self::assertSame('VATEX-EU-132', $exempt->comment);
    }

    public function testRateRefusesZeroAndExemptNeedsACode(): void
    {
        try {
            ComplianceLineTax::rate(0);
            self::fail('rate(0) must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('zeroRated()', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        ComplianceLineTax::exempt(' ');
    }

    public function testExpectedTotalReportsRoundingAndRefusesRealDifferences(): void
    {
        $lines = [new ComplianceInvoiceLine(description: 'A', quantity: 3, price: 33.33, taxes: [ComplianceLineTax::rate(25)])];

        $totals = ComplianceInvoiceTotals::fromLines($lines, expectedTotal: 124.99);
        self::assertSame('124.99', $totals->total);
        self::assertSame('0.00', $totals->roundingDifference);

        $off = ComplianceInvoiceTotals::fromLines($lines, expectedTotal: '125.02');
        self::assertSame('0.03', $off->roundingDifference);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('differs from the invoice lines');
        ComplianceInvoiceTotals::fromLines($lines, expectedTotal: 130.00);
    }
}
