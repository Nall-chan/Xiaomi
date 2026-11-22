<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MiioProtocol.php';

use PHPUnit\Framework\TestCase;
use Xiaomi\Miio\Packet;

/**
 * Lokales miIO-Protokoll gegen einen unabhängig (openssl/md5sum) erzeugten Referenzvektor.
 * Testwerte, kein echtes Gerät: Token 000102…0f, DeviceId 0x0A0B0C0D, Stamp 0x1234.
 */
class MiioProtocolTest extends TestCase
{
    private const Token = '000102030405060708090a0b0c0d0e0f';
    private const DeviceId = 0x0A0B0C0D;
    private const Stamp = 0x1234;
    private const Json = '{"id":1,"method":"miIO.info","params":[]}';
    private const Key = '1ac1ef01e96caf1be0d329331a4fc2a8';
    private const IV = '9ae5383e3c6e0606c15da7847fcd1d42';
    private const Packet = '21310050000000000a0b0c0d00001234c3f02644ed2d22dd9ed4c2eb3358a4752c9dfc761522a115e1c2ce9e6e748522b8aaaf1203910505d6dade053631c80ee3f01a26602d89f172713cc2c4281fd0';

    public function testKeyAndIV(): void
    {
        [$Key, $IV] = Packet::KeyAndIV(hex2bin(self::Token));
        $this->assertSame(self::Key, bin2hex($Key));
        $this->assertSame(self::IV, bin2hex($IV));
    }

    public function testEncodeMatchesReference(): void
    {
        $this->assertSame(self::Packet, bin2hex(Packet::Encode(self::Json, hex2bin(self::Token), self::DeviceId, self::Stamp)));
    }

    public function testDecodeReference(): void
    {
        $Result = Packet::Decode(hex2bin(self::Packet), hex2bin(self::Token));
        $this->assertSame(0, $Result['error']);
        $this->assertSame(self::DeviceId, $Result['deviceId']);
        $this->assertSame(self::Stamp, $Result['stamp']);
        $this->assertSame(self::Json, $Result['payload']);
    }

    public function testHandshake(): void
    {
        $this->assertSame('21310020' . str_repeat('ff', 28), bin2hex(Packet::Handshake()));
        // Antwort auf den Handshake: Kopf ohne Nutzdaten mit DeviceId und Stamp
        $Answer = substr(hex2bin('21310020000000000a0b0c0d00001234'), 0, 16) . str_repeat("\x00", 16);
        $Result = Packet::Decode($Answer, '');
        $this->assertSame(0, $Result['error']);
        $this->assertSame('', $Result['payload']);
        $this->assertSame(self::DeviceId, $Result['deviceId']);
        $this->assertSame(self::Stamp, $Result['stamp']);
    }

    public function testWrongTokenIsChecksumError(): void
    {
        $Result = Packet::Decode(hex2bin(self::Packet), str_repeat("\x11", 16));
        $this->assertSame(Packet::ErrorChecksum, $Result['error']);
        $this->assertNull($Result['payload']);
    }

    public function testInvalidPackets(): void
    {
        $Packet = hex2bin(self::Packet);
        $Token = hex2bin(self::Token);
        $this->assertSame(Packet::ErrorPacket, Packet::Decode('', $Token)['error']);
        $this->assertSame(Packet::ErrorPacket, Packet::Decode("\x21\x32" . substr($Packet, 2), $Token)['error']);
        $this->assertSame(Packet::ErrorPacket, Packet::Decode(substr($Packet, 0, -1), $Token)['error']);
    }

    public function testNextStampAlwaysIncreases(): void
    {
        $this->assertSame(101, Packet::NextStamp(100, 0));
        $this->assertSame(101, Packet::NextStamp(100, 1));
        $this->assertSame(160, Packet::NextStamp(100, 60));
    }
}
