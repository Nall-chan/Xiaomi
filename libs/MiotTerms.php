<?php

declare(strict_types=1);

/**
 * Begriffe und Einheiten der Xiaomi-App (`card_default_txt`, öffentlich über `getappconfig`).
 * Die vollständige Tabelle enthält alle Sprachen (ca. 290 KB); behalten wird nur eine Sprache.
 * Ohne Symcon-Abhängigkeit, damit sie mit Testdaten geprüft werden kann.
 */

namespace Xiaomi\Terms {
    /**
     * Terms
     */
    class Terms
    {
        /** Name und Version der Konfiguration in getappconfig (Version als Text, als Zahl: „data type not valid“) */
        public const ConfigName = 'card_default_txt';
        public const ConfigVersion = '2';

        /**
         * Reduce
         *
         * @param  array $Content Inhalt von card_default_txt (complex_units, units, short_name, names)
         * @param  string $SystemLanguage Symcon-Systemsprache (z.B. de_DE, zh_TW)
         * @return array ['names' => [Schlüssel oder englischer Text => Übersetzung], 'units' => [Einheit => Übersetzung]]
         */
        public static function Reduce(array $Content, string $SystemLanguage): array
        {
            $Keys = self::LanguageKeys($SystemLanguage);
            $Names = [];
            foreach ((array) ($Content['names'] ?? []) as $Key => $Texts) {
                $Text = self::Pick((array) $Texts, $Keys);
                if ($Text === '') {
                    continue;
                }
                $Names[(string) $Key] = $Text;
                // Spec-Beschreibungen sind englisch: auch über den englischen Text finden
                if (!empty($Texts['en'])) {
                    $Names[(string) $Texts['en']] = $Text;
                }
            }
            $Units = [];
            foreach ((array) ($Content['units'] ?? []) as $Key => $Unit) {
                $Text = self::Pick((array) ($Unit['name'] ?? []), $Keys);
                if ($Text !== '') {
                    $Units[(string) $Key] = $Text;
                }
            }
            // Zusammengesetzte Einheiten (z.B. seconds mit Teiler) haben Vorrang
            foreach ((array) ($Content['complex_units'][0] ?? []) as $Unit) {
                $Text = self::Pick((array) ($Unit['name'] ?? []), $Keys);
                if (!empty($Unit['key']) && ($Text !== '')) {
                    $Units[(string) $Unit['key']] = $Text;
                }
            }
            return ['names' => $Names, 'units' => $Units];
        }

        /**
         * Name
         *
         * @param  array $Terms Ergebnis von Reduce
         * @param  string $Name Begriff (Schlüssel oder englischer Text)
         * @return string Übersetzung, sonst der Begriff selbst
         */
        public static function Name(array $Terms, string $Name): string
        {
            return $Terms['names'][$Name] ?? $Name;
        }

        /**
         * Unit
         *
         * @param  array $Terms Ergebnis von Reduce
         * @param  string $Unit Einheit der Spec (z.B. celsius, seconds)
         * @return string Übersetzung, sonst die Einheit selbst
         */
        public static function Unit(array $Terms, string $Unit): string
        {
            return $Terms['units'][$Unit] ?? $Unit;
        }

        /**
         * LanguageKeys
         *
         * Reihenfolge der Sprachschlüssel: genaue Variante (zh_TW, pt_BR), dann Sprache (de), dann Englisch.
         *
         * @param  string $SystemLanguage
         * @return array
         */
        private static function LanguageKeys(string $SystemLanguage): array
        {
            $Parts = explode('_', $SystemLanguage);
            $Keys = [];
            if (isset($Parts[1])) {
                $Keys[] = strtolower($Parts[0]) . '_' . strtoupper($Parts[1]);
            }
            $Keys[] = strtolower($Parts[0]);
            $Keys[] = 'en';
            return array_values(array_unique($Keys));
        }

        /**
         * Pick
         *
         * @param  array $Texts Sprache => Text
         * @param  array $Keys Sprachschlüssel in Reihenfolge
         * @return string
         */
        private static function Pick(array $Texts, array $Keys): string
        {
            foreach ($Keys as $Key) {
                if (isset($Texts[$Key]) && is_string($Texts[$Key]) && ($Texts[$Key] !== '')) {
                    return $Texts[$Key];
                }
            }
            return '';
        }
    }
}
