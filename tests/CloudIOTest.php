<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

/**
 * Cloud IO: Kernelstart ohne Login und Auswertung des 2FA-Abschlusses.
 */
class CloudIOTest extends TestCase
{
    private const CloudIOModuleID = '{DF2248D9-FC17-4609-840D-BA52DBF9CEB6}';

    /** @var int */
    private $InstanceID;

    /** @var object */
    private $CloudIO;

    public function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/CoreStubs/library.json');
        parent::setUp();
        $this->InstanceID = IPS_CreateInstance(self::CloudIOModuleID);
        $this->CloudIO = IPS\InstanceManager::getInstanceInterface($this->InstanceID);
    }

    public function testKernelStartWithoutLoginStaysInactive(): void
    {
        IPS_ApplyChanges($this->InstanceID);
        $this->CloudIO->MessageSink(time(), 0, IPS_KERNELSTARTED, []);
        $this->assertSame(IS_INACTIVE, IPS_GetInstance($this->InstanceID)['InstanceStatus']);
    }

    public function testParseVerifyResponseUsesHeaderOfRedirectChain(): void
    {
        $Header = implode("\r\n", [
            'HTTP/1.1 302 Found',
            'extension-pragma: {"nonce":123,"ssecurity":"SSEC==","psecurity":"PSEC=="}',
            'Set-Cookie: userId=4711; Domain=.xiaomi.com; Path=/',
            'Set-Cookie: cUserId=CUSER; Domain=.xiaomi.com; Path=/',
            'Location: https://sts.api.io.mi.com/sts?d=abc&clientSign=xyz'
        ]) . "\r\n\r\n" . implode("\r\n", [
            'HTTP/1.1 200 OK',
            'Set-Cookie: serviceToken=TOKEN/+=; Path=/',
            'Set-Cookie: broken-cookie'
        ]);
        $Method = new ReflectionMethod($this->CloudIO, 'parseVerifyResponse');
        $Result = $Method->invoke(null, $Header);
        $this->assertSame('https://sts.api.io.mi.com/sts?d=abc&clientSign=xyz', $Result['location']);
        $this->assertSame('SSEC==', $Result['ssecurity']);
        $this->assertSame('TOKEN/+=', $Result['serviceToken']);
        $this->assertSame('4711', $Result['userId']);
        $this->assertSame('CUSER', $Result['cUserId']);
    }

    public function testParseVerifyResponseWithoutPragma(): void
    {
        $Method = new ReflectionMethod($this->CloudIO, 'parseVerifyResponse');
        $Result = $Method->invoke(null, "HTTP/1.1 200 OK\r\nContent-Type: text/html");
        $this->assertSame('', $Result['ssecurity']);
        $this->assertSame('', $Result['location']);
    }
}
