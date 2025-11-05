<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Enums\VatState;
use Taxora\Sdk\ValueObjects\VatResource;

final class VatBackendLinkTest extends TestCase
{
    public function testBuildsProductionBackendLink(): void
    {
        $vo = VatResource::fromArray([
            'uuid' => '6e9ca803-7f5d-410e-ad11-47d824c510be',
            'vat_uid' => 'ATU12345678',
            'state' => VatState::VALID->value,
            'environment' => 'LIVE',
        ]);

        self::assertSame(
            'https://app.taxora.io/vat-history/LIVE/6e9ca803-7f5d-410e-ad11-47d824c510be',
            $vo->getBackendLink()
        );
    }

    public function testBuildsSandboxBackendLink(): void
    {
        $vo = VatResource::fromArray([
            'uuid' => '11111111-2222-3333-4444-555555555555',
            'vat_uid' => 'ATU12345678',
            'state' => VatState::VALID->value,
            'environment' => 'SANDBOX',
        ]);

        self::assertSame(
            'https://app.taxora.io/vat-history/SANDBOX/11111111-2222-3333-4444-555555555555',
            $vo->getBackendLink()
        );
    }

    public function testReturnsNullWhenUuidMissing(): void
    {
        $vo = VatResource::fromArray([
            'vat_uid' => 'ATU12345678',
            'state' => VatState::INVALID->value,
            'environment' => 'SANDBOX',
        ]);

        self::assertNull($vo->getBackendLink());
    }
}
