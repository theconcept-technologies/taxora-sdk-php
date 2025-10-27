<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Enums\VatState;
use Taxora\Sdk\Tests\Fixtures\SandboxVatFixtures;
use Taxora\Sdk\ValueObjects\CompanyAddress;
use Taxora\Sdk\ValueObjects\VatResource;

final class SandboxVatFixturesTest extends TestCase
{
    #[DataProvider('provideValidSandboxVatData')]
    public function testSandboxValidVatFixturesHydrateVatResource(string $vatUid, array $details): void
    {
        $resource = VatResource::fromArray([
            'vat_uid' => $vatUid,
            'state' => VatState::VALID->value,
            'country_code' => $details['country_code'],
            'company_name' => $details['company_name'],
            'company_address' => json_encode([
                'city' => $details['city'],
                'name' => $details['company_name'],
                'state' => '',
                'street' => $details['street'],
                'country' => $details['country_code'],
                'postal_code' => $details['zip_code'],
            ], JSON_UNESCAPED_SLASHES),
        ]);

        self::assertSame($vatUid, $resource->vat_uid);
        self::assertSame(VatState::VALID, $resource->state);
        self::assertSame($details['country_code'], $resource->country_code);
        self::assertSame($details['company_name'], $resource->company_name);
        self::assertInstanceOf(CompanyAddress::class, $resource->company_address);
        self::assertSame($details['street'], $resource->company_address->street);
        self::assertSame($details['zip_code'], $resource->company_address->postalCode);
        self::assertSame($details['city'], $resource->company_address->city);
        self::assertSame($details['country_code'], $resource->company_address->country);
    }

    public static function provideValidSandboxVatData(): iterable
    {
        foreach (SandboxVatFixtures::valid() as $vatUid => $details) {
            yield $vatUid => [$vatUid, $details];
        }
    }

    #[DataProvider('provideInvalidSandboxVatData')]
    public function testSandboxInvalidVatFixturesHydrateVatResource(string $vatUid): void
    {
        $resource = VatResource::fromArray([
            'vat_uid' => $vatUid,
            'state' => VatState::INVALID->value,
            'country_code' => substr($vatUid, 0, 2),
        ]);

        self::assertSame($vatUid, $resource->vat_uid);
        self::assertSame(VatState::INVALID, $resource->state);
        self::assertSame(substr($vatUid, 0, 2), $resource->country_code);
        self::assertNull($resource->company_address);
    }

    public static function provideInvalidSandboxVatData(): iterable
    {
        foreach (SandboxVatFixtures::invalid() as $vatUid) {
            yield $vatUid => [$vatUid];
        }
    }
}
