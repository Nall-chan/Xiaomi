<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MiotCard.php';

use PHPUnit\Framework\TestCase;
use Xiaomi\Card\Card;

/**
 * Sperren aus den Gerätekarten der App auf die Gerätebeschreibung übertragen.
 * Testdaten im Aufbau der echten Konfiguration (Ventilator-Karte), Inhalte selbst gewählt.
 */
class MiotCardTest extends TestCase
{
    private const Urn = 'urn:miot-spec-v2:device:fan:0000A005:test-fan:1:0000D000';

    public function testFindByDeviceType(): void
    {
        $this->assertSame(['fan'], Card::Find($this->configs(), self::Urn)['types']);
        $this->assertNull(Card::Find($this->configs(), 'urn:miot-spec-v2:device:light:0000A001:test-light:1'));
        // ausgenommenes Modell (URN ohne Suffix)
        $this->assertNull(Card::Find($this->configs(), 'urn:miot-spec-v2:device:fan:0000A005:excluded-fan:1:0000D001'));
    }

    public function testLocks(): void
    {
        $Locks = Card::Locks(Card::Find($this->configs(), self::Urn), $this->spec(), 'P');
        $this->assertSame([
            'P_2_2' => [['ident' => 'P_2_1', 'value' => false]],
            'P_2_4' => [['ident' => 'P_2_1', 'value' => false], ['ident' => 'P_2_3', 'value' => 1]]
        ], $Locks);
    }

    public function testIsLocked(): void
    {
        $Locks = Card::Locks(Card::Find($this->configs(), self::Urn), $this->spec(), 'P');
        $State = ['P_2_1' => true, 'P_2_3' => 0];
        $Get = function (string $Ident) use (&$State)
        {
            return $State[$Ident] ?? null;
        };
        $this->assertFalse(Card::IsLocked($Locks['P_2_4'], $Get));
        $State['P_2_3'] = 1; // Natural Wind
        $this->assertTrue(Card::IsLocked($Locks['P_2_4'], $Get));
        $State = ['P_2_1' => false, 'P_2_3' => 0];
        $this->assertTrue(Card::IsLocked($Locks['P_2_2'], $Get));
        $this->assertFalse(Card::IsLocked($Locks['P_2_2'], fn (string $Ident) => null));
    }

    private function configs(): array
    {
        return [
            ['types' => ['light'], 'disable_type' => ['urn:miot-spec-v2:device:light:0000A001:test-light:1'], 'card_items' => []],
            [
                'types'        => ['fan'],
                'disable_type' => ['urn:miot-spec-v2:device:fan:0000A005:excluded-fan:1'],
                'card_items'   => [
                    ['cardType' => 1, 'prop_key' => 'p:fan:on'],
                    ['cardType' => 1, 'prop_key' => 'p:fan:horizontal-swing', 'operation' => [['disable_status' => [['key' => 'p:fan:on', 'value' => 'false']]]]],
                    ['cardType' => 12, 'prop_key' => 'p:fan:fan-level', 'operation' => [['disable_status' => [['key' => 'p:fan:on', 'value' => 'false']]]]],
                    ['cardType' => 12, 'prop_key' => 'pi:2:4', 'operation' => [['disable_status' => [['key' => 'p:fan:on', 'value' => false], ['key' => 'p:fan:mode', 'value' => 'Natural Wind']]]]],
                    // Unbekannter Wert und zusammengesetzter Schlüssel werden übersprungen
                    ['cardType' => 8, 'prop_key' => 'p:fan:mode', 'operation' => [['disable_status' => [['key' => 'p:fan:mode', 'value' => 'Turbo']]]]],
                    ['cardType' => 2, 'prop_key' => 'p:fan:on,a:fan:toggle', 'operation' => [['action' => 'a:fan:toggle', 'disable_status' => [['key' => 'p:fan:on', 'value' => false]]]]]
                ]
            ]
        ];
    }

    private function spec(): array
    {
        return [
            'services' => [[
                'iid'        => 2,
                'type'       => 'fan',
                'properties' => [
                    ['iid' => 1, 'type' => 'on', 'format' => 'bool', 'values' => []],
                    ['iid' => 2, 'type' => 'horizontal-swing', 'format' => 'bool', 'values' => []],
                    ['iid' => 3, 'type' => 'mode', 'format' => 'uint8', 'values' => [['value' => 0, 'name' => 'Direkt', 'description' => 'Straight Wind'], ['value' => 1, 'name' => 'Natürlich', 'description' => 'Natural Wind']]],
                    ['iid' => 4, 'type' => 'fan-level', 'format' => 'uint8', 'values' => []]
                ]
            ]]
        ];
    }
}
