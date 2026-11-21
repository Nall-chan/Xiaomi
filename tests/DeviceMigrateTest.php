<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

/**
 * Migration der Geräte-Instanz aus älteren Versionen.
 */
class DeviceMigrateTest extends TestCase
{
    private const DeviceModuleID = '{733AB5D2-957D-E76A-BA5D-5006701A6216}';

    /** @var object */
    private $Device;

    public function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/CoreStubs/library.json');
        parent::setUp();
        $this->Device = IPS\InstanceManager::getInstanceInterface(IPS_CreateInstance(self::DeviceModuleID));
    }

    public function testWithoutAttributesIsUnchanged(): void
    {
        $Json = json_encode(['configuration' => new stdClass()]);
        $this->assertSame($Json, $this->Device->Migrate($Json));
    }

    public function testMissingSpecsAttributeIsUnchanged(): void
    {
        $Json = json_encode([
            'configuration' => new stdClass(),
            'attributes'    => ['Token' => 'abcdef']
        ]);
        $this->assertSame($Json, $this->Device->Migrate($Json));
    }

    public function testEmptySpecsAreUnchanged(): void
    {
        $Json = json_encode([
            'configuration' => new stdClass(),
            'attributes'    => ['Token' => 'abcdef', 'Specs' => '[]']
        ]);
        $this->assertSame($Json, $this->Device->Migrate($Json));
    }

    public function testOldSpecsFormatResetsToken(): void
    {
        $Result = json_decode($this->Device->Migrate(json_encode([
            'configuration' => new stdClass(),
            'attributes'    => ['Token' => 'abcdef', 'Specs' => json_encode(['type' => 'urn:miot-spec-v2:device:fan:0000A005:dmaker-p18:1', 'services' => []])]
        ])), true);
        $this->assertSame('', $Result['attributes']['Token']);
        $this->assertSame('[]', $Result['attributes']['Specs']);
    }

    public function testCurrentSpecsKeepToken(): void
    {
        $Result = json_decode($this->Device->Migrate(json_encode([
            'configuration' => new stdClass(),
            'attributes'    => ['Token' => 'abcdef', 'Specs' => json_encode(['urn' => 'urn:miot-spec-v2:device:fan:0000A005:dmaker-p18:1'])]
        ])), true);
        $this->assertSame('abcdef', $Result['attributes']['Token']);
    }
}
