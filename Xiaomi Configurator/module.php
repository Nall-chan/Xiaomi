<?php

declare(strict_types=1);

eval('declare(strict_types=1);namespace XiaomiConfigurator {?>' . file_get_contents(__DIR__ . '/../libs/helper/DebugHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiConfigurator {?>' . file_get_contents(__DIR__ . '/../libs/helper/BufferHelper.php') . '}');
require_once(dirname(__DIR__).'/libs/XiaomiConsts.php';
/**
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 * @property string $SSecurity
 * @property string $UserId
 * @property string $Location
 * @property string $ServiceToken
 */
class XiaomiConfigurator extends IPSModule
{
    use \XiaomiConfigurator\DebugHelper;
    use \XiaomiConfigurator\BufferHelper;

    const Attribute_Username = 'Username';
    const Attribute_Password = 'Password';
    const Attribute_Country = 'Country';
    const Attribute_AgentID = 'AgentID';
    const Attribute_ClientID = 'ClientID';
    const API_Device_List = '/v2/home/device_list_page';
    const Module_MiDevice = '{733AB5D2-957D-E76A-BA5D-5006701A6216}';
    const Module_UDPSocket = '{82347F20-F541-41E1-AC5B-A636FD3AE2D8}';
    const Property_Host = 'Host';
    const Property_DeviceId = 'DeviceId';
    const Property_Token = 'Token';

    private static $CURL_error_codes = [
        0  => 'UNKNOWN ERROR',
        1  => 'CURLE_UNSUPPORTED_PROTOCOL',
        2  => 'CURLE_FAILED_INIT',
        3  => 'CURLE_URL_MALFORMAT',
        4  => 'CURLE_URL_MALFORMAT_USER',
        5  => 'CURLE_COULDNT_RESOLVE_PROXY',
        6  => 'CURLE_COULDNT_RESOLVE_HOST',
        7  => 'CURLE_COULDNT_CONNECT',
        8  => 'CURLE_FTP_WEIRD_SERVER_REPLY',
        9  => 'CURLE_REMOTE_ACCESS_DENIED',
        11 => 'CURLE_FTP_WEIRD_PASS_REPLY',
        13 => 'CURLE_FTP_WEIRD_PASV_REPLY',
        14 => 'CURLE_FTP_WEIRD_227_FORMAT',
        15 => 'CURLE_FTP_CANT_GET_HOST',
        17 => 'CURLE_FTP_COULDNT_SET_TYPE',
        18 => 'CURLE_PARTIAL_FILE',
        19 => 'CURLE_FTP_COULDNT_RETR_FILE',
        21 => 'CURLE_QUOTE_ERROR',
        22 => 'CURLE_HTTP_RETURNED_ERROR',
        23 => 'CURLE_WRITE_ERROR',
        25 => 'CURLE_UPLOAD_FAILED',
        26 => 'CURLE_READ_ERROR',
        27 => 'CURLE_OUT_OF_MEMORY',
        28 => 'CURLE_OPERATION_TIMEDOUT',
        30 => 'CURLE_FTP_PORT_FAILED',
        31 => 'CURLE_FTP_COULDNT_USE_REST',
        33 => 'CURLE_RANGE_ERROR',
        34 => 'CURLE_HTTP_POST_ERROR',
        35 => 'CURLE_SSL_CONNECT_ERROR',
        36 => 'CURLE_BAD_DOWNLOAD_RESUME',
        37 => 'CURLE_FILE_COULDNT_READ_FILE',
        38 => 'CURLE_LDAP_CANNOT_BIND',
        39 => 'CURLE_LDAP_SEARCH_FAILED',
        41 => 'CURLE_FUNCTION_NOT_FOUND',
        42 => 'CURLE_ABORTED_BY_CALLBACK',
        43 => 'CURLE_BAD_FUNCTION_ARGUMENT',
        45 => 'CURLE_INTERFACE_FAILED',
        47 => 'CURLE_TOO_MANY_REDIRECTS',
        48 => 'CURLE_UNKNOWN_TELNET_OPTION',
        49 => 'CURLE_TELNET_OPTION_SYNTAX',
        51 => 'CURLE_PEER_FAILED_VERIFICATION',
        52 => 'CURLE_GOT_NOTHING',
        53 => 'CURLE_SSL_ENGINE_NOTFOUND',
        54 => 'CURLE_SSL_ENGINE_SETFAILED',
        55 => 'CURLE_SEND_ERROR',
        56 => 'CURLE_RECV_ERROR',
        58 => 'CURLE_SSL_CERTPROBLEM',
        59 => 'CURLE_SSL_CIPHER',
        60 => 'CURLE_SSL_CACERT',
        61 => 'CURLE_BAD_CONTENT_ENCODING',
        62 => 'CURLE_LDAP_INVALID_URL',
        63 => 'CURLE_FILESIZE_EXCEEDED',
        64 => 'CURLE_USE_SSL_FAILED',
        65 => 'CURLE_SEND_FAIL_REWIND',
        66 => 'CURLE_SSL_ENGINE_INITFAILED',
        67 => 'CURLE_LOGIN_DENIED',
        68 => 'CURLE_TFTP_NOTFOUND',
        69 => 'CURLE_TFTP_PERM',
        70 => 'CURLE_REMOTE_DISK_FULL',
        71 => 'CURLE_TFTP_ILLEGAL',
        72 => 'CURLE_TFTP_UNKNOWNID',
        73 => 'CURLE_REMOTE_FILE_EXISTS',
        74 => 'CURLE_TFTP_NOSUCHUSER',
        75 => 'CURLE_CONV_FAILED',
        76 => 'CURLE_CONV_REQD',
        77 => 'CURLE_SSL_CACERT_BADFILE',
        78 => 'CURLE_REMOTE_FILE_NOT_FOUND',
        79 => 'CURLE_SSH',
        80 => 'CURLE_SSL_SHUTDOWN_FAILED',
        81 => 'CURLE_AGAIN',
        82 => 'CURLE_SSL_CRL_BADFILE',
        83 => 'CURLE_SSL_ISSUER_ERROR',
        84 => 'CURLE_FTP_PRET_FAILED',
        84 => 'CURLE_FTP_PRET_FAILED',
        85 => 'CURLE_RTSP_CSEQ_ERROR',
        86 => 'CURLE_RTSP_SESSION_ERROR',
        87 => 'CURLE_FTP_BAD_FILE_LIST',
        88 => 'CURLE_CHUNK_FAILED'
    ];

    private static $http_error =
        [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Server error'
        ];

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterAttributeString(self::Attribute_Username, '');
        $this->RegisterAttributeString(self::Attribute_Password, '');
        $this->RegisterAttributeString(self::Attribute_Country, 'de');
        $this->RegisterAttributeString(self::Attribute_AgentID, $this->generateRandomString('ABCDEF', 13));
        $this->RegisterAttributeString(self::Attribute_ClientID, $this->generateRandomString('ABCDEFGHIJKLMNOPQRSTUVWXYZ', 6));
        $this->SSecurity = '';
        $this->UserId = '';
        $this->Location = '';
        $this->ServiceToken = '';
    }
    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();
        $this->UpdateServiceToken();
    }
    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'Save') {
            $Data = explode(':', $Value);
            $this->WriteAttributeString(self::Attribute_Username, urldecode($Data[0]));
            $this->WriteAttributeString(self::Attribute_Password, urldecode($Data[1]));
            $this->WriteAttributeString(self::Attribute_Country, urldecode($Data[2]));
            if ($this->UpdateServiceToken()) {
                $this->ReloadForm();
            }
            return;
        }
    }
    public function GetConfigurationForm()
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if ($this->GetStatus() == IS_CREATING) {
            return json_encode($Form);
        }
        $Form['actions'][0]['items'][0]['items'][0]['value'] = $this->ReadAttributeString(self::Attribute_Username);
        $Form['actions'][0]['items'][0]['items'][1]['value'] = $this->ReadAttributeString(self::Attribute_Password);
        $Form['actions'][0]['items'][0]['items'][2]['value'] = $this->ReadAttributeString(self::Attribute_Country);
        if ($this->isLoggedIn()) {
            $Devices = $this->GetDevices();
        }
        $DeviceValues = [];
        $InstanceIDListe = $this->GetInstanceList(self::Module_MiDevice, 'Host');
        foreach ($Devices as $Device) {
            if (!array_key_exists('localip', $Device)) {
                continue;
            }
            if ($Device['localip'] == '') {
                continue;
            }
            $AddDevice = [
                'IPAddress'              => $Device['localip'],
                'MAC'                    => $Device['mac'],
                'Model'                  => $Device['model'],
                'name'                   => $Device['name']
            ];
            $InstanceIdDevice = array_search($Device['localip'], $InstanceIDListe);
            if ($InstanceIdDevice !== false) {
                $AddDevice['name'] = IPS_GetName($InstanceIdDevice);
                $AddDevice['instanceID'] = $InstanceIdDevice;
                $AddDevice['host'] = $Device['localip'];
                unset($InstanceIDListe[$InstanceIdDevice]);
            }

            $AddDevice['create'] = [

                'moduleID'      => self::Module_MiDevice,
                'location'      => [$this->Translate('Mi Home Devices')],
                'configuration' => [
                    self::Property_Host     => $Device['localip'],
                    self::Property_DeviceId => $Device['did'],
                    self::Property_Token    => $Device['token'],
                ]

            ];

            $DeviceValues[] = $AddDevice;
        }
        foreach ($InstanceIDListe as $InstanceIdDevice => $IPAddress) {
            $AddDevice = [
                'instanceID'             => $InstanceIdDevice,
                'IPAddress'              => $IPAddress,
                'MAC'                    => '',
                'Model'                  => '',
                'name'                   => IPS_GetName($InstanceIdDevice)
            ];
            $DeviceValues[] = $AddDevice;
        }
        $Form['actions'][1]['values'] = $DeviceValues;
        $this->SendDebug('FORM', json_encode($Form), 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);
        return json_encode($Form);
    }
    public function GetDevices(): array
    {
        $this->SendDebug(__FUNCTION__, self::API_Device_List, 0);
        $Request['data'] = json_encode([
            'getVirtualModel'   => true,
            'getHuamiDevices'   => 1,
            'get_split_device'  => true,
            'support_smart_home'=> true,
            'get_cariot_device' => true
        ]);
        $Result = $this->Request(self::API_Device_List, $Request);
        $this->SendDebug(__FUNCTION__, $Result, 0);
        if ($Result) {
            return json_decode($Result, true)['result']['list'];
        }
        return [];
    }
    public function Request(string $Path, array $Params): ?string
    {
        $Url = $this->GetApiUrl() . $Path;
        $Nonce = $this->GenerateNonce();
        $SignedNonce = $this->SignedNonce($Nonce);
        $this->SendDebug('Cloud Request Url', $Url, 0);
        $this->SendDebug('Cloud Request Data', $Params, 0);
        $Params['rc4_hash__'] = $this->GenerateSignature($Path, $SignedNonce, $Params);
        foreach ($Params as $Key => &$Param) {
            $Param = base64_encode(substr($this->rc4(base64_decode($SignedNonce), str_repeat("\x0", 1024) . $Param), 1024));
        }
        $Params['signature'] = $this->GenerateSignature($Path, $SignedNonce, $Params);
        $Params['_nonce'] = $Nonce;
        $Params['ssecurity'] = $this->SSecurity;

        $Cookie = implode(';', [
            'sdkVersion=accountsdk-18.8.15', //3.8.6 ?
            'deviceId=' . $this->ReadAttributeString(self::Attribute_ClientID),
            'userId=' . $this->UserId,
            'yetAnotherServiceToken=' . $this->ServiceToken,
            'serviceToken=' . $this->ServiceToken,
            'locale=' . IPS_GetSystemLanguage() .
            'timezone=GMT' . date('P'),
            'is_daylight=' . date('I'),
            'dst_offset=' . (string) ((int) date('I') * 60 * 60 * 1000),
            'channel=MI_APP_STORE'
        ]);

        $Headers = [
            'User-Agent: Android-7.1.1-1.0.0-ONEPLUS A3010-136-' .
            $this->ReadAttributeString(self::Attribute_AgentID) .
            'APP/xiaomi.smarthome APPV/62830',
            'Accept-Encoding: identity',
            'Accept: */*',
            'Connection: keep-alive',
            'x-xiaomi-protocal-flag-cli: PROTOCAL-HTTP2',
            'mishop-client-id: 180100041079',
            'Content-Type: application/x-www-form-urlencoded',
            'MIOT-ENCRYPT-ALGORITHM: ENCRYPT-RC4',
            'Cookie: ' . $Cookie
        ];

        $ch = curl_init($Url);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADEROPT, CURLHEADER_UNIFIED);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $Headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($Params));
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
        $response = curl_exec($ch);
        $HttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_errno = curl_errno($ch);
        curl_close($ch);
        $Header = '';
        $Result = null;
        if (!is_bool($response)) {
            $Parts = explode("\r\n\r\n", $response);
            $Header = array_shift($Parts);
            $Result = implode("\r\n\r\n", $Parts);
        }
        $this->SendDebug('Cloud Response Header (' . $HttpCode . ')', $Header, 0);
        $this->SendDebug('Cloud Response Body (' . $HttpCode . ')', $Result, 0);
        switch ($HttpCode) {
            case 0:
                $this->SendDebug('CURL ERROR', self::$CURL_error_codes[$curl_errno], 0);
                return null;
            case 400:
            case 401:
            case 403:
            case 404:
            case 405:
            case 500:
                $this->SendDebug(self::$http_error[$HttpCode], $HttpCode, 0);
                return null;
        }
        if ((strpos($Result, 'message') !== false) || (strpos($Result, 'error') !== false) || (strpos($Result, 'code') !== false)) {
            return $Result;
        }
        $Result = substr($this->rc4(base64_decode($SignedNonce), str_repeat("\x0", 1024) . base64_decode($Result)), 1024);
        $this->SendDebug('Cloud Response', $Result, 0);
        return $Result;
    }
    protected function GetConfigParam(&$item1, int $InstanceID, string $ConfigParam): void
    {
        $item1 = IPS_GetProperty($InstanceID, $ConfigParam);
    }
    private function isLoggedIn()
    {
        return $this->ServiceToken !== '';
    }

    private function GetInstanceList(string $GUID, string $ConfigParam): array
    {
        $InstanceIDList = IPS_GetInstanceListByModuleID($GUID);
        $InstanceIDList = array_flip(array_values($InstanceIDList));
        array_walk($InstanceIDList, [$this, 'GetConfigParam'], $ConfigParam);
        $this->SendDebug('Filter', $InstanceIDList, 0);
        return $InstanceIDList;
    }

    private function UpdateServiceToken(): bool
    {
        if ($this->ReadAttributeString(self::Attribute_Username) !== '') {
            $this->SetStatus(IS_INACTIVE);
            return false;
        }
        $Sign = $this->getLoginSign();
        if (is_null($Sign)) {
            $this->SendDebug('ERROR Cloud', 'could not connect', 0);
            $this->SetStatus(IS_EBASE + 1);
            return false;
        }
        if (!$this->Login($Sign)) {
            $this->SendDebug('ERROR Cloud', 'could not login', 0);
            $this->SetStatus(IS_EBASE + 1);
            return false;
        }
        $this->ServiceToken = $this->GetServiceToken((strpos($Sign, 'http') !== 0) ? $this->Location : $Sign);
        if ($this->ServiceToken === '') {
            $this->SendDebug('ERROR Cloud', 'could not fetch token', 0);
            $this->SetStatus(IS_EBASE + 1);
            return false;
        }
        $this->SetStatus(IS_ACTIVE);
        return true;
    }

    private function parseCookies(string $Data): string
    {
        $Lines = explode("\r\n", $Data);
        array_shift($Lines);
        array_pop($Lines);

        foreach ($Lines as $Line) {
            $line_array = explode(':', $Line);
            $Field = strtolower(trim(array_shift($line_array)));
            if ($Field != 'set-cookie') {
                continue;
            }
            $CookieString = explode(';', trim(implode(':', $line_array)))[0];
            [$Cookie, $Value] = explode('=', $CookieString, 2);
            if ($Cookie !== 'serviceToken') {
                continue;
            }
            return $Value;
        }
        return '';
    }

    private function GetServiceToken(string $Url): string
    {
        $Headers = [
            'User-Agent: Android-7.1.1-1.0.0-ONEPLUS A3010-136-' .
            $this->ReadAttributeString(self::Attribute_AgentID) .
            'APP/xiaomi.smarthome APPV/62830'
        ];
        $this->SendDebug('Cloud Request', $Url, 0);
        $ch = curl_init($Url);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADEROPT, CURLHEADER_UNIFIED);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $Headers);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
        $response = curl_exec($ch);
        $HttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $HeaderOut = curl_getinfo($ch, CURLINFO_HEADER_OUT);
        curl_close($ch);
        $this->SendDebug('Cloud Response (' . $HttpCode . ')', $response, 0);
        if ($HttpCode !== 200) {
            return '';
        }
        $Header = '';
        $Result = '';
        if (!is_bool($response)) {
            $Parts = explode("\r\n\r\n", $response);
            $Header = array_shift($Parts);
            $Result = implode("\r\n\r\n", $Parts);
        }
        $this->SendDebug('Cloud Body (' . $HttpCode . ')', $Result, 0);
        return $this->parseCookies($Header);
    }

    private function Login(string $Sign): bool
    {
        $Data = [
            'hash'    => strtoupper(md5($this->ReadAttributeString(self::Attribute_Password))),
            '_json'   => 'true',
            'sid'     => 'xiaomiio',
            'callback'=> 'https://sts.api.io.mi.com/sts',
            'qs'      => '%3Fsid%3Dxiaomiio%26_json%3Dtrue',
            '_sign'   => $Sign,
            'user'    => $this->ReadAttributeString(self::Attribute_Username)
        ];
        $Cookie = 'sdkVersion=accountsdk-18.8.15; deviceId=' . $this->ReadAttributeString(self::Attribute_ClientID);
        $Headers = [
            'User-Agent: Android-7.1.1-1.0.0-ONEPLUS A3010-136-' .
            $this->ReadAttributeString(self::Attribute_AgentID) .
            'APP/xiaomi.smarthome APPV/62830',
            'Content-Type: application/x-www-form-urlencoded',
            'Cookie: ' . $Cookie
        ];

        $Url = 'https://account.xiaomi.com/pass/serviceLoginAuth2';
        $this->SendDebug('Cloud Request', $Url, 0);
        $ch = curl_init($Url);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADEROPT, CURLHEADER_UNIFIED);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $Headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($Data));
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
        $response = curl_exec($ch);
        $HttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $HeaderOut = curl_getinfo($ch, CURLINFO_HEADER_OUT);
        curl_close($ch);
        $this->SendDebug('Cloud Response (' . $HttpCode . ')', $response, 0);
        if ($HttpCode !== 200) {
            return false;
        }
        $Header = '';
        $Result = '';
        if (!is_bool($response)) {
            $Parts = explode("\r\n\r\n", $response);
            $Header = array_shift($Parts);
            $Result = implode("\r\n\r\n", $Parts);
        }
        $this->SendDebug('Cloud Body (' . $HttpCode . ')', $Result, 0);
        $Json = $this->parseJson($Result);
        $Data = array_intersect_key($Json, array_flip(['ssecurity', 'userId', 'location']));
        if (count($Data) !== 3) {
            return false;
        }
        $this->SSecurity = $Data['ssecurity'];
        $this->UserId = $Data['userId'];
        $this->Location = $Data['location'];
        return true;
    }

    private function generateRandomString(string $x, int $length = 10): string
    {
        return substr(str_shuffle(str_repeat($x, (int) ceil($length / strlen($x)))), 1, $length);
    }

    private function getLoginSign(): ?string
    {
        $Data = Sys_GetURLContent('https://account.xiaomi.com/pass/serviceLogin?sid=xiaomiio&_json=true');
        if ($Data) {
            return $this->parseJson($Data)['_sign'];
        }
        return null;
    }

    private function parseJson(string $Data): array
    {
        if (strpos($Data, '&&&START&&&') === 0) {
            $Data = substr($Data, 11);
        }
        return json_decode($Data, true);
    }

    private function GetApiUrl(): string
    {
        $country = $this->ReadAttributeString(self::Attribute_Country);
        return 'https://' . (($country === 'cn') ? '' : $country . '.') . 'api.io.mi.com/app';
    }

    private function GenerateNonce(): string
    {
        $buf = random_bytes(8);
        $timestamp = floor(time() / 60);
        $timestampBytes = pack('N', $timestamp);
        $buf .= $timestampBytes;
        $base64 = base64_encode($buf);
        return $base64;
    }

    private function SignedNonce(string $nonce)
    {
        $s = base64_decode($this->SSecurity);
        $n = base64_decode($nonce);
        $hash = hash('sha256', $s . $n, true);
        return base64_encode($hash);
    }

    private function GenerateSignature(string $Path, string $SignedNonce, array $Params)
    {
        $exps = [
            'POST',
            $Path,
        ];
        ksort($Params);
        foreach ($Params as $Key => $Value) {
            $exps[] = $Key . '=' . $Value;
        }
        $exps[] = $SignedNonce;
        return base64_encode(sha1(implode('&', $exps), true));
    }
    private function rc4(string $pwd, string $data): string
    {
        $key[] = '';
        $box[] = '';
        $cipher = '';

        $pwd_length = strlen($pwd);
        $data_length = strlen($data);

        for ($i = 0; $i < 256; $i++) {
            $key[$i] = ord($pwd[$i % $pwd_length]);
            $box[$i] = $i;
        }

        for ($j = $i = 0; $i < 256; $i++) {
            $j = ($j + $box[$i] + $key[$i]) % 256;
            $tmp = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }

        for ($a = $j = $i = 0; $i < $data_length; $i++) {
            $a = ($a + 1) % 256;
            $j = ($j + $box[$a]) % 256;

            $tmp = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;

            $k = $box[(($box[$a] + $box[$j]) % 256)];

            $cipher .= chr(ord($data[$i]) ^ $k);
        }
        return $cipher;
    }
}
