<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Support\NorwegianOrgNumber;

final class NorwegianOrgNumberTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validNumbers(): iterable
    {
        yield 'Equinor' => ['923609016'];
        yield 'second known number' => ['974760673'];
        yield 'spaced' => ['923 609 016'];
        yield 'VAT form' => ['NO923609016MVA'];
        yield 'spaced VAT form, lower case' => ['no 923 609 016 mva'];
        yield 'dotted' => ['923.609.016'];
        yield 'Peppol participant id' => ['0192:923609016'];
    }

    #[DataProvider('validNumbers')]
    public function testAcceptsValidNumbers(string $value): void
    {
        self::assertTrue(NorwegianOrgNumber::isValid($value));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNumbers(): iterable
    {
        yield 'wrong check digit' => ['923609017'];
        yield 'remainder 1 (check digit 10 is never issued)' => ['100000130'];
        yield 'too short' => ['92360901'];
        yield 'too long' => ['9236090160'];
        yield 'letters' => ['92360901A'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidNumbers')]
    public function testRejectsInvalidNumbers(string $value): void
    {
        self::assertFalse(NorwegianOrgNumber::isValid($value));
    }

    public function testNormalizeStripsFormatting(): void
    {
        self::assertSame('923609016', NorwegianOrgNumber::normalize(' NO 923 609 016 MVA '));
        self::assertSame('923609016', NorwegianOrgNumber::normalize('923-609-016'));
        self::assertSame('923609016', NorwegianOrgNumber::normalize('0192:923609016'));
        self::assertSame('ABC', NorwegianOrgNumber::normalize('abc'), 'normalize does not validate');
    }

    public function testToVatNumberBuildsMvaForm(): void
    {
        self::assertSame('NO923609016MVA', NorwegianOrgNumber::toVatNumber('923 609 016'));
        self::assertSame('NO974760673MVA', NorwegianOrgNumber::toVatNumber('NO974760673MVA'));
        self::assertSame('923609016', NorwegianOrgNumber::toDigits('NO 923609016 MVA'));
    }

    public function testToVatNumberRejectsInvalidNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NorwegianOrgNumber::toVatNumber('923609017');
    }
}
