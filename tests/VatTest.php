<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Enums\VatState;
use Taxora\Sdk\ValueObjects\VatResource;
use Taxora\Sdk\ValueObjects\VatCollection;
use Taxora\Sdk\ValueObjects\ScoreBreakdown;
use Taxora\Sdk\ValueObjects\CompanyAddress;
use Taxora\Sdk\ValueObjects\ProviderDocument;
use Taxora\Sdk\ValueObjects\ProviderDocumentLine;
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
                [
                    'type' => 'AI Name Comparison', 'score' => 25, 'valid' => true, 'summary' => 'Company names match',
                    'code' => 'MATCH', 'details' => ['ok']
                ],
            ],
            'environment' => 'LIVE',
            'provider' => 'vies',
            'used_providers' => ['fon', 'vies'],
            'provider_vat_state' => 'VALID',
            'provider_note' => 'Provider reports VAT Number is valid, but the check failed (e.g., name/address mismatch).',
            'provider_last_checked_at' => '2024-01-19T13:15:00Z',
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
        self::assertSame(['valid' => true, 'summary' => 'Company names match', 'code' => 'MATCH', 'details' => ['ok']],
            $vo->breakdown[0]->metadata);
        // New optional upstream provider fields
        self::assertSame('LIVE', $vo->environment);
        self::assertSame('vies', $vo->provider);
        self::assertSame(['fon', 'vies'], $vo->used_providers);
        self::assertSame('VALID', $vo->provider_vat_state);
        self::assertSame('Provider reports VAT Number is valid, but the check failed (e.g., name/address mismatch).',
            $vo->provider_note);
        self::assertSame('2024-01-19T13:15:00+00:00', $vo->provider_last_checked_at?->format(DATE_ATOM));
    }

    public function testVatResourceMapsProviderDocument(): void
    {
        $payload = [
            'vat_uid' => 'ATU12345678',
            'state' => VatState::VALID->value,
            'provider_document' => [
                'id' => 123,
                'provider' => 'fon',
                'document_type' => 'fon_daily_xml',
                'state' => 'imported',
                'document_date' => '2025-12-08',
                'mime' => 'application/xml',
                'size' => 9876,
                'hash' => 'abc123',
                'meta' => ['source' => 'daily_feed'],
                'line' => [
                    'id' => 456,
                    'vat_uid' => 'ATU12345678',
                    'row_number' => 12,
                    'entry_identifier' => '1',
                    'reference' => 'FONREF',
                    'meta' => ['note' => 'line meta'],
                ],
            ],
        ];

        $vo = VatResource::fromArray($payload);

        self::assertInstanceOf(ProviderDocument::class, $vo->provider_document);
        self::assertSame(123, $vo->provider_document?->id);
        self::assertSame('fon', $vo->provider_document?->provider);
        self::assertSame('fon_daily_xml', $vo->provider_document?->document_type);
        self::assertSame('imported', $vo->provider_document?->state);
        self::assertSame('2025-12-08', $vo->provider_document?->document_date?->format('Y-m-d'));
        self::assertSame('application/xml', $vo->provider_document?->mime);
        self::assertSame(9876, $vo->provider_document?->size);
        self::assertSame('abc123', $vo->provider_document?->hash);
        self::assertSame(['source' => 'daily_feed'], $vo->provider_document?->meta);
        self::assertInstanceOf(ProviderDocumentLine::class, $vo->provider_document?->line);
        self::assertSame(12, $vo->provider_document?->line?->row_number);
        self::assertSame('FONREF', $vo->provider_document?->line?->reference);
        self::assertSame(['note' => 'line meta'], $vo->provider_document?->line?->meta);
    }

    public function testVatResourceToArrayIncludesProviderDocument(): void
    {
        $vo = VatResource::fromArray([
            'vat_uid' => 'ATU12345678',
            'state' => VatState::VALID->value,
            'provider_document' => [
                'id' => 321,
                'provider' => 'vies',
                'document_type' => 'vies_response',
                'state' => 'archived',
                'document_date' => '2023-01-02',
                'mime' => 'application/xml',
                'size' => 1024,
                'hash' => 'hashhash',
                'meta' => null,
                'line' => [
                    'id' => 789,
                    'vat_uid' => 'ATU12345678',
                    'row_number' => 3,
                    'entry_identifier' => '3',
                    'reference' => null,
                    'meta' => ['raw' => true],
                ],
            ],
        ]);

        $payload = $vo->toArray();

        self::assertIsArray($payload['provider_document']);
        self::assertSame(321, $payload['provider_document']['id']);
        self::assertSame('2023-01-02', $payload['provider_document']['document_date']);
        self::assertSame('vies', $payload['provider_document']['provider']);
        self::assertSame('vies_response', $payload['provider_document']['document_type']);
        self::assertSame('archived', $payload['provider_document']['state']);
        self::assertNull($payload['provider_document']['meta']);
        self::assertIsArray($payload['provider_document']['line']);
        self::assertSame(['raw' => true], $payload['provider_document']['line']['meta']);
        self::assertSame(3, $payload['provider_document']['line']['row_number']);
    }

    public function testVatResourceToArrayFormatsProviderFields(): void
    {
        $vo = VatResource::fromArray([
            'vat_uid' => 'ATU00000000',
            'state' => VatState::INVALID->value,
            'checked_at' => '2024-01-10T08:00:00Z',
            'provider_last_checked_at' => '2024-01-11T09:30:00Z',
            'environment' => 'SANDBOX',
            'provider' => 'fon',
            'used_providers' => '["fon", "vies", ""]',
            'provider_vat_state' => 'INVALID',
            'provider_note' => 'Upstream could not confirm VAT.',
        ]);

        $payload = $vo->toArray();

        self::assertSame('fon', $payload['provider']);
        self::assertSame(['fon', 'vies'], $payload['used_providers']);
        self::assertSame('INVALID', $payload['provider_vat_state']);
        self::assertSame('Upstream could not confirm VAT.', $payload['provider_note']);
        self::assertSame('2024-01-11T09:30:00+00:00', $payload['provider_last_checked_at']);
        self::assertSame('2024-01-10T08:00:00+00:00', $payload['checked_at']);
        self::assertSame('SANDBOX', $payload['environment']);
    }

    public function testVatResourceMapsAddressFallbackResponseFields(): void
    {
        $vo = VatResource::fromArray([
            'vat_uid' => 'ATU12345678',
            'state' => VatState::VALID->value,
            'requested_company_name' => 'Example Company GmbH',
            'requested_company_name_original' => 'John Doe',
            'requested_input_address' => [
                'addressLine1' => 'Example Company GmbH',
                'addressLine2' => 'Second Floor',
                'addressLine3' => 'Building A',
                'postalCode' => '1010',
                'city' => 'Vienna',
                'countryCode' => 'at',
                'ignored' => 'skip',
            ],
            'score_source' => 'addressLine1Fallback',
            'score_attempts' => [
                ['source' => 'companyName', 'score' => 0],
                ['source' => 'addressLine1Fallback', 'score' => 93.7],
                ['source' => '', 'score' => 'nope'],
            ],
        ]);

        self::assertSame('John Doe', $vo->requested_company_name_original);
        self::assertSame('addressLine1Fallback', $vo->score_source);
        self::assertSame(
            [
                'addressLine1' => 'Example Company GmbH',
                'addressLine2' => 'Second Floor',
                'addressLine3' => 'Building A',
                'postalCode' => '1010',
                'city' => 'Vienna',
                'countryCode' => 'AT',
            ],
            $vo->requested_input_address
        );
        self::assertSame(
            [
                ['source' => 'companyName', 'score' => 0.0],
                ['source' => 'addressLine1Fallback', 'score' => 93.7],
            ],
            $vo->score_attempts
        );
    }

    public function testVatResourceToArrayIncludesAddressFallbackResponseFields(): void
    {
        $vo = VatResource::fromArray([
            'vat_uid' => 'ATU12345678',
            'requested_company_name_original' => 'Original Name',
            'requested_input_address' => '{"addressLine1":"Example Company GmbH","countryCode":"de"}',
            'score_source' => 'addressLine1Fallback',
            'score_attempts' => '[{"source":"companyName","score":0},{"source":"addressLine1Fallback","score":90.1}]',
        ]);

        $payload = $vo->toArray();

        self::assertSame('Original Name', $payload['requested_company_name_original']);
        self::assertSame(
            ['addressLine1' => 'Example Company GmbH', 'countryCode' => 'DE'],
            $payload['requested_input_address']
        );
        self::assertSame('addressLine1Fallback', $payload['score_source']);
        self::assertSame(
            [
                ['source' => 'companyName', 'score' => 0.0],
                ['source' => 'addressLine1Fallback', 'score' => 90.1],
            ],
            $payload['score_attempts']
        );
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
            $vo->company_address->toArray()['full_address']
        );
    }

    public function testCompanyAddressNullFallback(): void
    {
        $vo = VatResource::fromArray([
            'vat_uid' => 'ATU12345678',
        ]);

        self::assertNull($vo->company_address);
    }
}
