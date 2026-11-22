<?php

declare(strict_types=1);

/**
 * Lokales miIO-Protokoll (UDP 54321): Paketaufbau, Verschlüsselung und Prüfsumme.
 * Ohne Symcon-Abhängigkeit, damit es mit Referenzvektoren getestet werden kann.
 *
 * Paket: 32 Byte Kopf + verschlüsselte Nutzdaten
 *   0x2131 | Länge (2) | 0x00000000 | DeviceId (4) | Stamp (4) | MD5-Prüfsumme (16)
 * Die Prüfsumme wird über das Paket berechnet, in dem an ihrer Stelle der Token steht.
 * Nutzdaten: AES-128-CBC mit Key = MD5(Token), IV = MD5(Key + Token).
 * Handshake: Kopf ohne Nutzdaten, alle Felder ab Byte 4 mit 0xFF gefüllt.
 */

namespace Xiaomi\Miio {
    /**
     * Packet
     */
    class Packet
    {
        /** Magic der ersten zwei Byte */
        public const Magic = "\x21\x31";
        /** Länge des Kopfes in Byte */
        public const HeaderLength = 32;
        /** Fehler: Kopf, Länge oder Entschlüsselung ungültig */
        public const ErrorPacket = 300;
        /** Fehler: Prüfsumme passt nicht zum Token */
        public const ErrorChecksum = 301;

        /**
         * Handshake
         *
         * @return string Handshake-Anfrage (32 Byte)
         */
        public static function Handshake(): string
        {
            return self::Magic . "\x00\x20" . str_repeat("\xff", 28);
        }

        /**
         * KeyAndIV
         *
         * @param  string $Token Token (16 Byte, binär)
         * @return array [Key, IV]
         */
        public static function KeyAndIV(string $Token): array
        {
            $Key = md5($Token, true);
            return [$Key, md5($Key . $Token, true)];
        }

        /**
         * Encode
         *
         * @param  string $Data Nutzdaten (JSON)
         * @param  string $Token Token (16 Byte, binär)
         * @param  int $DeviceId Geräte-ID
         * @param  int $Stamp Zeitstempel des Gerätes
         * @return string Paket
         */
        public static function Encode(string $Data, string $Token, int $DeviceId, int $Stamp): string
        {
            [$Key, $IV] = self::KeyAndIV($Token);
            $Encrypted = openssl_encrypt($Data, 'aes-128-cbc', $Key, OPENSSL_RAW_DATA, $IV);
            $Head = self::Magic . pack('n', self::HeaderLength + strlen($Encrypted)) . "\x00\x00\x00\x00" . pack('N', $DeviceId) . pack('N', $Stamp);
            $Checksum = md5($Head . $Token . $Encrypted, true);
            return $Head . $Checksum . $Encrypted;
        }

        /**
         * Decode
         *
         * Prüft Kopf und Länge, liest Geräte-ID und Zeitstempel und entschlüsselt die Nutzdaten.
         * Ein Handshake (ohne Nutzdaten) liefert Payload ''. Die Prüfsumme wird nur bei Nutzdaten geprüft.
         *
         * @param  string $Packet Empfangenes Paket
         * @param  string $Token Token (16 Byte, binär), bei Handshake beliebig
         * @return array ['error' => 0|ErrorPacket|ErrorChecksum, 'deviceId' => int, 'stamp' => int, 'payload' => ?string]
         */
        public static function Decode(string $Packet, string $Token): array
        {
            $Result = ['error' => self::ErrorPacket, 'deviceId' => 0, 'stamp' => 0, 'payload' => null];
            if ((strlen($Packet) < self::HeaderLength) || (substr($Packet, 0, 2) != self::Magic)) {
                return $Result;
            }
            if (unpack('n', $Packet, 2)[1] != strlen($Packet)) {
                return $Result;
            }
            $Result['deviceId'] = unpack('N', $Packet, 8)[1];
            $Result['stamp'] = unpack('N', $Packet, 12)[1];
            $Encrypted = substr($Packet, self::HeaderLength);
            if ($Encrypted === '') {
                $Result['error'] = 0;
                $Result['payload'] = '';
                return $Result;
            }
            if (md5(substr($Packet, 0, 16) . $Token . $Encrypted, true) !== substr($Packet, 16, 16)) {
                $Result['error'] = self::ErrorChecksum;
                return $Result;
            }
            [$Key, $IV] = self::KeyAndIV($Token);
            $Data = openssl_decrypt($Encrypted, 'aes-128-cbc', $Key, OPENSSL_RAW_DATA, $IV);
            if ($Data === false) {
                return $Result;
            }
            $Result['error'] = 0;
            $Result['payload'] = $Data;
            return $Result;
        }

        /**
         * NextStamp
         *
         * Zeitstempel für die nächste Anfrage: letzter Gerätewert plus vergangene Sekunden, mindestens +1,
         * damit er auch im selben Sekundenintervall steigt (python-miio sendet immer Gerätewert + 1).
         *
         * @param  int $DeviceStamp Zuletzt empfangener Zeitstempel des Gerätes
         * @param  int $SecondsPassed Seit dem Empfang vergangene Sekunden
         * @return int
         */
        public static function NextStamp(int $DeviceStamp, int $SecondsPassed): int
        {
            return $DeviceStamp + max(1, $SecondsPassed);
        }
    }
}
