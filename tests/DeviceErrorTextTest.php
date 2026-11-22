<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

/**
 * Prüft die Umsetzung von Fehlercodes in Meldungen der Geräte-Instanz.
 */
class DeviceErrorTextTest extends TestCase
{
    private const DeviceModuleID = '{733AB5D2-957D-E76A-BA5D-5006701A6216}';

    /** @var object */
    private $Device;

    /** @var ReflectionMethod */
    private $GetErrorText;

    public function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/CoreStubs/library.json');
        parent::setUp();
        $InstanceID = IPS_CreateInstance(self::DeviceModuleID);
        $this->Device = IPS\InstanceManager::getInstanceInterface($InstanceID);
        $this->GetErrorText = new ReflectionMethod($this->Device, 'GetErrorText');
    }

    public function testKnownDeviceCode(): void
    {
        $this->assertSame(
            $this->Device->Translate('Property value invalid'),
            $this->GetErrorText->invoke($this->Device, \Xiaomi\Device\ApiError::$CodeToText, -704220043)
        );
    }

    public function testKnownCloudCode(): void
    {
        $this->assertSame(
            $this->Device->Translate('Unauthorized'),
            $this->GetErrorText->invoke($this->Device, \Xiaomi\Cloud\ApiError::$CodeToText, 2)
        );
    }

    public function testUnknownCodeDoesNotFail(): void
    {
        $this->assertSame(
            $this->Device->Translate('Unknown error: ') . '-123456',
            $this->GetErrorText->invoke($this->Device, \Xiaomi\Device\ApiError::$CodeToText, -123456)
        );
    }

    public function testRemoteServiceErrorIsKnown(): void
    {
        $this->assertSame(
            $this->Device->Translate('Remote service error, device could not process the command'),
            $this->GetErrorText->invoke($this->Device, \Xiaomi\Device\ApiError::$CodeToText, -706010002)
        );
    }

    public function testEveryErrorTextIsTranslated(): void
    {
        $Locale = json_decode(file_get_contents(__DIR__ . '/../Xiaomi MIoT Device/locale.json'), true)['translations']['de'];
        $Texts = array_unique(array_merge(
            array_values(\Xiaomi\Device\ApiError::$CodeToText),
            array_values(\Xiaomi\Cloud\ApiError::$CodeToText),
            ['Unknown error: ']
        ));
        foreach ($Texts as $Text) {
            $this->assertArrayHasKey($Text, $Locale, 'Missing translation: ' . $Text);
        }
    }
}
