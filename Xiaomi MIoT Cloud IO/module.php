<?php

declare(strict_types=1);

eval('declare(strict_types=1);namespace XiaomiCloudIO {?>' . file_get_contents(__DIR__ . '/../libs/helper/DebugHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiCloudIO {?>' . file_get_contents(__DIR__ . '/../libs/helper/BufferHelper.php') . '}');
require_once dirname(__DIR__) . '/libs/XiaomiConsts.php';
/**
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 * @property string $SSecurity
 * @property string $UserId
 * @property string $Location
 * @property string $ServiceToken
 * @property string $VerifyUrl
 */
class XiaomiMIoTCloudIO extends IPSModule
{
    use \XiaomiCloudIO\DebugHelper;
    use \XiaomiCloudIO\BufferHelper;

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

        $this->RegisterPropertyString(\Xiaomi\Cloud\Property::Username, '');
        $this->RegisterPropertyString(\Xiaomi\Cloud\Property::Password, '');
        $this->RegisterPropertyString(\Xiaomi\Cloud\Property::Country, 'de');
        $this->RegisterAttributeString(\Xiaomi\Cloud\Attribute::AgentID, self::generateRandomString('ABCDEF', 13));
        $this->RegisterAttributeString(\Xiaomi\Cloud\Attribute::ClientID, self::generateRandomString('ABCDEFGHIJKLMNOPQRSTUVWXYZ', 6));
        $this->SSecurity = '';
        $this->UserId = '';
        $this->Location = '';
        $this->ServiceToken = '';
        $this->VerifyUrl = '';
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
        $this->SetSummary($this->ReadPropertyString(\Xiaomi\Cloud\Property::Username));
        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        $this->SSecurity = '';
        $this->UserId = '';
        $this->Location = '';
        $this->ServiceToken = '';
        $this->VerifyUrl = '';
        IPS_RunScriptText('IPS_Sleep(250);IPS_RequestAction(' . $this->InstanceID . ',"UpdateServiceToken","");');
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;
        }
    }

    public function ForwardData($JSONString)
    {
        list($Uri, $Params) = \Xiaomi\Cloud\ForwardData::FromJson($JSONString);
        $Result = $this->Request($Uri, $Params);
        return is_null($Result) ? '' : $Result;
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'UpdateServiceToken':
                $this->UpdateServiceToken();
                break;
        }
    }

    public function SubmitVerificationCode(string $Code): string
    {
        return $this->VerifyDevice($Code);
    }

    private function KernelReady()
    {
        $this->UnregisterMessage(0, IPS_KERNELSTARTED);
        $this->UpdateServiceToken();
    }

    private function UpdateServiceToken(): bool
    {
        $this->VerifyUrl = '';
        if ($this->ReadPropertyString(\Xiaomi\Cloud\Property::Username) == '') {
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
            if ($this->VerifyUrl !== '') {
                $this->SetStatus(IS_INACTIVE);
            } else {
                $this->SendDebug('ERROR Cloud', 'invalid login', 0);
                $this->SetStatus(IS_EBASE + 1);
            }
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

    private function getLoginSign(): ?string
    {
        $this->SendDebug(__FUNCTION__, '', 0);
        $Data = Sys_GetURLContent(\Xiaomi\Cloud\ApiUrl::GetSign);
        if ($Data) {
            $this->SendDebug(__FUNCTION__, $Data, 0);
            return self::parseJson($Data)['_sign'];
        }
        return null;
    }

    private function Login(string $Sign): bool
    {
        $this->SendDebug(__FUNCTION__, $Sign, 0);
        $Payload = \Xiaomi\Cloud\ApiData::getLoginPayload(
            $this->ReadPropertyString(\Xiaomi\Cloud\Property::Username),
            $this->ReadPropertyString(\Xiaomi\Cloud\Property::Password),
            $Sign
        );
        $Cookie = \Xiaomi\Cloud\ApiCookie::getLoginCookie($this->ReadAttributeString(\Xiaomi\Cloud\Attribute::ClientID));
        $Headers = \Xiaomi\Cloud\ApiHeader::getLoginHeader(
            $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::AgentID),
            $Cookie
        );
        $Result = $this->CurlCall($Response, \Xiaomi\Cloud\ApiUrl::Login, $Headers, $Payload);
        if ($Result === null) {
            return false;
        }
        $Json = self::parseJson($Result);
        if ($Json === null) {
            return false;
        }
        if ($Json['securityStatus'] == 16) {
            $this->SendDebug('Cloud Login', 'Additional verification required', 0);
            $this->VerifyUrl = $Json['notificationUrl'];
            $this->UpdateFormField('VerifyUrl', 'onClick', 'echo "' . $Json['notificationUrl'] . '";');
            $this->UpdateFormField('VerifyPopup', 'visible', true);
            return false;
        }
        $Data = array_intersect_key($Json, array_flip(['ssecurity', 'userId', 'location']));
        if (count($Data) !== 3) {
            return false;
        }
        $this->SSecurity = $Data['ssecurity'];
        $this->UserId = (string) $Data['userId'];
        $this->Location = $Data['location'];
        return true;
    }

    private function VerifyDevice(string $Code): string
    {
        $this->SendDebug(__FUNCTION__, $Code, 0);
        $this->SendDebug('Cloud Login', 'Device verification process initiated', 0);
        $IdentityUrl = str_replace('fe/service/identity/authStart', 'identity/list', $this->VerifyUrl);
        $Cookie = \Xiaomi\Cloud\ApiCookie::getLoginCookie($this->ReadAttributeString(\Xiaomi\Cloud\Attribute::ClientID));
        $Headers = \Xiaomi\Cloud\ApiHeader::getLoginHeader(
            $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::AgentID),
            $Cookie
        );
        $ResponseHeader = '';
        $Result = $this->CurlCall($ResponseHeader, $IdentityUrl, $Headers);
        if ($Result === null) {
            return 'Error on fetching identity list';
        }
        $IdentitySession = self::parseCookies($ResponseHeader, 'identity_session');
        if ($IdentitySession === '') {
            return 'Error on parsing identity session';
        }
        $Json = self::parseJson($Result);
        if ($Json === null) {
            return 'Error on parsing identity result';
        }
        $Flag = $Json['flag'];

        $VerifyUrl = \Xiaomi\Cloud\ApiVerifyIdentity::getUrl((int) $Flag) . http_build_query([
            '_dc'   => (int) (time() * 1000)
        ]);
        $Headers = \Xiaomi\Cloud\ApiHeader::getLoginHeader(
            $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::AgentID),
            $Cookie . '; identity_session=' . $IdentitySession
        );
        $Data = [
            '_flag'  => $Flag,
            'ticket' => $Code,
            'trust'  => true,
            '_json'  => true
        ];
        $ResponseHeader = '';
        $Result = $this->CurlCall($ResponseHeader, $VerifyUrl, $Headers, $Data);
        if ($Result === null) {
            return 'Error on submit verification code';
        }
        $Json = self::parseJson($Result);
        if ($Json === null) {
            return 'Error on parsing verification result';
        }
        if ($Json['code'] !== 0) {
            if (isset($Json['tips'])) {
                return $Json['tips'];
            }
            return 'Error on submit verification code';
        }
        $LoginUrl = $Json['location'];
        $ResponseHeader = '';
        $Result = $this->CurlCall($ResponseHeader, $LoginUrl, $Headers);
        $this->ServiceToken = self::parseCookies($ResponseHeader, 'serviceToken');
        $this->UserId = (string) self::parseCookies($ResponseHeader, 'userId');
        $Lines = explode("\r\n", $ResponseHeader);
        foreach ($Lines as $Line) {
            $line_array = explode(':', $Line);
            $Field = strtolower(trim(array_shift($line_array)));
            if ($Field == 'extension-pragma') {
                $Data = json_decode(array_shift($line_array), true);
                $this->SSecurity = $Data['ssecurity'];
                $this->SendDebug('Cloud Login', 'Device verification successful', 0);
                $this->SetStatus(IS_ACTIVE);
                return '';
            }
        }
        return 'Error on finalizing verification';
    }

    private function GetServiceToken(string $Url): string
    {
        $this->SendDebug(__FUNCTION__, $Url, 0);
        $Headers = [
            sprintf(\Xiaomi\Cloud\ApiHeader::UserAgent, $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::AgentID))
        ];
        $Result = $this->CurlCall($ResponseHeader, $Url, $Headers);
        if ($Result == 'ok') {
            return self::parseCookies($ResponseHeader, 'serviceToken');
        }
        return '';
    }

    private function Request(string $Path, string $ParamsString): ?string
    {
        $this->SendDebug(__FUNCTION__, $Path, 0);
        $this->SendDebug(__FUNCTION__, $ParamsString, 0);
        $Params['data'] = $ParamsString;
        $Url = \Xiaomi\Cloud\ApiUrl::GetApiUrl($this->ReadPropertyString(\Xiaomi\Cloud\Property::Country), $Path);
        $Nonce = self::GenerateNonce();
        $SignedNonce = self::SignedNonce($this->SSecurity, $Nonce);
        $Params['rc4_hash__'] = self::GenerateSignature($Path, $SignedNonce, $Params);
        foreach ($Params as &$Param) {
            $Param = base64_encode(substr(self::rc4(base64_decode($SignedNonce), str_repeat("\x0", 1024) . $Param), 1024));
        }
        $Params['signature'] = self::GenerateSignature($Path, $SignedNonce, $Params);
        $Params['_nonce'] = $Nonce;
        $Params['ssecurity'] = $this->SSecurity;
        $Cookie = \Xiaomi\Cloud\ApiCookie::getApiCookie(
            $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::ClientID),
            $this->UserId,
            $this->ServiceToken
        );
        $this->SendDebug('Cloud Request (Cookie)', $Cookie, 0);
        $Headers = \Xiaomi\Cloud\ApiHeader::getApiHeader(
            $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::AgentID),
            $Cookie
        );
        $Result = $this->CurlCall($Response, $Url, $Headers, $Params);
        if ($Result === null) {
            return null;
        }
        if ((strpos($Result, 'message') !== false) || (strpos($Result, 'error') !== false) || (strpos($Result, 'code') !== false)) {
            return $Result;
        }
        $Result = substr(self::rc4(base64_decode($SignedNonce), str_repeat("\x0", 1024) . base64_decode($Result)), 1024);
        $this->SendDebug('Cloud Response', $Result, 0);
        return $Result;
    }

    private function CurlCall(?string &$ResponseHeader, string $Url, array $Headers = [], ?array $Data = null): ?string
    {
        $this->SendDebug('Cloud Request Url', $Url, 0);
        $this->SendDebug('Cloud Request Header', $Headers, 0);
        $this->SendDebug('Cloud Request Data', $Data, 0);
        $ch = curl_init($Url);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADEROPT, CURLHEADER_UNIFIED);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $Headers);
        if ($Data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($Data));
        }
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
        $response = curl_exec($ch);
        $HttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_errno = curl_errno($ch);
        $ResponseHeader = '';
        $Result = null;
        if (!is_bool($response)) {
            $Parts = explode("\r\n\r\n", $response);
            $ResponseHeader = array_shift($Parts);
            $Result = implode("\r\n\r\n", $Parts);
        }
        $this->SendDebug('Cloud Response Header (' . $HttpCode . ')', $ResponseHeader, 0);
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
        return $Result;
    }

    private static function parseCookies(string $Data, string $CookieName): string
    {
        $Lines = explode("\r\n", trim($Data));
        array_shift($Lines);

        foreach ($Lines as $Line) {
            $line_array = explode(':', $Line);
            $Field = strtolower(trim(array_shift($line_array)));
            if ($Field != 'set-cookie') {
                continue;
            }
            $CookieString = explode(';', trim(implode(':', $line_array)))[0];
            [$Cookie, $Value] = explode('=', $CookieString, 2);
            if ($Cookie !== $CookieName) {
                continue;
            }
            return $Value;
        }
        return '';
    }

    private static function parseJson(string $Data): ?array
    {
        if (strpos($Data, '&&&START&&&') === 0) {
            $Data = substr($Data, 11);
        }
        return json_decode($Data, true);
    }

    private static function generateRandomString(string $x, int $length = 10): string
    {
        return substr(str_shuffle(str_repeat($x, (int) ceil($length / strlen($x)))), 1, $length);
    }

    private static function GenerateNonce(): string
    {
        $buf = random_bytes(8);
        $timestamp = floor(time() / 60);
        $timestampBytes = pack('N', $timestamp);
        $buf .= $timestampBytes;
        $base64 = base64_encode($buf);
        return $base64;
    }

    private static function SignedNonce(string $SSecurity, string $nonce)
    {
        $s = base64_decode($SSecurity);
        $n = base64_decode($nonce);
        $hash = hash('sha256', $s . $n, true);
        return base64_encode($hash);
    }

    private static function GenerateSignature(string $Path, string $SignedNonce, array $Params)
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

    private static function rc4(string $pwd, string $data): string
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
