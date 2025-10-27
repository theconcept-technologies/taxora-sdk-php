<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Enums\VatState;
use Taxora\Sdk\ValueObjects\VatResource;
use Taxora\Sdk\ValueObjects\VatCollection;
use Taxora\Sdk\ValueObjects\ScoreBreakdown;
use Taxora\Sdk\ValueObjects\CompanyAddress;
use Taxora\Sdk\Tests\Fixtures\SandboxVatFixtures;

final class VatTest extends TestCase
{
    public function testVatResourceMapping(): void
    {
        $fixture = SandboxVatFixtures::valid()['ATU12345678'];

        $data = [
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'vat_uid' => 'ATU12345678',
            'state' => VatState::VALID->value,
            'country_code' => $fixture['country_code'],
            'company_name' => $fixture['company_name'],
            'company_address' => json_encode([
                'city' => $fixture['city'],
                'name' => $fixture['company_name'],
                'state' => '',
                'street' => $fixture['street'],
                'country' => $fixture['country_code'],
                'postal_code' => $fixture['zip_code'],
            ], JSON_UNESCAPED_SLASHES),
            'requested_company_name' => 'Example Company GmbH',
            'checked_at' => '2024-01-19T12:45:00Z',
            'score' => 100,
            'breakdown' => [
                ['type' => 'AI Name Comparison', 'score' => 25, 'valid' => true, 'summary' => 'Company names match', 'code' => 'MATCH', 'details' => ['ok']],
            ],
        ];
        $vo = VatResource::fromArray($data);

        self::assertSame('ATU12345678', $vo->vat_uid);
        self::assertInstanceOf(VatState::class, $vo->state);
        self::assertSame(VatState::VALID, $vo->state);
        self::assertSame($fixture['country_code'], $vo->country_code);
        self::assertInstanceOf(CompanyAddress::class, $vo->company_address);
        self::assertSame($fixture['company_name'], $vo->company_address->name);
        self::assertSame($fixture['street'], $vo->company_address->street);
        self::assertSame($fixture['zip_code'], $vo->company_address->postalCode);
        self::assertSame($fixture['city'], $vo->company_address->city);
        self::assertSame($fixture['country_code'], $vo->company_address->country);
        self::assertSame(100.0, $vo->score);
        self::assertSame('2024-01-19T12:45:00+00:00', $vo->checked_at?->format(DATE_ATOM));
        self::assertIsArray($vo->breakdown);
        self::assertInstanceOf(ScoreBreakdown::class, $vo->breakdown[0]);
        self::assertSame('AI Name Comparison', $vo->breakdown[0]->stepName);
        self::assertSame(25.0, $vo->breakdown[0]->scoreContribution);
        self::assertSame(['valid' => true, 'summary' => 'Company names match', 'code' => 'MATCH', 'details' => ['ok']], $vo->breakdown[0]->metadata);
    }

    public function testVatCollectionFromHistoryResponse(): void
    {
        $validFixtures = SandboxVatFixtures::valid();
        $firstValid = array_key_first($validFixtures);
        $invalidFixtures = SandboxVatFixtures::invalid();
        $firstInvalid = $invalidFixtures[0];

        $payload = [
            'data' => [
                ['vat_uid' => $firstValid, 'state' => VatState::VALID->value],
                ['vat_uid' => $firstInvalid, 'state' => VatState::INVALID->value],
            ],
            'links' => ['self' => 'https://api.taxora.io/v1/vat/history'],
        ];
        $col = VatCollection::fromResponse($payload);

        self::assertCount(2, $col);
        self::assertSame($firstValid, $col->all()[0]->vat_uid);
        self::assertSame('https://api.taxora.io/v1/vat/history', $col->self);
    }

    public function testVatCollectionFromValidateMultipleArray(): void
    {
        $validUids = array_keys(SandboxVatFixtures::valid());

        $payload = [
            ['vat_uid' => $validUids[1], 'state' => VatState::VALID->value],
            ['vat_uid' => $validUids[2], 'state' => VatState::VALID->value],
        ];
        $col = VatCollection::fromResponse($payload);

        self::assertCount(2, $col);
        self::assertSame($validUids[2], $col->all()[1]->vat_uid);
    }

    public function testScoreBreakdownToArray(): void
    {
        $breakdown = new ScoreBreakdown('CheckVatUid', -5.5, ['reason' => 'format mismatch']);

        self::assertSame(
            [
                'stepName' => 'CheckVatUid',
                'scoreContribution' => -5.5,
                'metadata' => ['reason' => 'format mismatch'],
            ],
            $breakdown->toArray()
        );
    }

    public function testCompanyAddressFallbackToPlainString(): void
    {
        $vo = VatResource::fromArray([
            'vat_uid' => 'ATU12345678',
            'company_address' => 'Musterstraße 12, 1010 Wien, Austria',
        ]);

        self::assertInstanceOf(CompanyAddress::class, $vo->company_address);
        self::assertSame('Musterstraße 12, 1010 Wien, Austria', (string) $vo->company_address);
        self::assertSame(
            'Musterstraße 12, 1010 Wien, Austria',
            $vo->company_address?->toArray()['full_address']
        );
    }
}
