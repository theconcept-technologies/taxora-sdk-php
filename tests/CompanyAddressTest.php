<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Taxora\Sdk\ValueObjects\CompanyAddress;

final class CompanyAddressTest extends TestCase
{
    public function testCreatesFromJsonString(): void
    {
        $json = '{"city":"Ludersdorf-Wilfersdorf","name":"Weigl Martin","state":"","street":"Wilfersdorf","country":"AT","postal_code":"8200"}';

        $address = CompanyAddress::from($json);

        self::assertNotNull($address);
        self::assertSame('Weigl Martin', $address->name);
        self::assertSame('Wilfersdorf', $address->street);
        self::assertSame('8200', $address->postalCode);
        self::assertSame('Ludersdorf-Wilfersdorf', $address->city);
        self::assertNull($address->state);
        self::assertSame('AT', $address->country);
        self::assertSame('Weigl Martin, Wilfersdorf, 8200 Ludersdorf-Wilfersdorf, AT', $address->fullAddress);
        self::assertSame($json, $address->raw);
    }

    public function testCreatesFromJsonWithOnlyFullAddress(): void
    {
        $json = '{"full_address":"GALLERIA SAN FEDERICO 16 \n10121 TORINO TO\n"}';

        $address = CompanyAddress::from($json);

        self::assertNotNull($address);
        self::assertSame('GALLERIA SAN FEDERICO 16 10121 TORINO TO', $address->fullAddress);
        self::assertSame($json, $address->raw);
    }

    public function testReturnsNullForEmptyString(): void
    {
        self::assertNull(CompanyAddress::from('   '));
    }

    public function testFallbackToStringWhenJsonInvalid(): void
    {
        $address = CompanyAddress::from('Some plain text address');

        self::assertNotNull($address);
        self::assertSame('Some plain text address', $address->fullAddress);
        self::assertSame('Some plain text address', (string) $address);
    }
}
