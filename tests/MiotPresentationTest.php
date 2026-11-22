<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MiotPresentation.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Xiaomi\Presentation\Builder;
use Xiaomi\Presentation\Id;

/**
 * Darstellungen aus der Gerätebeschreibung: Auswahl der Darstellung und Prüfung aller Parameter gegen
 * die Parameterlisten der Darstellungen (tests/presentations.json, aus IPS_GetPresentations, Symcon 9.1).
 */
class MiotPresentationTest extends TestCase
{
    /** @var array */
    private static $Presentations;

    public static function setUpBeforeClass(): void
    {
        self::$Presentations = json_decode(file_get_contents(__DIR__ . '/presentations.json'), true);
    }

    /**
     * @return array [Beschreibung => [Eigenschaft, schreibbar, Suffix, erwartete Darstellung]]
     */
    public static function cases(): array
    {
        $p = fn (string $Format, string $Unit = '', ?array $Range = null, array $Values = []) => [
            'format' => $Format, 'unit' => $Unit, 'range' => $Range, 'values' => $Values
        ];
        $List = [['value' => 0, 'name' => 'Direkt'], ['value' => 1, 'name' => 'Natürlich']];
        return [
            'bool schreibbar'          => [$p('bool'), true, '', Id::Switch],
            'bool lesbar'              => [$p('bool'), false, '', Id::ValuePresentation],
            'Liste schreibbar'         => [$p('uint8', '', null, $List), true, '', Id::Enumeration],
            'Liste lesbar'             => [$p('uint8', '', null, $List), false, '', Id::ValuePresentation],
            'Text-Liste lesbar'        => [$p('string', '', null, [['value' => 'a', 'name' => 'A']]), false, '', Id::ValuePresentation],
            'Bool-Liste lesbar'        => [$p('bool', '', null, [['value' => false, 'name' => 'Zu'], ['value' => true, 'name' => 'Auf']]), false, '', Id::ValuePresentation],
            'Bereich schreibbar'       => [$p('uint8', 'percentage', [1, 100, 1]), true, ' %', Id::Slider],
            'Float-Bereich schreibbar' => [$p('float', 'celsius', [16, 30, 0.5]), true, ' °C', Id::Slider],
            'Kelvin schreibbar'        => [$p('uint32', 'kelvin', [2700, 6500, 1]), true, ' K', Id::Slider],
            'Bereich lesbar'           => [$p('float', 'celsius', [-40, 125, 0.1]), false, ' °C', Id::ValuePresentation],
            'Zahl ohne Bereich'        => [$p('int32'), true, '', Id::ValueInput],
            'Zahl lesbar'              => [$p('uint16', 'seconds'), false, ' s', Id::ValuePresentation],
            'Text schreibbar'          => [$p('string'), true, '', Id::ValueInput],
            'Text lesbar'              => [$p('string'), false, '', Id::ValuePresentation],
            'RGB'                      => [$p('uint32', 'rgb', [0, 16777215, 1]), true, '', Id::Color]
        ];
    }

    #[DataProvider('cases')]
    public function testPresentationIsValid(array $Property, bool $Writable, string $Suffix, string $Expected): void
    {
        [$Type, $Presentation] = Builder::ForProperty($Property, $Writable, $Suffix);
        $this->assertSame($Expected, $Presentation['PRESENTATION']);
        $this->assertValid($Type, $Presentation, $Writable);
    }

    public function testExecuteAction(): void
    {
        [$Type, $Presentation] = Builder::ForExecuteAction('Ausführen');
        $this->assertSame(Builder::TypeInteger, $Type);
        $this->assertSame(Id::Enumeration, $Presentation['PRESENTATION']);
        $this->assertSame([['Value' => 0, 'Caption' => 'Ausführen', 'IconActive' => false, 'IconValue' => '', 'Color' => -1]], json_decode($Presentation['OPTIONS'], true));
        $this->assertValid($Type, $Presentation, true);
    }

    public function testSliderValues(): void
    {
        [$Type, $Presentation] = Builder::ForProperty(['format' => 'float', 'unit' => 'celsius', 'range' => [16, 30, 0.5], 'values' => []], true, ' °C');
        $this->assertSame(Builder::TypeFloat, $Type);
        $this->assertSame(16.0, $Presentation['MIN']);
        $this->assertSame(0.5, $Presentation['STEP_SIZE']);
        $this->assertSame(1, $Presentation['DIGITS']);
        [, $Presentation] = Builder::ForProperty(['format' => 'uint32', 'unit' => 'kelvin', 'range' => [2700, 6500, 1], 'values' => []], true, ' K');
        $this->assertSame(Builder::GradientColorTemperature, $Presentation['GRADIENT_TYPE']);
    }

    public function testReadOnlyNumericListUsesConstantIntervals(): void
    {
        [, $Presentation] = Builder::ForProperty(['format' => 'uint8', 'unit' => '', 'range' => null, 'values' => [['value' => 2, 'name' => 'Stufe 2']]], false, '');
        $this->assertTrue($Presentation['INTERVALS_ACTIVE']);
        $this->assertSame([['IntervalMinValue' => 2, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'Stufe 2']], json_decode($Presentation['INTERVALS'], true));
    }

    /**
     * Prüft Parameternamen, Werte und Unterparameter gegen presentations.json sowie die Einschränkungen der Darstellung.
     */
    private function assertValid(int $Type, array $Presentation, bool $Writable): void
    {
        $Id = $Presentation['PRESENTATION'];
        $this->assertArrayHasKey($Id, self::$Presentations, 'Unknown presentation');
        $Definition = self::$Presentations[$Id];
        $Restrictions = $Definition['restrictions'];
        if (isset($Restrictions['variableType'])) {
            $this->assertContains($Type, $Restrictions['variableType'], 'Variable type not allowed');
        }
        if (isset($Restrictions['requestAction'])) {
            $this->assertSame($Restrictions['requestAction'], $Writable, 'Action restriction not met');
        }
        $Allowed = $Definition['parameters'];
        foreach ($Definition['groups'] ?? [] as $Group) {
            if (in_array($Type, $Group['variableType'])) {
                $Allowed = array_merge($Allowed, $Group['parameters']);
            }
        }
        foreach ($Presentation as $Name => $Value) {
            if ($Name === 'PRESENTATION') {
                continue;
            }
            $this->assertArrayHasKey($Name, $Allowed, 'Parameter ' . $Name . ' not allowed');
            if (isset($Allowed[$Name]['values'])) {
                $this->assertContains($Value, $Allowed[$Name]['values'], 'Value of ' . $Name . ' not allowed');
            }
            if (isset($Allowed[$Name]['parameters'])) {
                $List = json_decode($Value, true);
                $this->assertIsArray($List, $Name . ' is no JSON list');
                foreach ($List as $Entry) {
                    foreach (array_keys($Entry) as $Key) {
                        $this->assertArrayHasKey($Key, $Allowed[$Name]['parameters'], 'Parameter ' . $Name . '.' . $Key . ' not allowed');
                    }
                }
            }
        }
    }
}
