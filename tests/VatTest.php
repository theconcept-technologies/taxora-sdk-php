<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Enums\VatState;
use Taxora\Sdk\ValueObjects\VatResource;
use Taxora\Sdk\ValueObjects\VatCollection;
use Taxora\Sdk\ValueObjects\ScoreBreakdown;
use Taxora\Sdk\ValueObjects\CompanyAddress;

final class VatTest extends TestCase
{
    public function testVatResourceMapping(): void
    {
        $data = [
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'vat_uid' => 'ATU12345678',
            'state' => 'VALID',
            'country_code' => 'AT',
            'company_name' => 'Example GmbH',
            'company_address' => json_encode([
                'city' => 'Ludersdorf-Wilfersdorf',
                'name' => 'Kickenweiz Reinhard Johann',
                'state' => '',
                'street' => 'Wilfersdorf',
                'country' => 'AT',
                'postal_code' => '8200',
            ], JSON_UNESCAPED_SLASHES),
            'requested_company_name' => 'Example Company GmbH',
            'checked_at' => '2024-01-19T12:45:00Z',
            'score' => 100,
            'breakdown' => [
                ['type'=>'AI Name Comparison','score'=>25,'valid'=>true,'summary'=>'Company names match','code'=>'MATCH','details'=>['ok']],
            ]
        ];
        $vo = VatResource::fromArray($data);

        self::assertSame('ATU12345678', $vo->vat_uid);
        self::assertInstanceOf(VatState::class, $vo->state);
        self::assertSame(VatState::VALID, $vo->state);
        self::assertSame('AT', $vo->country_code);
        self::assertInstanceOf(CompanyAddress::class, $vo->company_address);
        self::assertSame('Kickenweiz Reinhard Johann', $vo->company_address->name);
        self::assertSame('Wilfersdorf', $vo->company_address->street);
        self::assertSame('8200', $vo->company_address->postalCode);
        self::assertSame('Ludersdorf-Wilfersdorf', $vo->company_address->city);
        self::assertSame('AT', $vo->company_address->country);
        self::assertSame(100.0, $vo->score);
        self::assertSame('2024-01-19T12:45:00+00:00', $vo->checked_at?->format(DATE_ATOM));
        self::assertIsArray($vo->breakdown);
        self::assertInstanceOf(ScoreBreakdown::class, $vo->breakdown[0]);
        self::assertSame('AI Name Comparison', $vo->breakdown[0]->stepName);
        self::assertSame(25.0, $vo->breakdown[0]->scoreContribution);
        self::assertSame(['valid'=>true,'summary'=>'Company names match','code'=>'MATCH','details'=>['ok']], $vo->breakdown[0]->metadata);
    }

    public function testVatCollectionFromHistoryResponse(): void
    {
        $payload = [
            'data' => [
                ['vat_uid'=>'ATU11111111','state'=>'VALID'],
                ['vat_uid'=>'ATU22222222','state'=>'INVALID'],
            ],
            'links' => ['self'=>'https://api.taxora.io/api/v1/vat/history']
        ];
        $col = VatCollection::fromResponse($payload);

        self::assertCount(2, $col);
        self::assertSame('ATU11111111', $col->all()[0]->vat_uid);
        self::assertSame('https://api.taxora.io/api/v1/vat/history', $col->self);
    }

    public function testVatCollectionFromValidateMultipleArray(): void
    {
        $payload = [
            ['vat_uid'=>'ATU33333333','state'=>'VALID'],
            ['vat_uid'=>'ATU44444444','state'=>'VALID'],
        ];
        $col = VatCollection::fromResponse($payload);

        self::assertCount(2, $col);
        self::assertSame('ATU44444444', $col->all()[1]->vat_uid);
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
