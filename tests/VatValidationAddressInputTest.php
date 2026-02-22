<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Taxora\Sdk\ValueObjects\VatValidationAddressInput;

final class VatValidationAddressInputTest extends TestCase
{
    public function testFromArrayNormalizesAndFiltersFields(): void
    {
        $input = VatValidationAddressInput::fromArray([
            'addressLine1' => ' Example Company GmbH ',
            'addressLine2' => 'Second Floor',
            'postalCode' => ' 1010 ',
            'city' => ' Vienna ',
            'countryCode' => 'at',
        ]);

        self::assertSame(
            [
                'addressLine1' => 'Example Company GmbH',
                'addressLine2' => 'Second Floor',
                'postalCode' => '1010',
                'city' => 'Vienna',
                'countryCode' => 'AT',
            ],
            $input->toArray()
        );
    }

    public function testConstructorBuildsPayloadFromAddressLines(): void
    {
        $input = new VatValidationAddressInput(
            addressLine1: 'Line One',
            addressLine2: 'Line Two',
            postalCode: '1200',
            city: 'Wien',
            countryCode: 'at'
        );

        self::assertSame(
            [
                'addressLine1' => 'Line One',
                'addressLine2' => 'Line Two',
                'postalCode' => '1200',
                'city' => 'Wien',
                'countryCode' => 'AT',
            ],
            $input->toArray()
        );
    }

    public function testFromArrayRejectsUnsupportedField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported address input field "line1".');

        VatValidationAddressInput::fromArray(['line1' => 'abc']);
    }

    public function testConstructorRejectsTooLongAddressLine(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('addressLine1 exceeds maximum length of 255 characters.');

        new VatValidationAddressInput(addressLine1: str_repeat('x', 256));
    }

    public function testConstructorRejectsCountryCodeLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('countryCode must be exactly 2 characters.');

        new VatValidationAddressInput(countryCode: 'AUT');
    }

    public function testFromArrayRejectsAddressLine3(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported address input field "addressLine3".');

        VatValidationAddressInput::fromArray(['addressLine3' => 'Not supported']);
    }
}
