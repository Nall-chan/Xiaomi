<?php

declare(strict_types=1);

/**
 * Gerätekarten der Xiaomi-App (`card_control_miotspec_config`, öffentlich über `getappconfig`).
 * Genutzt werden die Sperren: Ein Element ist nicht bedienbar, solange eine Bedingung erfüllt ist
 * (z.B. Lüfterstufe gesperrt, wenn aus oder Modus = „Natural Wind“).
 * Ohne Symcon-Abhängigkeit, damit sie mit Testdaten geprüft werden kann.
 */

namespace Xiaomi\Card {
    /**
     * Card
     */
    class Card
    {
        /** Name und Version der Konfiguration in getappconfig (Version als Text) */
        public const ConfigName = 'card_control_miotspec_config';
        public const ConfigVersion = '8';

        /**
         * DeviceType
         *
         * `urn:miot-spec-v2:device:fan:0000A005:xiaomi-p76:1` → `fan`
         *
         * @param  string $Urn
         * @return string
         */
        public static function DeviceType(string $Urn): string
        {
            return explode(':', $Urn)[3] ?? '';
        }

        /**
         * Find
         *
         * @param  array $Configs Alle Karten (Liste aus card_control_miotspec_config)
         * @param  string $Urn Volle URN des Gerätes
         * @return ?array Karte für den Gerätetyp, null wenn keine passt
         */
        public static function Find(array $Configs, string $Urn): ?array
        {
            $Type = self::DeviceType($Urn);
            foreach ($Configs as $Card) {
                if (!in_array($Type, (array) ($Card['types'] ?? []))) {
                    continue;
                }
                // Einzelne Modelle sind ausgenommen (volle URN oder URN ohne Suffix)
                foreach ((array) ($Card['disable_type'] ?? []) as $Disabled) {
                    if (($Disabled === $Urn) || str_starts_with($Urn, $Disabled . ':')) {
                        continue 2;
                    }
                }
                return $Card;
            }
            return null;
        }

        /**
         * Locks
         *
         * Sperren der Karte, übersetzt auf die Eigenschaften der Gerätebeschreibung (eigenes Format, Schema ≥ 2).
         *
         * @param  array $Card Karte (Ergebnis von Find)
         * @param  array $Spec Gerätebeschreibung im eigenen Format
         * @param  string $Prefix Präfix der Variablen-Idents (z.B. 'P')
         * @return array Ident => [['ident' => Ident der Bedingung, 'value' => Wert passend zum Typ], …]
         */
        public static function Locks(array $Card, array $Spec, string $Prefix): array
        {
            $Locks = [];
            foreach ((array) ($Card['card_items'] ?? []) as $Item) {
                $Target = self::Resolve($Spec, (string) ($Item['prop_key'] ?? ''));
                if (is_null($Target)) {
                    continue;
                }
                $Ident = $Prefix . '_' . $Target['siid'] . '_' . $Target['piid'];
                foreach ((array) ($Item['operation'] ?? []) as $Operation) {
                    // Nur reine Sperren; Operationen mit Aktion (z.B. Start/Pause-Knopf) bleiben unberücksichtigt
                    if (isset($Operation['action'])) {
                        continue;
                    }
                    foreach ((array) ($Operation['disable_status'] ?? []) as $Condition) {
                        $Source = self::Resolve($Spec, (string) ($Condition['key'] ?? ''));
                        if (is_null($Source)) {
                            continue;
                        }
                        $Value = self::Value($Source['property'], $Condition['value'] ?? null);
                        if (is_null($Value)) {
                            continue;
                        }
                        $Locks[$Ident][] = ['ident' => $Prefix . '_' . $Source['siid'] . '_' . $Source['piid'], 'value' => $Value];
                    }
                }
            }
            // Mehrere Kartenelemente können dieselbe Eigenschaft nennen (z.B. p:fan:fan-level und pi:2:4)
            foreach ($Locks as $Ident => $Conditions) {
                $Locks[$Ident] = array_values(array_unique($Conditions, SORT_REGULAR));
            }
            return $Locks;
        }

        /**
         * IsLocked
         *
         * @param  array $Conditions Bedingungen einer Sperre (Eintrag aus Locks)
         * @param  callable $GetValue fn (string $Ident): mixed, aktueller Wert, null wenn unbekannt
         * @return bool true, wenn mindestens eine Bedingung erfüllt ist
         */
        public static function IsLocked(array $Conditions, callable $GetValue): bool
        {
            foreach ($Conditions as $Condition) {
                $Current = $GetValue($Condition['ident']);
                if (!is_null($Current) && ($Current == $Condition['value'])) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Resolve
         *
         * `p:<service>:<eigenschaft>` (Kurztypen) oder `pi:<siid>:<piid>` → Eigenschaft der Gerätebeschreibung.
         * Zusammengesetzte Schlüssel (mit Komma) und Ereignisse werden nicht aufgelöst.
         *
         * @param  array $Spec
         * @param  string $Key
         * @return ?array ['siid', 'piid', 'property']
         */
        private static function Resolve(array $Spec, string $Key): ?array
        {
            if (str_contains($Key, ',')) {
                return null;
            }
            $Parts = explode(':', $Key);
            if (count($Parts) != 3) {
                return null;
            }
            foreach ((array) ($Spec['services'] ?? []) as $Service) {
                foreach ((array) ($Service['properties'] ?? []) as $Property) {
                    $Match = ($Parts[0] === 'p') && ($Service['type'] === $Parts[1]) && ($Property['type'] === $Parts[2]);
                    $Match = $Match || (($Parts[0] === 'pi') && ((string) $Service['iid'] === $Parts[1]) && ((string) $Property['iid'] === $Parts[2]));
                    if ($Match) {
                        return ['siid' => (int) $Service['iid'], 'piid' => (int) $Property['iid'], 'property' => $Property];
                    }
                }
            }
            return null;
        }

        /**
         * Value
         *
         * Wert einer Bedingung passend zur Eigenschaft: bool aus true/"true"/1, Werteliste über den englischen Text, sonst Zahl/Text.
         *
         * @param  array $Property
         * @param  mixed $Value
         * @return mixed null, wenn der Wert nicht zugeordnet werden kann
         */
        private static function Value(array $Property, mixed $Value): mixed
        {
            if (is_null($Value)) {
                return null;
            }
            if ($Property['format'] === 'bool') {
                return ($Value === true) || ($Value === 1) || (strtolower((string) $Value) === 'true') || ((string) $Value === '1');
            }
            foreach ((array) ($Property['values'] ?? []) as $Entry) {
                if ((strcasecmp((string) ($Entry['description'] ?? ''), (string) $Value) === 0) || ((string) $Entry['value'] === (string) $Value)) {
                    return $Entry['value'];
                }
            }
            if (count((array) ($Property['values'] ?? []))) {
                // Text gehört zu keinem Wert der Liste (z.B. Status eines anderen Modells)
                return null;
            }
            return is_numeric($Value) ? $Value + 0 : (string) $Value;
        }
    }
}
