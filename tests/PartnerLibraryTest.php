<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/XiaomiConsts.php';

use PHPUnit\Framework\TestCase;
use Xiaomi\Configurator\PartnerLibrary;

/**
 * Zuordnung von Modellen zu Partner-Libraries im Konfigurator.
 */
class PartnerLibraryTest extends TestCase
{
    public function testYeelight(): void
    {
        $this->assertSame('Yeelight', PartnerLibrary::ForModel('yeelink.light.color1')[PartnerLibrary::Name]);
    }

    public function testRoborock(): void
    {
        $this->assertSame('Roborock', PartnerLibrary::ForModel('roborock.vacuum.s5')[PartnerLibrary::Name]);
        $this->assertSame('Roborock', PartnerLibrary::ForModel('rockrobo.vacuum.v1')[PartnerLibrary::Name]);
    }

    public function testOtherModels(): void
    {
        $this->assertNull(PartnerLibrary::ForModel('xiaomi.fan.p76'));
        $this->assertNull(PartnerLibrary::ForModel('dreame.vacuum.r2209'));
        // nur Leuchten von Yeelight, keine anderen yeelink-Geräte
        $this->assertNull(PartnerLibrary::ForModel('yeelink.bhf_light.v1'));
    }

    public function testModuleIdsAreGuids(): void
    {
        foreach (PartnerLibrary::List as $Library) {
            $this->assertMatchesRegularExpression('/^\{[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}\}$/', $Library[PartnerLibrary::ModuleId]);
        }
    }
}
