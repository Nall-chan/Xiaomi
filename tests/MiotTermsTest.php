<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MiotTerms.php';

use PHPUnit\Framework\TestCase;
use Xiaomi\Terms\Terms;

/**
 * Begriffe und Einheiten aus card_default_txt auf eine Sprache verkleinern.
 * Testdaten im Aufbau der echten Konfiguration, Inhalte selbst gewählt.
 */
class MiotTermsTest extends TestCase
{
    public function testReduceGerman(): void
    {
        $Terms = Terms::Reduce($this->content(), 'de_DE');
        $this->assertSame('Ein', Terms::Name($Terms, 'switch_on'));
        // über den englischen Text der Spec
        $this->assertSame('Ein', Terms::Name($Terms, 'On'));
        // deutsch fehlt → englisch
        $this->assertSame('Only English', Terms::Name($Terms, 'english_only'));
        $this->assertSame('Unbekannt', Terms::Name($Terms, 'Unbekannt'));
        $this->assertSame('°C', Terms::Unit($Terms, 'celsius'));
        // zusammengesetzte Einheit hat Vorrang
        $this->assertSame('Sek.', Terms::Unit($Terms, 'seconds'));
        $this->assertSame('lux', Terms::Unit($Terms, 'lux'));
    }

    public function testReduceLanguageVariant(): void
    {
        $Terms = Terms::Reduce($this->content(), 'zh_TW');
        $this->assertSame('開', Terms::Name($Terms, 'switch_on'));
        $Terms = Terms::Reduce($this->content(), 'zh_CN');
        $this->assertSame('开', Terms::Name($Terms, 'switch_on'));
    }

    private function content(): array
    {
        return [
            'complex_units' => [[
                ['key' => 'seconds', 'divider' => ' ', 'name' => ['en' => 's', 'de' => 'Sek.']]
            ]],
            'units' => [
                'celsius' => ['name' => ['en' => '°C', 'de' => '°C']],
                'seconds' => ['name' => ['en' => 's', 'de' => 's']]
            ],
            'short_name' => [['en' => '0']],
            'names'      => [
                'switch_on'    => ['en' => 'On', 'de' => 'Ein', 'zh_CN' => '开', 'zh_TW' => '開'],
                'english_only' => ['en' => 'Only English', 'de' => '']
            ]
        ];
    }
}
