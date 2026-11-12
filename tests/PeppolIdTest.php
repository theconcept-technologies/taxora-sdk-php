<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Support\PeppolId;

final class PeppolIdTest extends TestCase
{
    public function testParsesSchemeAndCompactsValue(): void
    {
        self::assertSame(['scheme' => '0208', 'value' => '0123456749'], PeppolId::parse(' 0208 : 0123.456.749 '));
    }

    public function testBareValueTakesTheCountrysUsualScheme(): void
    {
        self::assertSame(['scheme' => '0208', 'value' => '0123456749'], PeppolId::parse('0123 456 749', 'be'));
        self::assertSame('0106:12345678', PeppolId::format('12345678', 'NL'));
        self::assertSame('9932:GB123456789', PeppolId::format('GB123456789', 'GB'));
        self::assertSame('0192', PeppolId::defaultScheme('NO'));
    }

    public function testExplicitSchemeWinsOverTheCountryDefault(): void
    {
        self::assertSame('0088:5790000123456', PeppolId::format('0088:5790000123456', 'DK'));
    }

    public function testBareValueWithoutKnownSchemeIsInvalid(): void
    {
        self::assertNull(PeppolId::parse('12345', 'DE'));
        self::assertNull(PeppolId::parse('12345'));
        self::assertNull(PeppolId::parse('   ', 'BE'));
        self::assertFalse(PeppolId::isValid('12345', 'FR'));
        self::assertNull(PeppolId::defaultScheme('FR'));

        $this->expectException(InvalidArgumentException::class);
        PeppolId::format('12345', 'FR');
    }
}
