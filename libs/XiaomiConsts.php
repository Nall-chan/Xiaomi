<?php

declare(strict_types=1);

namespace Xiaomi{
    class GUID
    {
        public const MiDevice = '{733AB5D2-957D-E76A-BA5D-5006701A6216}';
        public const CloudIO = '{DF2248D9-FC17-4609-840D-BA52DBF9CEB6}';
        public const ReceiveFromCloud = '{5F3A76AF-D01E-42A3-93A3-D4E5E9267E32}';
        public const SendToCloud = '{76F2BB7B-F2B9-47EA-88F7-FD357D2E49E1}';
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
        public const Property = 'P';
        public const Event = 'E';
        public const Action = 'A';
    }
    class SpecTypes
    {
        public const Gateway = 'urn:miot-spec-v2:device:gateway';
        public const Roborocks = [
            'urn:miot-spec-v2:device:vacuum:0000A006:roborock',
            'urn:miot-spec-v2:device:vacuum:0000A006:rockrobo'
        ];
    }
}

namespace Xiaomi\Device{
    class Property
    {
        public const Active = 'Open';
        public const Host = 'Host';
        public const DeviceId = 'DeviceId';
        public const ForceCloud = 'ForceCloud';
        public const DeniedCloud = 'DeniedCloud';
        public const RefreshInterval = 'RefreshInterval';
    }
    class Timer
    {
        public const RefreshState = 'RefreshState';
        public const Reconnect = 'Reconnect';
    }
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
    }
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
    class ApiMethod
    {
        public const Info = 'miIO.info';
        public const GetProperties = 'get_properties';
        public const SetProperties = 'set_properties';
        public const ExecuteAction = 'action';
    }
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
            -704040002  => 'Unknown error', //Gateway offline?
            -704040003  => 'Unknown error',
            -704040005  => 'Invalid action',
            -704042011  => 'Device offline'
        ];
    }
    class SpecUrls
    {
        public const Device = 'https://home.miot-spec.com/spec/';
        public const Locales = 'https://miot-spec.org/instance/v2/multiLanguage?urn=';
    }
}

namespace Xiaomi\Cloud{
    class Property
    {
        public const Username = 'Username';
        public const Password = 'Password';
        public const Country = 'Country';
    }
    class Attribute
    {
        public const AgentID = 'AgentID';
        public const ClientID = 'ClientID';
    }
    class ApiUrl
    {
        public const GetSign = 'https://account.xiaomi.com/pass/serviceLogin?sid=xiaomiio&_json=true';
        public const Login = 'https://account.xiaomi.com/pass/serviceLoginAuth2';
        public const Domain = 'api.io.mi.com/app';
        public const Device_List = '/v2/home/device_list_page';
        public const GetProperties = '/miotspec/prop/get';
        public const SetProperties = '/miotspec/prop/set';
        public const ExecuteAction = '/miotspec/action';

        public static function GetApiUrl(string $Country, string $Path): string
        {
            return 'https://' . (($Country === 'cn') ? '' : $Country . '.') . self::Domain . $Path;
        }
    }
    class ApiError
    {
        public static $CodeToText = [
            -4  => 'Device offline',
            -8  => 'Data type not valid'

        ];
    }
    class ApiHeader
    {
        public const UserAgent = 'User-Agent: Android-7.1.1-1.0.0-ONEPLUS A3010-136-%sAPP/xiaomi.smarthome APPV/62830';
        public const Encoding = 'Accept-Encoding: identity';
        public const Accept = 'Accept: */*';
        public const Connection = 'Connection: keep-alive';
        public const CLI = 'x-xiaomi-protocal-flag-cli: PROTOCAL-HTTP2';
        public const ClientId = 'mishop-client-id: 180100041079';
        public const Content = 'Content-Type: application/x-www-form-urlencoded';
        public const Encrypt = 'MIOT-ENCRYPT-ALGORITHM: ENCRYPT-RC4';
        public const Cookie = 'Cookie: ';
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
        public const GUID = 'DataID';
        public const Uri = 'Uri';
        public const Params = 'Params';

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

namespace Xiaomi\Configurator{
    class Attribute
    {
        public const ShowOffline = 'ShowOffline';
    }
}

namespace Xiaomi\Roborock{
    class GUID
    {
        public const Module = '{CD3419DA-91F2-C5DA-7FEE-6EB452506C9F}';
        public const Store = '{F45B5D1F-56AE-4C61-9AB2-C87C63149EC3}';
        public const Device = '{E65614FB-B37A-219A-4876-E5676C948C33}';
        public const IO = '{4743ED9C-720B-D5EA-9B0C-0585803284F3}';
    }
    class Property
    {
        public const Ip = 'ip';
        public const Model = 'model';
        public const Server = 'Server';
        public const User = 'xiaomi_user';
        public const Password = 'xiaomi_password';
    }
    class Store
    {
        public const BundleId = 'fonzo.ipsymconroborock';
        public static $Opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Awesome-PHP\r\n"
            ]
        ];
    }
    class Models
    {
        public const RoborockVacuum = 'roborock.vacuum';
        public const DEVICELIST = [
            self::RoborockVacuum,
            self::RoborockVacuum . '.m1s',
            'rockrobo.vacuum.v1',
            self::RoborockVacuum . '.s5',
            self::RoborockVacuum . '.s5e',
            self::RoborockVacuum . '.s6',
            self::RoborockVacuum . '.a10',
            self::RoborockVacuum . '.a15',
            self::RoborockVacuum . '.a27',
            self::RoborockVacuum . '.a51'
        ];
    }
}
