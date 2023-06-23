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
}

namespace Xiaomi\Device{
    class Property
    {
        const Host = 'Host';
        const DeviceId = 'DeviceId';
        const Token = 'Token';
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
        public static function GetApiUrl(string $Country, string $Path): string
        {
            return 'https://' . (($Country === 'cn') ? '' : $Country . '.') . self::Domain . $Path;
        }
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

        public static function ToJson(string $Uri, array $Params = []): string
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
            unset($Data[self::GUID]);
            return $Data;
        }
    }
}
