<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

/**
 * Abstand der Verbindungsversuche: 15 Sekunden, verdoppelnd bis 32 Minuten (wie Event Control).
 */
class DeviceReconnectTest extends TestCase
{
    private const DeviceModuleID = '{733AB5D2-957D-E76A-BA5D-5006701A6216}';

    /** @var int */
    private $InstanceID;

    /** @var object */
    private $Device;

    public function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/CoreStubs/library.json');
        parent::setUp();
        $this->InstanceID = IPS_CreateInstance(self::DeviceModuleID);
        IPS_SetProperty($this->InstanceID, \Xiaomi\Device\Property::Active, true);
        IPS_SetProperty($this->InstanceID, \Xiaomi\Device\Property::DeviceId, '123');
        IPS_ApplyChanges($this->InstanceID); // ohne Cloud IO: bleibt inaktiv, kein Netzwerkzugriff
        $this->Device = IPS\InstanceManager::getInstanceInterface($this->InstanceID);
    }

    public function testDelayDoublesUpToLimit(): void
    {
        $Delays = [];
        for ($i = 0; $i < 10; $i++) {
            $this->failAttempt();
            $Delays[] = $this->reconnectInterval() / 1000;
        }
        $this->assertSame([15, 30, 60, 120, 240, 480, 960, 1920, 1920, 1920], $Delays);
    }

    public function testSecondErrorInSameAttemptIsNotCounted(): void
    {
        $this->failAttempt();
        $this->call('SetStatus', \Xiaomi\Device\InstanceStatus::TimeoutError);
        $this->assertSame(15000, $this->reconnectInterval());
    }

    /**
     * Ein Verbindungsversuch: Reconnect-Timer läuft ab (Intervall 0), Versuch schlägt fehl.
     */
    private function failAttempt(): void
    {
        $this->call('SetTimerInterval', \Xiaomi\Device\Timer::Reconnect, 0);
        $this->call('SetStatus', \Xiaomi\Device\InstanceStatus::TimeoutError);
    }

    private function reconnectInterval(): int
    {
        return (int) $this->call('GetTimerInterval', \Xiaomi\Device\Timer::Reconnect);
    }

    private function call(string $Method, mixed ...$Args): mixed
    {
        return (new ReflectionMethod($this->Device, $Method))->invoke($this->Device, ...$Args);
    }
}
