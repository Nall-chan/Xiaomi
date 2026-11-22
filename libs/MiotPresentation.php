<?php

declare(strict_types=1);

/**
 * Darstellung (Symcon ≥ 8.0) einer MIoT-Eigenschaft oder -Aktion aus der Gerätebeschreibung im eigenen Format
 * (libs/MiotSpec.php). Ohne Symcon-Abhängigkeit, damit sie gegen die Parameterlisten der Darstellungen
 * (tests/presentations.json) geprüft werden kann.
 */

namespace Xiaomi\Presentation {
    /**
     * Id
     *
     * GUIDs der Darstellungen (entsprechen den Symcon-Konstanten VARIABLE_PRESENTATION_*).
     */
    class Id
    {
        public const Color = '{05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB}';
        public const ValuePresentation = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
        public const Enumeration = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';
        public const Switch = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
        public const Slider = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
        public const ValueInput = '{6F477326-1683-A2FD-D2E7-477F366ECB62}';
    }

    /**
     * Builder
     */
    class Builder
    {
        /** Variablentypen (entsprechen VARIABLETYPE_*) */
        public const TypeBoolean = 0;
        public const TypeInteger = 1;
        public const TypeFloat = 2;
        public const TypeString = 3;
        /** Schieberegler: Verlauf Farbtemperatur, Verwendung Farbtemperatur */
        public const GradientColorTemperature = 2;
        public const UsageColorTemperature = 1;

        /**
         * VariableType
         *
         * @param  string $Format MIoT-Format (bool, uint8 … int32, float, string)
         * @return int
         */
        public static function VariableType(string $Format): int
        {
            switch ($Format) {
                case 'bool':
                    return self::TypeBoolean;
                case 'uint8':
                case 'uint16':
                case 'uint32':
                case 'int8':
                case 'int16':
                case 'int32':
                case 'int64':
                    return self::TypeInteger;
                case 'float':
                case 'double':
                    return self::TypeFloat;
            }
            return self::TypeString;
        }

        /**
         * ForProperty
         *
         * @param  array $Property Eigenschaft im eigenen Format
         * @param  bool $Writable Variable bekommt eine Aktion (schreibbare Eigenschaft oder Parameter einer Aktion)
         * @param  string $Suffix Einheit mit führendem Leerzeichen (z.B. ' %'), '' ohne Einheit
         * @return array [Variablentyp, Darstellung]
         */
        public static function ForProperty(array $Property, bool $Writable, string $Suffix): array
        {
            $Type = self::VariableType((string) $Property['format']);
            $Unit = (string) $Property['unit'];
            $Values = (array) $Property['values'];
            $Range = $Property['range'];
            if (($Unit === 'rgb') && ($Type === self::TypeInteger)) {
                return [$Type, ['PRESENTATION' => Id::Color]];
            }
            if (count($Values)) {
                return [$Type, $Writable ? self::Enumeration($Values, $Type) : self::ValueList($Values, $Type)];
            }
            if ($Type === self::TypeBoolean) {
                return [$Type, ['PRESENTATION' => $Writable ? Id::Switch : Id::ValuePresentation]];
            }
            if ($Type === self::TypeString) {
                return [$Type, ['PRESENTATION' => $Writable ? Id::ValueInput : Id::ValuePresentation]];
            }
            // Zahl
            $Digits = self::Digits($Type, is_array($Range) ? ($Range[2] ?? 0) : 0);
            if (!$Writable) {
                $Presentation = ['PRESENTATION' => Id::ValuePresentation, 'SUFFIX' => $Suffix, 'DIGITS' => $Digits];
                if (is_array($Range) && ($Range[1] > $Range[0])) {
                    $Presentation['MIN'] = self::Number($Range[0], $Type);
                    $Presentation['MAX'] = self::Number($Range[1], $Type);
                }
                return [$Type, $Presentation];
            }
            if (!is_array($Range) || ($Range[1] <= $Range[0])) {
                return [$Type, ['PRESENTATION' => Id::ValueInput, 'SUFFIX' => $Suffix]];
            }
            $Step = (($Range[2] ?? 0) > 0) ? $Range[2] : 1;
            $Presentation = [
                'PRESENTATION' => Id::Slider,
                'MIN'          => self::Number($Range[0], $Type),
                'MAX'          => self::Number($Range[1], $Type),
                'STEP_SIZE'    => self::Number($Step, $Type),
                'DIGITS'       => $Digits,
                'SUFFIX'       => $Suffix
            ];
            if ($Unit === 'kelvin') {
                $Presentation['GRADIENT_TYPE'] = self::GradientColorTemperature;
                $Presentation['USAGE_TYPE'] = self::UsageColorTemperature;
            }
            return [$Type, $Presentation];
        }

        /**
         * ForExecuteAction
         *
         * Aktion ohne Parameter: Integer-Variable mit einer Option.
         *
         * @param  string $Caption Beschriftung (übersetzt), z.B. „Ausführen“
         * @return array [Variablentyp, Darstellung]
         */
        public static function ForExecuteAction(string $Caption): array
        {
            return [self::TypeInteger, self::Enumeration([['value' => 0, 'name' => $Caption]], self::TypeInteger)];
        }

        /**
         * Enumeration
         *
         * @param  array $Values Werteliste [['value', 'name']]
         * @param  int $Type Variablentyp
         * @return array
         */
        private static function Enumeration(array $Values, int $Type): array
        {
            $Options = [];
            foreach ($Values as $Value) {
                $Options[] = [
                    'Value'      => self::Typed($Value['value'], $Type),
                    'Caption'    => (string) $Value['name'],
                    'IconActive' => false,
                    'IconValue'  => '',
                    'Color'      => -1
                ];
            }
            return ['PRESENTATION' => Id::Enumeration, 'OPTIONS' => json_encode($Options)];
        }

        /**
         * ValueList
         *
         * Nur lesbare Werteliste: Text über OPTIONS, Zahl über Intervalle mit festem Text (die Wertanzeige für Zahlen hat keine OPTIONS).
         *
         * @param  array $Values Werteliste [['value', 'name']]
         * @param  int $Type Variablentyp
         * @return array
         */
        private static function ValueList(array $Values, int $Type): array
        {
            if (($Type === self::TypeString) || ($Type === self::TypeBoolean)) {
                $Options = [];
                foreach ($Values as $Value) {
                    $Options[] = [
                        'Value'      => self::Typed($Value['value'], $Type),
                        'Caption'    => (string) $Value['name'],
                        'IconActive' => false,
                        'IconValue'  => '',
                        'ColorActive'=> false,
                        'ColorValue' => -1
                    ];
                }
                return ['PRESENTATION' => Id::ValuePresentation, 'OPTIONS' => json_encode($Options)];
            }
            $Intervals = [];
            foreach ($Values as $Value) {
                $Number = self::Typed($Value['value'], $Type);
                $Intervals[] = [
                    'IntervalMinValue' => $Number,
                    'IntervalMaxValue' => $Number,
                    'ConstantActive'   => true,
                    'ConstantValue'    => (string) $Value['name']
                ];
            }
            return [
                'PRESENTATION'     => Id::ValuePresentation,
                'INTERVALS_ACTIVE' => true,
                'INTERVALS'        => json_encode($Intervals),
                'DIGITS'           => 0
            ];
        }

        /**
         * Digits
         *
         * Nachkommastellen aus der Schrittweite (0.1 → 1, 0.01 → 2), Float ohne Schrittweite 1, Integer 0.
         *
         * @param  int $Type
         * @param  int|float $Step
         * @return int
         */
        private static function Digits(int $Type, int|float $Step): int
        {
            if ($Type !== self::TypeFloat) {
                return 0;
            }
            if ($Step <= 0) {
                return 1;
            }
            $Digits = 0;
            while (($Digits < 6) && (abs($Step * (10 ** $Digits) - round($Step * (10 ** $Digits))) > 1e-9)) {
                $Digits++;
            }
            return $Digits;
        }

        /**
         * Number
         *
         * @param  mixed $Value
         * @param  int $Type
         * @return int|float
         */
        private static function Number(mixed $Value, int $Type): int|float
        {
            return ($Type === self::TypeInteger) ? (int) $Value : (float) $Value;
        }

        /**
         * Typed
         *
         * @param  mixed $Value
         * @param  int $Type
         * @return mixed Wert passend zum Variablentyp
         */
        private static function Typed(mixed $Value, int $Type): mixed
        {
            switch ($Type) {
                case self::TypeBoolean:
                    return (bool) $Value;
                case self::TypeInteger:
                    return (int) $Value;
                case self::TypeFloat:
                    return (float) $Value;
            }
            return (string) $Value;
        }
    }
}
