<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MiotSpec.php';

use PHPUnit\Framework\TestCase;
use Xiaomi\Spec\Key;
use Xiaomi\Spec\Normalizer;

/**
 * Übersetzen der MIoT-Gerätebeschreibung in das eigene Format.
 * Testdaten im Aufbau einer echten Spec (miot-spec.org), Inhalte selbst gewählt.
 */
class MiotSpecTest extends TestCase
{
    private const Urn = 'urn:miot-spec-v2:device:fan:0000A005:test-fan:1:0000D000';

    public function testNormalizeWithLanguageChain(): void
    {
        $Spec = Normalizer::Normalize($this->instance(), $this->languages(), 'de');
        $this->assertSame(Normalizer::SchemaVersion, $Spec[Key::Schema]);
        $this->assertSame(self::Urn, $Spec[Key::Urn]);
        $Service = $Spec[Key::Services][0];
        $this->assertSame(2, $Service[Key::Iid]);
        $this->assertSame('fan', $Service[Key::Type]);
        $this->assertSame('Ventilator', $Service[Key::Name]);
        [$Power, $Mode, $Speed] = $Service[Key::Properties];
        // Deutsch vorhanden
        $this->assertSame('Ein/Aus', $Power[Key::Name]);
        $this->assertSame('on', $Power[Key::Type]);
        $this->assertSame(['read', 'write', 'notify'], $Power[Key::Access]);
        // Werteliste: Position 1 deutsch leer → englisch, Position 0 deutsch
        $this->assertSame([[Key::Value => 0, Key::Name => 'Direkt'], [Key::Value => 1, Key::Name => 'Natural']], $Mode[Key::Values]);
        // Weder deutsch noch englisch → Beschreibung der Spec
        $this->assertSame('Fan Speed', $Speed[Key::Name]);
        $this->assertSame([1, 100, 1], $Speed[Key::Range]);
        $this->assertSame('percentage', $Speed[Key::Unit]);
        $this->assertNull($Power[Key::Range]);
        $Action = $Service[Key::Actions][0];
        $this->assertSame('Umschalten', $Action[Key::Name]);
        $this->assertSame([3], $Action[Key::In]);
        $this->assertSame([], $Action[Key::Out]);
    }

    public function testUnknownLanguageFallsBackToEnglish(): void
    {
        $Spec = Normalizer::Normalize($this->instance(), $this->languages(), 'fr');
        $this->assertSame('Switch Status', $Spec[Key::Services][0][Key::Properties][0][Key::Name]);
    }

    public function testShortTypeAndLanguage(): void
    {
        $this->assertSame('fan-level', Normalizer::ShortType('urn:miot-spec-v2:property:fan-level:00000016:test-fan:1'));
        $this->assertSame('', Normalizer::ShortType('invalid'));
        $this->assertSame('de', Normalizer::Language('de_DE'));
        $this->assertSame('zh_tw', Normalizer::Language('zh_HK'));
        $this->assertSame('zh_cn', Normalizer::Language('zh_CN'));
    }

    private function instance(): array
    {
        return [
            'type'        => self::Urn,
            'description' => 'test fan',
            'services'    => [[
                'iid'         => 2,
                'type'        => 'urn:miot-spec-v2:service:fan:00007808:test-fan:1',
                'description' => 'Fan',
                'properties'  => [
                    ['iid' => 1, 'type' => 'urn:miot-spec-v2:property:on:00000006:test-fan:1', 'description' => 'Switch Status', 'format' => 'bool', 'access' => ['read', 'write', 'notify']],
                    ['iid'                    => 2, 'type' => 'urn:miot-spec-v2:property:mode:00000008:test-fan:1', 'description' => 'Mode', 'format' => 'uint8', 'access' => ['read', 'write'],
                        'value-list'          => [['value' => 0, 'description' => 'Straight Wind'], ['value' => 1, 'description' => 'Natural Wind']]],
                    ['iid' => 3, 'type' => 'urn:miot-spec-v2:property:fan-level:00000016:test-fan:1', 'description' => 'Fan Speed', 'format' => 'uint8', 'access' => ['read'], 'unit' => 'percentage', 'value-range' => [1, 100, 1]]
                ],
                'actions' => [
                    ['iid' => 1, 'type' => 'urn:miot-spec-v2:action:toggle:00002811:test-fan:1', 'description' => 'Toggle', 'in' => [3], 'out' => []]
                ]
            ]]
        ];
    }

    private function languages(): array
    {
        return [
            'en' => [
                'service:002:property:001'              => 'Switch Status',
                'service:002:property:002:valuelist:001'=> 'Natural'
            ],
            'de' => [
                'service:002'                           => 'Ventilator',
                'service:002:property:001'              => 'Ein/Aus',
                'service:002:property:002:valuelist:000'=> 'Direkt',
                'service:002:property:002:valuelist:001'=> '',
                'service:002:action:001'                => 'Umschalten'
            ]
        ];
    }
}
