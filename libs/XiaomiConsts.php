<?php

declare(strict_types=1);

namespace Xiaomi{

    /**
     * GUID
     */
    class GUID
    {
        public const MiDevice = '{733AB5D2-957D-E76A-BA5D-5006701A6216}';
        public const CloudIO = '{DF2248D9-FC17-4609-840D-BA52DBF9CEB6}';
        public const ReceiveFromCloud = '{5F3A76AF-D01E-42A3-93A3-D4E5E9267E32}';
        public const SendToCloud = '{76F2BB7B-F2B9-47EA-88F7-FD357D2E49E1}';
    }

    /**
     * Convert
     */
    class Convert
    {
        /**
         * ToIPSVar
         *
         * @param  string $Format
         * @return int
         */
        public static function ToIPSVar(string $Format): int
        {
            switch ($Format) {
                case 'bool':
                    return VARIABLETYPE_BOOLEAN;
                case 'uint8':
                case 'uint16':
                case 'uint32':
                case 'int8':
                case 'int16':
                case 'int32':
                    return VARIABLETYPE_INTEGER;
                case 'float':
                case 'double':
                    return VARIABLETYPE_FLOAT;
            }
            return VARIABLETYPE_STRING;
        }

        /**
         * getProfileName
         *
         * @param  int $Piid
         * @param  string $Type
         * @return string
         */
        public static function getProfileName(int $Piid, string $Type, string $ProfileSuffix): string
        {
            return \sprintf('XIAOMI.%s.%08d.%s', $Type, $Piid, $ProfileSuffix);
        }

    }

    /**
     * Translate
     */
    class Translate
    {
        /**
         * getLocaleName
         *
         * @param  string $Name
         * @return string
         */
        public static function getLocaleName(string $Name): string
        {
            $Locale = explode('_', IPS_GetSystemLanguage())[0];
            $Names = json_decode(file_get_contents(__DIR__ . '/card_default.json'), true)['names'];
            if (array_key_exists($Name, $Names)) {
                if (array_key_exists($Locale, $Names[$Name])) {
                    return $Names[$Name][$Locale];
                }
            }
            $Columns = array_column($Names, $Locale, 'en');
            if (array_key_exists($Name, $Columns)) {
                return $Columns[$Name];
            }
            return $Name;
        }

        /**
         * getLocaleUnit
         *
         * @param  string $Unit
         * @return string
         */
        public static function getLocaleUnit(string $Unit): string
        {
            $Locale = explode('_', IPS_GetSystemLanguage())[0];
            $All = json_decode(file_get_contents(__DIR__ . '/card_default.json'), true);
            $ComplexUnits = array_column($All['complex_units'][0], 'name', 'key');
            if (array_key_exists($Unit, $ComplexUnits)) {
                if (array_key_exists($Locale, $ComplexUnits[$Unit])) {
                    return $ComplexUnits[$Unit][$Locale];
                }
            }
            if (array_key_exists($Unit, $All['units'])) {
                if (array_key_exists($Locale, $All['units'][$Unit]['name'])) {
                    return $All['units'][$Unit]['name'][$Locale];
                }
            }
            return $Unit;
        }
    }

    /**
     * IdentPrefix
     */
    class IdentPrefix
    {
        public const Property = 'P';
        public const Event = 'E';
        public const Action = 'A';
    }

    /**
     * SpecTypes
     */
    class SpecTypes
    {
        public const Gateway = 'urn:miot-spec-v2:device:gateway';
    }

    /**
     * Sensitive
     *
     * Maskiert Schlüsselmaterial und Zugangsdaten (Token, ssecurity, Passwort-Hash, Codes) für Debug-Ausgaben.
     * Statt des Wertes wird nur seine Länge ausgegeben.
     */
    class Sensitive
    {
        public const Keys = [
            'token',
            'serviceToken',
            'yetAnotherServiceToken',
            'ssecurity',
            'psecurity',
            'passToken',
            'hash',
            'ticket',
            'password',
            // Sitzungsschlüssel der Zwei-Faktor-Anmeldung
            'identity_session',
            'applyToken',
            'identityToken',
            '_authenticationToken',
            '_signature',
            '_sign'
        ];

        /**
         * mask
         *
         * @param  mixed $Data String (JSON, Header, Cookie, Query) oder Array
         * @return mixed Daten mit maskierten Werten
         */
        public static function mask(mixed $Data): mixed
        {
            if (is_array($Data)) {
                foreach ($Data as $Key => $Value) {
                    if (is_string($Key) && in_array(strtolower($Key), array_map('strtolower', self::Keys), true)) {
                        $Data[$Key] = self::hide($Value);
                    } else {
                        $Data[$Key] = self::mask($Value);
                    }
                }
                return $Data;
            }
            if (!is_string($Data)) {
                return $Data;
            }
            $Pattern = '/(\b(?:' . implode('|', self::Keys) . ')\b"?\s*[:=]\s*"?)([^";&,\s}\]]+)/i';
            $Result = preg_replace_callback($Pattern, function (array $Match): string
            {
                return $Match[1] . self::hide($Match[2]);
            }, $Data) ?? $Data;
            // Stream-URLs (z.B. Kamera-Aktionen) enthalten im Pfad einen Sitzungsschlüssel: nur Schema und Host zeigen.
            // Erkennt auch JSON-maskierte Schrägstriche (rtsp:\/\/host\/...).
            $Result = preg_replace_callback('/\b(rtsps?|rtmps?)(:(?:\\\\?\/){2}[^\/\\\\"\s]+)([^"\s,\]]*)/i', function (array $Match): string
            {
                return $Match[1] . $Match[2] . ($Match[3] === '' ? '' : '/' . self::hide($Match[3]));
            }, $Result) ?? $Result;
            return $Result;
        }

        /**
         * preview
         *
         * Zeigt nur den Anfang und die Länge eines sensiblen Wertes (z.B. Token im Konfigurationsformular).
         *
         * @param  string $Value
         * @param  int $Visible Anzahl sichtbarer Zeichen am Anfang
         * @return string
         */
        public static function preview(string $Value, int $Visible = 4): string
        {
            if ($Value === '') {
                return '';
            }
            return substr($Value, 0, $Visible) . '…(' . strlen($Value) . ')';
        }

        /**
         * hide
         *
         * @param  mixed $Value
         * @return string
         */
        private static function hide(mixed $Value): string
        {
            return is_string($Value) ? '***(' . strlen($Value) . ')' : '***';
        }
    }
}

namespace Xiaomi\Device{

    /**
     * Property
     */
    class Property
    {
        public const Active = 'Open';
        public const Host = 'Host';
        public const DeviceId = 'DeviceId';
        public const ForceCloud = 'ForceCloud';
        public const DeniedCloud = 'DeniedCloud';
        public const RefreshInterval = 'RefreshInterval';
    }

    /**
     * Timer
     */
    class Timer
    {
        public const RefreshState = 'RefreshState';
        public const Reconnect = 'Reconnect';
    }

    /**
     * Attribute
     */
    class Attribute
    {
        public const Specs = 'Specs';
        public const ProductName = 'ModelName';
        public const Locales = 'Locales';
        public const useCloud = 'useCloud';
        public const Info = 'Info';
        public const Token = 'Token';
        public const Icon = 'Icon';
        public const ActionIdentsWithValues = 'ActionIdentsWithValues';
        public const ActionIdents = 'ActionIdents';
        public const ParamIdentsRead = 'ParamIdentsRead';
        public const ParamIdentsWrite = 'ParamIdentsWrite';
        public const LockedStateVariables = 'LockedStateVariables';
    }

    /**
     * InstanceStatus
     */
    class InstanceStatus
    {
        public const ConfigError = IS_EBASE + 1;
        public const GetTokenFailed = IS_EBASE + 2;
        public const ApiError = IS_EBASE + 3;
        public const GetSpecsFailed = IS_EBASE + 4;
        public const DidNotMatch = IS_EBASE + 5;
        public const TimeoutError = IS_EBASE + 6;
        public const InCloudOffline = IS_EBASE + 7;
    }

    /**
     * ApiMethod
     */
    class ApiMethod
    {
        public const Info = 'miIO.info';
        public const GetProperties = 'get_properties';
        public const SetProperties = 'set_properties';
        public const ExecuteAction = 'action';
    }

    /**
     * ApiError
     */
    class ApiError
    {
        public const PaketError = 300;
        public const ChecksumError = 301;
        public static $CodeToText = [
            -4001       => 'Unreadable attribute',
            -4002       => 'Attribute is not writable',
            -4003       => 'Properties, methods, events do not exist',
            -4004       => 'Other internal errors',
            -4005       => 'Attribute value error',
            -4006       => 'Method in parameter error',
            -4007       => 'DeviceId error',
            -9999       => 'user ack timeout',
            -704010000  => 'Unknown error',
            -704040002  => 'Service does not exist', // live geprüft (siid 99)
            -704040003  => 'Property does not exist', // live geprüft (piid 99)
            -704040005  => 'Invalid action',
            -704042011  => 'Device offline',
            -704220043  => 'Property value invalid'
        ];
    }

    /**
     * SpecUrls
     */
    class SpecUrls
    {
        public const Device = 'https://home.miot-spec.com/spec/';
        public const AllMioTSpecs = 'https://miot-spec.org/miot-spec-v2/instances?status=all';
        public const MioTDevices = 'https://miot-spec.org/miot-spec-v2/instance?type=';
        public const Locales = 'https://miot-spec.org/instance/v2/multiLanguage?urn=';
    }
}

namespace Xiaomi\Cloud{

    /**
     * Attribute
     */
    class Attribute
    {
        public const Username = 'Username';
        public const Password = 'Password';
        public const Country = 'Country';
        public const AgentID = 'AgentID';
        public const ClientID = 'ClientID';
        public const ClientSecret = 'ClientSecret';
        public const ServiceToken = 'ServiceToken';
        public const SSecurity = 'SSecurity';
        public const UserId = 'UserId';
        public const cUserId = 'cUserId';
        public const Location = 'Location';
        public const Sign = 'Sign';
    }

    /**
     * ApiUrl
     */
    class ApiUrl
    {
        public const GetSign = 'https://account.xiaomi.com/pass/serviceLogin?sid=xiaomiio&_json=true';
        public const Login = 'https://account.xiaomi.com/pass/serviceLoginAuth2';
        public const Domain = 'api.io.mi.com/app';
        public const Device_List = '/v2/home/device_list_page';
        public const GetProperties = '/miotspec/prop/get';
        public const SetProperties = '/miotspec/prop/set';
        public const ExecuteAction = '/miotspec/action';

        /**
         * GetApiUrl
         *
         * @param  string $Country
         * @param  string $Path
         * @return string
         */
        public static function GetApiUrl(string $Country, string $Path): string
        {
            return 'https://' . (($Country === 'cn') ? '' : $Country . '.') . self::Domain . $Path;
        }
    }

    /**
     * ApiError
     */
    class ApiError
    {
        public static $CodeToText = [
            2   => 'Unauthorized',
            -4  => 'Device offline',
            -8  => 'Data type not valid'

        ];
    }

    /**
     * ApiHeader
     */
    class ApiHeader
    {
        public const UserAgent = 'User-Agent: Android-7.1.1-1.0.0-ONEPLUS A3010-136-%sAPP/xiaomi.smarthome APPV/62830';
        public const Encoding = 'Accept-Encoding: identity';
        public const Accept = 'Accept: */*';
        public const Connection = 'Connection: keep-alive';
        public const CLI = 'X-XIAOMI-PROTOCAL-FLAG-CLI: PROTOCAL-HTTP2';
        public const Content = 'Content-Type: application/x-www-form-urlencoded';
        public const Encrypt = 'MIOT-ENCRYPT-ALGORITHM: ENCRYPT-RC4';
        public const Cookie = 'Cookie: ';

        /**
         * getLoginHeader
         *
         * @param  string $AgentID
         * @param  string $Cookie
         * @return array
         */
        public static function getLoginHeader(string $AgentID, string $Cookie): array
        {
            return
            [
                sprintf(self::UserAgent, $AgentID),
                self::Encoding,
                self::Accept,
                self::CLI,
                self::Content,
                self::Cookie . $Cookie
            ];
        }

        /**
         * getApiHeader
         *
         * @param  string $AgentID
         * @param  string $Cookie
         * @return array
         */
        public static function getApiHeader(string $AgentID, string $Cookie): array
        {
            return
            [
                sprintf(self::UserAgent, $AgentID),
                self::Encoding,
                self::Accept,
                self::CLI,
                self::Content,
                self::Encrypt,
                self::Cookie . $Cookie
            ];
        }
    }

    /**
     * ApiData
     */
    class ApiData
    {
        /**
         * getLoginPayload
         *
         * @param  string $Username
         * @param  string $Password
         * @param  string $Sign
         * @return array
         */
        public static function getLoginPayload(string $Username, string $Password, string $Sign): array
        {
            return [
                'hash'    => strtoupper(md5($Password)),
                '_json'   => 'true',
                'sid'     => 'xiaomiio',
                'callback'=> 'https://sts.api.io.mi.com/sts',
                'qs'      => '%3Fsid%3Dxiaomiio%26_json%3Dtrue',
                '_sign'   => $Sign,
                'user'    => $Username
            ];
        }
    }

    /**
     * ApiCookie
     */
    class ApiCookie
    {
        public const SDKVersion = 'sdkVersion=accountsdk-18.8.15';
        public const DeviceId = 'deviceId=';
        public const UserId = 'userId=';
        public const YAST = 'yetAnotherServiceToken=';
        public const ServiceToken = 'serviceToken=';
        public const Locale = 'locale=';
        public const Timezone = 'timezone=GMT';
        public const IsDaylight = 'is_daylight=';
        public const DSTOffset = 'dst_offset=';
        public const Channel = 'channel=MI_APP_STORE';

        /**
         * getLoginCookie
         *
         * @param  string $ClientID
         * @return string
         */
        public static function getLoginCookie(string $ClientID): string
        {
            return implode(
                ';',
                [
                    self::SDKVersion, //3.8.6 ?
                    self::DeviceId . $ClientID
                ]
            );
        }

        /**
         * getApiCookie
         *
         * @param  string $ClientID
         * @param  string $UserId
         * @param  string $ServiceToken
         * @return string
         */
        public static function getApiCookie(string $ClientID, string $UserId, string $ServiceToken): string
        {
            return implode(
                ';',
                [
                    self::SDKVersion, //3.8.6 ?
                    self::DeviceId . $ClientID,
                    self::UserId . $UserId,
                    self::YAST . $ServiceToken,
                    self::ServiceToken . $ServiceToken,
                    self::Locale . IPS_GetSystemLanguage(),
                    self::Timezone . date('P'),
                    self::IsDaylight . date('I'),
                    self::DSTOffset . (string) ((int) date('I') * 60 * 60 * 1000),
                    self::Channel
                ]
            );
        }
    }

    /**
     * ApiVerifyIdentity
     */
    class ApiVerifyIdentity
    {
        public const Phone = 4;
        public const Email = 8;
        public static $TypeToPath =
            [
                self::Phone => 'https://account.xiaomi.com/identity/auth/verifyPhone?',
                self::Email => 'https://account.xiaomi.com/identity/auth/verifyEmail?',
            ];

        /**
         * getUrl
         *
         * @param  int $Type
         * @return string
         */
        public static function getUrl(int $Type): string
        {
            if (!array_key_exists($Type, self::$TypeToPath)) {
                throw new \Exception('Unknown verification type: ' . $Type);
            }
            return self::$TypeToPath[$Type];
        }

        /**
         * getMessageTextAndIndex
         *
         * @param  int $Flag
         * @return array
         */
        public static function getMessageTextAndIndex(int $Flag): array
        {
            switch ($Flag) {
                case self::Email:
                    return [
                        'Send the confirmation code to the email address (%s).',
                        'maskedEmail'
                    ];
                case self::Phone:
                    return [
                        'Send the confirmation code to the phone number (%s).',
                        'maskedPhone'
                    ];
            }
            return [];
        }
    }

    /**
     * ApiCheckIdentity
     */
    class ApiCheckIdentity
    {
        public const Phone = 4;
        public const Email = 8;
        public static $TypeToPath =
            [
                self::Phone => 'https://account.xiaomi.com/identity/auth/sendPhoneTicket?',
                self::Email => 'https://account.xiaomi.com/identity/auth/sendEmailTicket?',
            ];

        /**
         * getUrl
         *
         * @param  int $Type
         * @return string
         */
        public static function getUrl(int $Type): string
        {
            if (!array_key_exists($Type, self::$TypeToPath)) {
                throw new \Exception('Unknown verification type: ' . $Type);
            }
            return self::$TypeToPath[$Type];
        }
    }

    /**
     * ForwardData
     */
    class ForwardData
    {
        public const GUID = 'DataID';
        public const Uri = 'Uri';
        public const Params = 'Params';

        /**
         * ToJson
         *
         * @param  string $Uri
         * @param  string $Params
         * @return string
         */
        public static function ToJson(string $Uri, string $Params = ''): string
        {
            return json_encode(
                [
                    self::GUID   => \Xiaomi\GUID::SendToCloud,
                    self::Uri    => $Uri,
                    self::Params => $Params
                ]
            );
        }

        /**
         * FromJson
         *
         * @param  string $JSONString
         * @return array
         */
        public static function FromJson(string $JSONString): array
        {
            $Data = json_decode($JSONString, true);
            return [$Data[self::Uri], $Data[self::Params]];
        }
    }
}

namespace Xiaomi\Configurator{
    /**
     * Attribute
     */
    class Attribute
    {
        public const ShowOffline = 'ShowOffline';
    }
}
