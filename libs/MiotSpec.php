<?php

declare(strict_types=1);

/**
 * MIoT-Gerätebeschreibung (Spec) in ein eigenes, einheitliches Format übersetzen.
 * Ohne Symcon-Abhängigkeit, damit es mit Testdaten geprüft werden kann.
 *
 * Quellen der Rohdaten (gleiches Format): Cloud `Gspec/get_instances_by_dids` + `Gspec/spec_multi_language`
 * oder öffentlich miot-spec.org `miot-spec-v2/instance?type=` + `instance/v2/multiLanguage?urn=`.
 * Übersetzungsschlüssel: `service:002`, `service:002:property:003`, `service:002:property:003:valuelist:000`
 * (Position in der Werteliste, nicht der Wert), `service:002:action:001`, `service:002:event:001`.
 */

namespace Xiaomi\Spec {
    /**
     * Key
     *
     * Schlüssel des eigenen Formats.
     */
    class Key
    {
        public const Schema = 'schema';
        public const Urn = 'urn';
        public const Description = 'description';
        public const Services = 'services';
        public const Properties = 'properties';
        public const Actions = 'actions';
        public const Iid = 'iid';
        public const Type = 'type';
        public const Name = 'name';
        public const Format = 'format';
        public const Access = 'access';
        public const Unit = 'unit';
        public const Range = 'range';
        public const Values = 'values';
        public const Value = 'value';
        public const In = 'in';
        public const Out = 'out';
    }

    /**
     * Normalizer
     */
    class Normalizer
    {
        /** Version des eigenen Formats, bei Änderungen erhöhen */
        public const SchemaVersion = 2;

        /**
         * Normalize
         *
         * @param  array $Instance Rohdaten der Spec (type, description, services …)
         * @param  array $Languages Übersetzungen je Sprache: ['de' => [Schlüssel => Text], 'en' => …]
         * @param  string $Language Gewünschte Sprache (z.B. 'de'), Kette: Sprache → en → Beschreibung
         * @return array Gerätebeschreibung im eigenen Format
         */
        public static function Normalize(array $Instance, array $Languages, string $Language): array
        {
            // Leere Übersetzungen überspringen, Sprache überschreibt Englisch
            $NotEmpty = fn ($Text): bool => is_string($Text) && ($Text !== '');
            $Texts = array_merge(
                array_filter((array) ($Languages['en'] ?? []), $NotEmpty),
                array_filter((array) ($Languages[$Language] ?? []), $NotEmpty)
            );
            $Services = [];
            foreach ((array) ($Instance['services'] ?? []) as $Service) {
                $Siid = (int) $Service['iid'];
                $Prefix = sprintf('service:%03d', $Siid);
                $Properties = [];
                foreach ((array) ($Service['properties'] ?? []) as $Property) {
                    $Piid = (int) $Property['iid'];
                    $Key = sprintf('%s:property:%03d', $Prefix, $Piid);
                    $Values = [];
                    foreach (array_values((array) ($Property['value-list'] ?? [])) as $Index => $Entry) {
                        $Values[] = [
                            Key::Value       => $Entry['value'],
                            Key::Name        => $Texts[sprintf('%s:valuelist:%03d', $Key, $Index)] ?? (string) ($Entry['description'] ?? ''),
                            // Englischer Text der Spec, z.B. für Sperren der App-Karten (Schema 2)
                            Key::Description => (string) ($Entry['description'] ?? '')
                        ];
                    }
                    $Range = $Property['value-range'] ?? null;
                    $Properties[] = [
                        Key::Iid         => $Piid,
                        Key::Type        => self::ShortType((string) ($Property['type'] ?? '')),
                        Key::Name        => $Texts[$Key] ?? (string) ($Property['description'] ?? ''),
                        Key::Description => (string) ($Property['description'] ?? ''),
                        Key::Format      => (string) ($Property['format'] ?? ''),
                        Key::Access      => array_values((array) ($Property['access'] ?? [])),
                        Key::Unit        => (string) ($Property['unit'] ?? ''),
                        Key::Range       => is_array($Range) && (count($Range) >= 2) ? array_values($Range) : null,
                        Key::Values      => $Values
                    ];
                }
                $Actions = [];
                foreach ((array) ($Service['actions'] ?? []) as $Action) {
                    $Aiid = (int) $Action['iid'];
                    $Actions[] = [
                        Key::Iid         => $Aiid,
                        Key::Type        => self::ShortType((string) ($Action['type'] ?? '')),
                        Key::Name        => $Texts[sprintf('%s:action:%03d', $Prefix, $Aiid)] ?? (string) ($Action['description'] ?? ''),
                        Key::Description => (string) ($Action['description'] ?? ''),
                        Key::In          => array_map('intval', (array) ($Action['in'] ?? [])),
                        Key::Out         => array_map('intval', (array) ($Action['out'] ?? []))
                    ];
                }
                $Services[] = [
                    Key::Iid         => $Siid,
                    Key::Type        => self::ShortType((string) ($Service['type'] ?? '')),
                    Key::Name        => $Texts[$Prefix] ?? (string) ($Service['description'] ?? ''),
                    Key::Description => (string) ($Service['description'] ?? ''),
                    Key::Properties  => $Properties,
                    Key::Actions     => $Actions
                ];
            }
            return [
                Key::Schema      => self::SchemaVersion,
                Key::Urn         => (string) ($Instance['type'] ?? ''),
                Key::Description => (string) ($Instance['description'] ?? ''),
                Key::Services    => $Services
            ];
        }

        /**
         * ShortType
         *
         * `urn:miot-spec-v2:property:fan-level:00000016:xiaomi-p76:1` → `fan-level`
         *
         * @param  string $Urn
         * @return string
         */
        public static function ShortType(string $Urn): string
        {
            return explode(':', $Urn)[3] ?? '';
        }

        /**
         * Language
         *
         * Symcon-Systemsprache (z.B. `de_DE`, `zh_TW`) → Sprachschlüssel der Spec-Übersetzungen.
         *
         * @param  string $SystemLanguage
         * @return string
         */
        public static function Language(string $SystemLanguage): string
        {
            $Parts = explode('_', strtolower($SystemLanguage));
            if ($Parts[0] == 'zh') {
                // Traditionelles Chinesisch: zh_hk wie zh_tw
                return (in_array($Parts[1] ?? '', ['tw', 'hk'])) ? 'zh_tw' : 'zh_cn';
            }
            return $Parts[0];
        }
    }
}
