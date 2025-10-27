<?php
declare(strict_types=1);

namespace Taxora\Sdk\Tests\Fixtures;

use Taxora\Sdk\Enums\VatState;

final class SandboxVatFixtures
{
    /**
     * Sandbox VAT UIDs that always resolve to a VALID state.
     *
     * @return array<string, array{company_name: string, street: string, zip_code: string, city: string, country_code: string}>
     */
    public static function valid(): array
    {
        return [
            'ATU12345678' => [
                'company_name' => 'Alpha Handels GmbH',
                'street' => 'Ringstraße 1',
                'zip_code' => '1010',
                'city' => 'Wien',
                'country_code' => 'AT',
            ],
            'DE123456789' => [
                'company_name' => 'Beta Technik GmbH',
                'street' => 'Hauptstraße 12',
                'zip_code' => '10115',
                'city' => 'Berlin',
                'country_code' => 'DE',
            ],
            'FR99345678901' => [
                'company_name' => 'Gamma Industrie SAS',
                'street' => '10 Rue de Rivoli',
                'zip_code' => '75001',
                'city' => 'Paris',
                'country_code' => 'FR',
            ],
            'IT12398765432' => [
                'company_name' => 'Delta Servizi SRL',
                'street' => 'Via Roma 25',
                'zip_code' => '00100',
                'city' => 'Roma',
                'country_code' => 'IT',
            ],
            'GB999999973' => [
                'company_name' => 'Epsilon Solutions Ltd',
                'street' => '221B Baker Street',
                'zip_code' => 'NW1 6XE',
                'city' => 'London',
                'country_code' => 'GB',
            ],
        ];
    }

    /**
     * Sandbox VAT UIDs that always resolve to an INVALID state.
     *
     * @return list<string>
     */
    public static function invalid(): array
    {
        return [
            'ATU99999999',
            'DE000000000',
            'FR00000000000',
            'IT00000000000',
            'GB000000000',
        ];
    }

    /**
     * @return array<string, array|list<string>>
     */
    public static function byState(): array
    {
        return [
            VatState::VALID->value => self::valid(),
            VatState::INVALID->value => self::invalid(),
        ];
    }
}
