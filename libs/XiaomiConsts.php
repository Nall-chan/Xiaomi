<?php

declare(strict_types=1);

namespace Xiaomi{
    class GUID
    {
        const MiDevice = '{733AB5D2-957D-E76A-BA5D-5006701A6216}';
        const CloudIO = '{DF2248D9-FC17-4609-840D-BA52DBF9CEB6}';
        const ReceiveFromCloud = '{5F3A76AF-D01E-42A3-93A3-D4E5E9267E32}';
        const SendToCloud = '{76F2BB7B-F2B9-47EA-88F7-FD357D2E49E1}';
    }
    class Convert
    {
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
        public static function getProfileName(string $Urn, string $Name): string
        {
            $Parts = explode(':', substr($Urn, strpos($Urn, ':' . $Name . ':') + strlen($Name) + 2));
            return 'XIAOMI.' . $Name . '.' . $Parts[0] . '.' . $Parts[1];
        }
    }
    class Translate
    {
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
            //echo  $Name;
            //var_dump($Key);
            if (array_key_exists($Name, $Columns)) {
                return $Columns[$Name];
            }
            return $Name;
        }
        public static function getLocaleUnit(string $Unit): string
        {
            $Locale = explode('_', IPS_GetSystemLanguage())[0];
            $All = json_decode(file_get_contents(__DIR__ . '/card_default.json'), true);
            $ComplexUnits = array_column($All['complex_units'], 'name', 'key');
            if (array_key_exists($Unit, $ComplexUnits)) {
                if (array_key_exists($Locale, $ComplexUnits[$Unit])) {
                    return $ComplexUnits[$Unit][$Unit][$Locale];
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
    class IdentPrefix
    {
        const Property = 'P';
        const Event = 'E';
        const Action = 'A';
    }
}

namespace Xiaomi\Device{
    class Property
    {
        const Active = 'Open';
        const Host = 'Host';
        const DeviceId = 'DeviceId';
        const ForceCloud = 'ForceCloud';
        const DeniedCloud = 'DeniedCloud';
    }
    class Attribute
    {
        const Specs = 'Specs';
        const ProductName = 'ModelName';
        const Locales = 'Locales';
        const useCloud = 'useCloud';
        const Info = 'Info';
        const Token = 'Token';
        const Icon = 'Icon';
        const ActionIdentsWithValues = 'ActionIdentsWithValues';
        const ActionIdents = 'ActionIdents';
        const ParamIdentsRead = 'ParamIdentsRead';
        const ParamIdentsWrite = 'ParamIdentsWrite';
    }
    class InstanceStatus
    {
        const ConfigError = IS_EBASE + 1;
        const GetTokenFailed = IS_EBASE + 2;
        const ApiError = IS_EBASE + 3;
        const GetSpecsFailed = IS_EBASE + 4;
        const DidNotMatch = IS_EBASE + 5;
        const TimeoutError = IS_EBASE + 6;
    }
    class ApiMethod
    {
        const Info = 'miIO.info';
        const GetProperties = 'get_properties';
        const SetProperties = 'set_properties';
        const ExecuteAction = 'action';
    }
    class ApiError
    {
        const PaketError = 300;
        const ChecksumError = 301;
        public static $CodeToText = [
            -4001       => 'Unreadable attribute',
            -4002       => 'Attribute is not writable',
            -4003       => 'Properties, methods, events do not exist',
            -4004       => 'Other internal errors',
            -4005       => 'Attribute value error',
            -4006       => 'Method in parameter error',
            -4007       => 'did error',
            -9999       => 'user ack timeout',
            -704040005  => 'invalid action'
        ];
    }
    class SpecUrls
    {
        const Device = 'https://home.miot-spec.com/spec/';
        const Locales = 'https://miot-spec.org/instance/v2/multiLanguage?urn=';
    }
}

namespace Xiaomi\Cloud{

    class Property
    {
        const Username = 'Username';
        const Password = 'Password';
        const Country = 'Country';
    }
    class Attribute
    {
        const AgentID = 'AgentID';
        const ClientID = 'ClientID';
    }
    class ApiUrl
    {
        const GetSign = 'https://account.xiaomi.com/pass/serviceLogin?sid=xiaomiio&_json=true';
        const Login = 'https://account.xiaomi.com/pass/serviceLoginAuth2';
        const Domain = 'api.io.mi.com/app';
        const Device_List = '/v2/home/device_list_page';
        const GetProperties = '/miotspec/prop/get';
        const SetProperties = '/miotspec/prop/set';
        const ExecuteAction = '/miotspec/action';

        public static function GetApiUrl(string $Country, string $Path): string
        {
            return 'https://' . (($Country === 'cn') ? '' : $Country . '.') . self::Domain . $Path;
        }
    }
    class ApiError
    {
        public static $CodeToText = [
            -4  => 'Device offline',
            -8  => 'data type not valid'

        ];
    }
    class ApiHeader
    {
        const UserAgent = 'User-Agent: Android-7.1.1-1.0.0-ONEPLUS A3010-136-%sAPP/xiaomi.smarthome APPV/62830';
        const Encoding = 'Accept-Encoding: identity';
        const Accept = 'Accept: */*';
        const Connection = 'Connection: keep-alive';
        const CLI = 'x-xiaomi-protocal-flag-cli: PROTOCAL-HTTP2';
        const ClientId = 'mishop-client-id: 180100041079';
        const Content = 'Content-Type: application/x-www-form-urlencoded';
        const Encrypt = 'MIOT-ENCRYPT-ALGORITHM: ENCRYPT-RC4';
        const Cookie = 'Cookie: ';
        public static function getLoginHeader(string $AgentID, string $Cookie): array
        {
            return
            [
                sprintf(self::UserAgent, $AgentID),
                self::Encoding,
                self::Accept,
                self::ClientId,
                self::Content,
                self::Cookie . $Cookie
            ];
        }
        public static function getApiHeader(string $AgentID, string $Cookie): array
        {
            return
            [
                sprintf(self::UserAgent, $AgentID),
                self::Encoding,
                self::Accept,
                //self::Connection,
                //self::CLI,
                self::ClientId,
                self::Content,
                self::Encrypt,
                self::Cookie . $Cookie
            ];
        }
    }
    class ApiData
    {
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
    class ApiCookie
    {
        const SDKVersion = 'sdkVersion=accountsdk-18.8.15';
        const DeviceId = 'deviceId=';
        const UserId = 'userId=';
        const YAST = 'yetAnotherServiceToken=';
        const ServiceToken = 'serviceToken=';
        const Locale = 'locale=';
        const Timezone = 'timezone=GMT';
        const IsDaylight = 'is_daylight=';
        const DSTOffset = 'dst_offset=';
        const Channel = 'channel=MI_APP_STORE';

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
                    self::Locale . IPS_GetSystemLanguage() .
                    self::Timezone . date('P'),
                    self::IsDaylight . date('I'),
                    self::DSTOffset . (string) ((int) date('I') * 60 * 60 * 1000),
                    self::Channel
                ]
            );
        }
    }
    class ForwardData
    {
        const GUID = 'DataID';
        const Uri = 'Uri';
        const Params = 'Params';

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
        public static function FromJson(string $JSONString): array
        {
            $Data = json_decode($JSONString, true);
            return [$Data[self::Uri], $Data[self::Params]];
        }
    }
}
