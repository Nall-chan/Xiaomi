<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../libs/XiaomiConsts.php';

use PHPUnit\Framework\TestCase;

/**
 * Maskierung sensibler Daten für Debug-Ausgaben (nur erfundene Testwerte).
 */
class SensitiveMaskTest extends TestCase
{
    public function testJsonToken(): void
    {
        $Masked = \Xiaomi\Sensitive::mask('{"id":1,"result":{"model":"vendor.device.v1","token":"00112233445566778899aabbccddeeff","ipflag":1}}');
        $this->assertStringNotContainsString('00112233445566778899aabbccddeeff', $Masked);
        $this->assertStringContainsString('"token":"***(32)"', $Masked);
        $this->assertStringContainsString('"model":"vendor.device.v1"', $Masked);
    }

    public function testCookieHeader(): void
    {
        $Masked = \Xiaomi\Sensitive::mask('Cookie: sdkVersion=accountsdk-18.8.15;deviceId=ABCDEF;userId=1234;yetAnotherServiceToken=FAKEONE;serviceToken=FAKETWO;locale=de');
        $this->assertStringNotContainsString('FAKEONE', $Masked);
        $this->assertStringNotContainsString('FAKETWO', $Masked);
        $this->assertStringContainsString('userId=1234', $Masked);
        $this->assertStringContainsString('locale=de', $Masked);
    }

    public function testSetCookieAndPragma(): void
    {
        $Masked = \Xiaomi\Sensitive::mask("Set-Cookie: serviceToken=FaKe/+=; Path=/\r\nextension-pragma: {\"nonce\":1,\"ssecurity\":\"FAKESSEC==\",\"psecurity\":\"FAKEPSEC==\"}");
        $this->assertStringNotContainsString('FaKe/+=', $Masked);
        $this->assertStringNotContainsString('FAKESSEC==', $Masked);
        $this->assertStringNotContainsString('FAKEPSEC==', $Masked);
    }

    public function testArrayByKey(): void
    {
        $Masked = \Xiaomi\Sensitive::mask(['user' => 'name', 'hash' => 'FAKEMD5', 'ticket' => '000000', 'data' => ['ssecurity' => 'X']]);
        $this->assertSame('name', $Masked['user']);
        $this->assertSame('***(7)', $Masked['hash']);
        $this->assertSame('***(6)', $Masked['ticket']);
        $this->assertSame('***(1)', $Masked['data']['ssecurity']);
    }

    public function testTwoFactorSessionKeys(): void
    {
        $Masked = \Xiaomi\Sensitive::mask(
            "Cookie: sdkVersion=accountsdk-18.8.15;deviceId=ABCDEF; identity_session=FAKE/SESSION+A==\r\n" .
            '{"code":2,"flag":8,"applyToken":"FAKEAPPLY","directVerify":true}' . "\r\n" .
            'Location: https://account.example.com/end?userId=1234&sid=xiaomiio&identityToken=FAKEIDENT&_authenticationToken=FAKEAUTH%2F&_signature=2%26FAKESIG%3D' . "\r\n" .
            '{"_sign":"FAKESIGN","qs":"%3Fsid"}'
        );
        foreach (['FAKE/SESSION', 'FAKEAPPLY', 'FAKEIDENT', 'FAKEAUTH', 'FAKESIG', 'FAKESIGN'] as $Secret) {
            $this->assertStringNotContainsString($Secret, $Masked);
        }
        $this->assertStringContainsString('userId=1234', $Masked);
        $this->assertStringContainsString('"directVerify":true', $Masked);
        $this->assertStringContainsString('deviceId=ABCDEF', $Masked);
    }

    public function testStreamUrl(): void
    {
        $Masked = \Xiaomi\Sensitive::mask('{"code":0,"result":{"out":["rtsp://stream.example.com:443/transcoder/FAKESESSIONKEY_H264","http://cdn.example.com/a.png",1700000000000]}}');
        $this->assertStringNotContainsString('FAKESESSIONKEY', $Masked);
        $this->assertStringContainsString('rtsp://stream.example.com:443/***(', $Masked);
        $this->assertStringContainsString('"http://cdn.example.com/a.png"', $Masked);
        $this->assertStringContainsString('1700000000000', $Masked);
    }

    public function testStreamUrlJsonEscaped(): void
    {
        $Masked = \Xiaomi\Sensitive::mask('{"out":["rtsp:\/\/stream.example.com:443\/transcoder\/FAKESESSIONKEY"]}');
        $this->assertStringNotContainsString('FAKESESSIONKEY', $Masked);
        $this->assertStringContainsString('rtsp:\/\/stream.example.com:443/***(', $Masked);
    }

    public function testStreamUrlInArray(): void
    {
        $Masked = \Xiaomi\Sensitive::mask(['code' => 0, 'out' => ['rtsp://stream.example.com/FAKESESSIONKEY', 'x']]);
        $this->assertStringNotContainsString('FAKESESSIONKEY', $Masked['out'][0]);
        $this->assertSame('x', $Masked['out'][1]);
    }

    public function testPreview(): void
    {
        $this->assertSame('0011…(32)', \Xiaomi\Sensitive::preview('00112233445566778899aabbccddeeff'));
        $this->assertSame('', \Xiaomi\Sensitive::preview(''));
    }

    public function testNonSensitiveIsUnchanged(): void
    {
        $Text = '{"code":0,"message":"ok","result":[{"did":"1000","siid":7,"piid":1,"value":50}]}';
        $this->assertSame($Text, \Xiaomi\Sensitive::mask($Text));
        $this->assertNull(\Xiaomi\Sensitive::mask(null));
    }
}
