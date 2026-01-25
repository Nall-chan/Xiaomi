<?php

declare(strict_types=1);

eval('declare(strict_types=1);namespace XiaomiCloudIO {?>' . file_get_contents(__DIR__ . '/../libs/helper/DebugHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiCloudIO {?>' . file_get_contents(__DIR__ . '/../libs/helper/BufferHelper.php') . '}');
require_once dirname(__DIR__) . '/libs/XiaomiConsts.php';
/**
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 * @property string $VerifyUrl
 * @property string $IdentitySession
 * @property int $Flag
 */
class XiaomiMIoTCloudIO extends IPSModule
{
    use \XiaomiCloudIO\DebugHelper;
    use \XiaomiCloudIO\BufferHelper;

    /** @var array $CURL_error_codes */
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
        85 => 'CURLE_RTSP_CSEQ_ERROR',
        86 => 'CURLE_RTSP_SESSION_ERROR',
        87 => 'CURLE_FTP_BAD_FILE_LIST',
        88 => 'CURLE_CHUNK_FAILED'
    ];

    /** @var array $http_error */
    private static $http_error =
        [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Server error'
        ];

    /**
     * Create
     *
     * @return void
     */
    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterAttributeString(\Xiaomi\Cloud\Attribute::Username, '');
        $this->RegisterAttributeString(\Xiaomi\Cloud\Attribute::Password, '');
        $this->RegisterAttributeString(\Xiaomi\Cloud\Attribute::Country, 'de');
        $this->RegisterAttributeString(\Xiaomi\Cloud\Attribute::AgentID, self::generateRandomString('ABCDEF', 13));
        $this->RegisterAttributeString(\Xiaomi\Cloud\Attribute::ClientID, self::generateRandomString('ABCDEFGHIJKLMNOPQRSTUVWXYZ', 6));
        $this->RegisterAttributeString(\Xiaomi\Cloud\Attribute::ServiceToken, '');
        $this->RegisterAttributeString(\Xiaomi\Cloud\Attribute::SSecurity, '');
        $this->RegisterAttributeString(\Xiaomi\Cloud\Attribute::UserId, '');
        $this->RegisterAttributeString(\Xiaomi\Cloud\Attribute::cUserId, '');
        $this->RegisterAttributeString(\Xiaomi\Cloud\Attribute::Location, '');
        $this->RegisterAttributeString(\Xiaomi\Cloud\Attribute::Sign, '');
        $this->VerifyUrl = '';
        $this->IdentitySession = '';
    }

    /**
     * Migrate
     *
     * @param  string $JSONData
     * @return string
     */
    public function Migrate($JSONData)
    {
        $j = json_decode($JSONData);
        if (isset($j->configuration->{\Xiaomi\Cloud\Attribute::Username})) {
            $j->attributes->{\Xiaomi\Cloud\Attribute::Username} = $j->configuration->{\Xiaomi\Cloud\Attribute::Username};
            $j->attributes->{\Xiaomi\Cloud\Attribute::Password} = $j->configuration->{\Xiaomi\Cloud\Attribute::Password};
            $j->attributes->{\Xiaomi\Cloud\Attribute::Country} = $j->configuration->{\Xiaomi\Cloud\Attribute::Country};
            unset($j->configuration->{\Xiaomi\Cloud\Attribute::Username});
            unset($j->configuration->{\Xiaomi\Cloud\Attribute::Password});
            unset($j->configuration->{\Xiaomi\Cloud\Attribute::Country});
        }
        return json_encode($j);
    }

    /**
     * ApplyChanges
     *
     * @return void
     */
    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();
        $this->SetSummary($this->ReadAttributeString(\Xiaomi\Cloud\Attribute::Username));
        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        if ($this->ReadAttributeString(\Xiaomi\Cloud\Attribute::Username) == '' || $this->VerifyUrl != '') {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        if ($this->ReadAttributeString(\Xiaomi\Cloud\Attribute::Location) == '') {
            if (!$this->StartLogin(
                $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::Username),
                $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::Password)
            )) {
                $this->SetStatus(IS_EBASE + 1);
                return;
            }
        }
        $this->UpdateServiceToken();
    }

    /**
     * MessageSink
     *
     * @param  mixed $TimeStamp
     * @param  mixed $SenderID
     * @param  mixed $Message
     * @param  mixed $Data
     * @return void
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;
        }
    }

    /**
     * RequestAction
     *
     * @param  string $Ident
     * @param  mixed $Value
     * @return void
     */
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'LoginPopup':
                $this->UpdateFormField('LoginPopup', 'visible', true);
                break;
            case 'UpdateServiceToken':
                break;
        }
    }

    /**
     * GetConfigurationForm
     *
     * @return string
     */
    public function GetConfigurationForm(): string
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if ($this->GetStatus() == IS_CREATING) {
            return json_encode($Form);
        }
        if ($this->ReadAttributeString(\Xiaomi\Cloud\Attribute::Username)) {
            $Form['actions'][0]['visible'] = true;
            if ($this->VerifyUrl) {
                list($VerifyMessage, $ErrorMessage) = $this->StartVerifyDevice();
                if ($VerifyMessage != '') {
                    $Form['actions'][3]['popup']['items'][1]['caption'] = $VerifyMessage;
                } else {
                    $Form['actions'][3]['popup']['items'][1]['caption'] = $this->Translate($ErrorMessage);
                    $Form['actions'][3]['popup']['items'][1]['color'] = 0xff0000;
                    $Form['actions'][3]['popup']['items'][2]['enabled'] = false;
                }
                $Form['actions'][3]['visible'] = true;
                //$Form['actions'][3]['popup']['items'][1]['onClick'] = 'echo "' . $this->VerifyUrl . '";';
            }
        } else {
            $Form['actions'][1]['visible'] = true;
            $Form['actions'][2]['visible'] = true;
        }
        $this->SendDebug('FORM', json_encode($Form), 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);
        return json_encode($Form);
    }

    /**
     * ForwardData
     *
     * @param  mixed $JSONString
     * @return string
     */
    public function ForwardData($JSONString)
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            $this->SendDebug('ForwardData', 'Instance not active', 0);
            return '';
        }
        list($Uri, $Params) = \Xiaomi\Cloud\ForwardData::FromJson($JSONString);
        $Response = $this->Request($Uri, $Params);
        if (is_null($Response)) {
            return '';
        }
        $Result = json_decode($Response, true);
        if ($Result === null) {
            return '';
        }
        if ($Result['code'] == 2) {
            if ($this->Login(
                $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::Username),
                $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::Password)
            )) {
                if ($this->UpdateServiceToken()) {
                    $Response = $this->Request($Uri, $Params);
                    return is_null($Response) ? '' : $Response;
                }
            }
            $this->SetStatus(IS_EBASE + 1);
        }
        return is_null($Response) ? '' : $Response;
    }

    /**
     * Logout
     *
     * @return void
     */
    public function Logout(): void
    {
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::Username, '');
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::Password, '');
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::ServiceToken, '');
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::SSecurity, '');
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::UserId, '');
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::cUserId, '');
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::Location, '');
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::Sign, '');
        $this->SetStatus(IS_INACTIVE);
        $this->ReloadForm();
    }

    /**
     * SetCredentials
     *
     * @param  string $Username
     * @param  string $Password
     * @param  string $Country
     * @return string
     */
    public function SetCredentials(string $Username, string $Password, string $Country): string
    {
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::Country, $Country);
        if ($this->StartLogin($Username, $Password)) {
            $this->UpdateServiceToken();
            $this->ReloadForm();
            return '';
        }
        return $this->Translate('Unauthorized');
    }

    /**
     * SendVerificationCode
     *
     * @return string
     */
    public function SendVerificationCode(): string
    {
        $this->SendDebug(__FUNCTION__, '', 0);
        $Cookie = \Xiaomi\Cloud\ApiCookie::getLoginCookie($this->ReadAttributeString(\Xiaomi\Cloud\Attribute::ClientID));
        $VerifyUrl = \Xiaomi\Cloud\ApiCheckIdentity::getUrl($this->Flag) . http_build_query([
            '_dc'   => (int) (time() * 1000)
        ]);
        $Headers = \Xiaomi\Cloud\ApiHeader::getLoginHeader(
            $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::AgentID),
            $Cookie . '; identity_session=' . $this->IdentitySession
        );
        $Data = [
            'retry'  => 0,
            'icode'  => '',
            '_json'  => 'true'
        ];
        $ResponseHeader = '';
        $Result = $this->CurlCall($ResponseHeader, $VerifyUrl, $Headers, $Data);
        if ($Result === null) {
            return 'Error in request to send verification code';
        }
        $Json = self::parseJson($Result);
        if ($Json === null) {
            return 'Error in parsing result from send verification request';
        }
        if ($Json['code'] !== 0) {
            if (isset($Json['tips'])) {
                return $Json['tips'];
            }
            return 'Error in request to send verification code';
        }
        $this->UpdateFormField('SendVerificationCodeButton', 'enabled', false);
        $this->UpdateFormField('SendVerificationCodeButton', 'caption', $this->Translate('Code sent'));
        return '';
    }

    /**
     * SubmitVerificationCode
     *
     * @param  string $Code
     * @return string
     */
    public function SubmitVerificationCode(string $Code): string
    {
        $Result = $this->VerifyDevice($Code);
        if ($Result == '') {
            return $this->Translate('MESSAGE:Verification successful!');
        }
        return $Result;
    }

    /**
     * KernelReady
     *
     * @return void
     */
    private function KernelReady()
    {
        $this->UnregisterMessage(0, IPS_KERNELSTARTED);
        $this->UpdateServiceToken();
    }

    /**
     * StartLogin
     *
     * @param  string $Username
     * @param  string $Password
     * @return bool
     */
    private function StartLogin(string $Username, string $Password): bool
    {
        $Sign = $this->getLoginSign();
        if (is_null($Sign)) {
            $this->SendDebug('ERROR Cloud', 'could not connect', 0);
            $this->SetStatus(IS_EBASE + 1);
            return false;
        }
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::Sign, $Sign);
        if (!$this->Login($Username, $Password)) {
            if ($this->VerifyUrl == '') {
                $this->SendDebug('ERROR Cloud', 'invalid login', 0);
                $this->SetStatus(IS_EBASE + 1);
            }
            return false;
        }
        return true;
    }

    /**
     * getLoginSign
     *
     * @return string
     */
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

    /**
     * Login
     *
     * @param  string $Username
     * @param  string $Password
     * @return bool
     */
    private function Login(string $Username, string $Password): bool
    {
        $this->SendDebug(__FUNCTION__, '', 0);
        $this->VerifyUrl = '';
        $this->IdentitySession = '';
        $Payload = \Xiaomi\Cloud\ApiData::getLoginPayload(
            $Username,
            $Password,
            $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::Sign)
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
            $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::Username, $Username);
            $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::Password, $Password);
            list($VerifyMessage, $ErrorMessage) = $this->StartVerifyDevice();

            $this->UpdateFormField('LogoutButton', 'visible', true);
            $this->UpdateFormField('LoginButton', 'visible', false);
            $this->UpdateFormField('LoginPopup', 'visible', false);

            //$this->UpdateFormField('VerifyUrl', 'onClick', 'echo "' . $Json['notificationUrl'] . '";');
            $this->UpdateFormField('VerifyMessage', 'caption', $VerifyMessage);
            if ($VerifyMessage != '') {
                $this->UpdateFormField('VerifyMessage', 'caption', $VerifyMessage);
            } else {
                $this->UpdateFormField('VerifyMessage', 'caption', $this->Translate($ErrorMessage));
                $this->UpdateFormField('VerifyMessage', 'color', 0xff0000);
                $this->UpdateFormField('SubmitVerificationCodeButton', 'visible', false);
            }
            $this->UpdateFormField('VerifyPopup', 'visible', true);
            return false;
        }
        $Data = array_intersect_key($Json, array_flip(['ssecurity', 'userId', 'location', 'cUserId']));
        if (count($Data) !== 4) {
            return false;
        }
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::Username, $Username);
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::Password, $Password);
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::SSecurity, $Data['ssecurity']);
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::UserId, (string) $Data['userId']);
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::cUserId, $Data['cUserId']);
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::Location, $Data['location']);
        return true;
    }

    /**
     * StartVerifyDevice
     *
     * @return array
     */
    private function StartVerifyDevice(): array
    {
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
            return ['', 'Error on fetching verification list'];
        }
        $this->IdentitySession = self::parseCookies($ResponseHeader, 'identity_session');
        if ($this->IdentitySession === '') {
            return ['', 'Error on parsing identity session'];
        }
        $Json = self::parseJson($Result);
        if ($Json === null) {
            return ['', 'Error on parsing verification list'];
        }
        $this->Flag = (int) $Json['flag'];
        $VerifyUrl = \Xiaomi\Cloud\ApiVerifyIdentity::getUrl($this->Flag)
        . http_build_query(
            [
                '_flag'  => $this->Flag,
                '_json'  => 'true'
            ]
        );
        $Headers = \Xiaomi\Cloud\ApiHeader::getLoginHeader(
            $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::AgentID),
            $Cookie . '; identity_session=' . $this->IdentitySession
        );
        $ResponseHeader = '';
        $Result = $this->CurlCall($ResponseHeader, $VerifyUrl, $Headers);
        if ($Result === null) {
            return ['', 'Error on fetching verification message'];
        }
        $Json = self::parseJson($Result);
        if ($Json === null) {
            return ['', 'Error on parsing verification message'];
        }
        if ($Json['code'] !== 0) {
            if (isset($Json['tips'])) {
                return $Json['tips'];
            }
            return ['', 'Error on fetching verification message'];
        }
        list($Message, $Index) = \Xiaomi\Cloud\ApiVerifyIdentity::getMessageTextAndIndex($this->Flag);
        $Message = sprintf($this->Translate($Message), $Json[$Index]);
        return [$Message, ''];
    }

    /**
     * VerifyDevice
     *
     * @param  string $Code
     * @return string
     */
    private function VerifyDevice(string $Code): string
    {
        $this->SendDebug(__FUNCTION__, $Code, 0);
        $Cookie = \Xiaomi\Cloud\ApiCookie::getLoginCookie($this->ReadAttributeString(\Xiaomi\Cloud\Attribute::ClientID));
        $VerifyUrl = \Xiaomi\Cloud\ApiVerifyIdentity::getUrl($this->Flag) . http_build_query([
            '_dc'   => (int) (time() * 1000)
        ]);
        $Headers = \Xiaomi\Cloud\ApiHeader::getLoginHeader(
            $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::AgentID),
            $Cookie . '; identity_session=' . $this->IdentitySession
        );
        $Data = [
            '_flag'  => $this->Flag,
            '_json'  => 'true',
            'ticket' => $Code,
            'trust'  => 'true'
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
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::ServiceToken, self::parseCookies($Result, 'serviceToken'));
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::UserId, (string) self::parseCookies($Result, 'userId'));
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::cUserId, (string) self::parseCookies($Result, 'cUserId'));
        $Lines = explode("\r\n", $Result);
        foreach ($Lines as $Line) {
            $line_array = explode(':', $Line);
            $Field = strtolower(trim(array_shift($line_array)));
            if ($Field == 'location') {
                $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::Location, trim(implode(':', $line_array)));
                continue;
            }
            if ($Field == 'extension-pragma') {
                $Data = json_decode(trim(implode(':', $line_array)), true);
                $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::SSecurity, $Data['ssecurity']);
                $this->SendDebug('Cloud Login', 'Device verification successful', 0);
                $this->SetStatus(IS_ACTIVE);
                $this->VerifyUrl = '';
                return '';
            }
        }
        return 'Error on finalizing verification';
    }

    /**
     * UpdateServiceToken
     *
     * @return bool
     */
    private function UpdateServiceToken(): bool
    {
        $this->SendDebug(__FUNCTION__, '', 0);
        $ServiceToken = '';
        $Headers = [
            sprintf(\Xiaomi\Cloud\ApiHeader::UserAgent, $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::AgentID))
        ];
        $Result = $this->CurlCall($ResponseHeader, $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::Location), $Headers);
        if ($Result == 'ok') {
            $ServiceToken = self::parseCookies($ResponseHeader, 'serviceToken');
            $this->SetStatus(IS_ACTIVE);
        } else {
            $this->SendDebug('ERROR Cloud', 'could not fetch token', 0);
            $this->SetStatus(IS_EBASE + 1);
        }
        $this->WriteAttributeString(\Xiaomi\Cloud\Attribute::ServiceToken, $ServiceToken);
        return $ServiceToken !== '';
    }

    /**
     * Request
     *
     * @param  string $Path
     * @param  string $ParamsString
     * @return ?string
     */
    private function Request(string $Path, string $ParamsString): ?string
    {
        $this->SendDebug(__FUNCTION__, $Path, 0);
        $this->SendDebug(__FUNCTION__, $ParamsString, 0);
        $Params['data'] = $ParamsString;
        $Url = \Xiaomi\Cloud\ApiUrl::GetApiUrl($this->ReadAttributeString(\Xiaomi\Cloud\Attribute::Country), $Path);
        $Nonce = self::GenerateNonce();
        $SignedNonce = self::SignedNonce($this->ReadAttributeString(\Xiaomi\Cloud\Attribute::SSecurity), $Nonce);
        $Params['rc4_hash__'] = self::GenerateSignature($Path, $SignedNonce, $Params);
        foreach ($Params as &$Param) {
            $Param = base64_encode(substr(self::rc4(base64_decode($SignedNonce), str_repeat("\x0", 1024) . $Param), 1024));
        }
        $Params['signature'] = self::GenerateSignature($Path, $SignedNonce, $Params);
        $Params['_nonce'] = $Nonce;
        $Params['ssecurity'] = $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::SSecurity);
        $Cookie = \Xiaomi\Cloud\ApiCookie::getApiCookie(
            $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::ClientID),
            $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::UserId),
            $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::ServiceToken)
        );
        $this->SendDebug('Cloud Request (Cookie)', $Cookie, 0);
        $Headers = \Xiaomi\Cloud\ApiHeader::getApiHeader(
            $this->ReadAttributeString(\Xiaomi\Cloud\Attribute::AgentID),
            $Cookie
        );
        $Result = $this->CurlCall($Response, $Url, $Headers, $Params);
        $this->SendDebug('Cloud Response ', $Response, 0);
        if ($Result === null) {
            return null;
        }
        if ((strpos($Result, 'message') !== false) || (strpos($Result, 'error') !== false) || (strpos($Result, 'code') !== false)) {
            return $Result;
        }
        $Result = substr(self::rc4(base64_decode($SignedNonce), str_repeat("\x0", 1024) . base64_decode($Result)), 1024);
        $this->SendDebug('Cloud Response Decoded', $Result, 0);
        return $Result;
    }

    /**
     * CurlCall
     *
     * @param  ?string $ResponseHeader
     * @param  string $Url
     * @param  array $Headers
     * @param  ?array $Data
     * @return ?string
     */
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
            case 401:
            case 403:
                $this->SendDebug(self::$http_error[$HttpCode], $HttpCode, 0);
                return $Result;
            case 0:
                $this->SendDebug('CURL ERROR', self::$CURL_error_codes[$curl_errno], 0);
                return null;
            case 400:
            case 404:
            case 405:
            case 500:
                $this->SendDebug(self::$http_error[$HttpCode], $HttpCode, 0);
                return null;
        }
        return $Result;
    }

    /**
     * parseCookies
     *
     * @param  string $Data
     * @param  string $CookieName
     * @return string
     */
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

    /**
     * parseJson
     *
     * @param  string $Data
     * @return array
     */
    private static function parseJson(string $Data): ?array
    {
        if (strpos($Data, '&&&START&&&') === 0) {
            $Data = substr($Data, 11);
        }
        return json_decode($Data, true);
    }

    /**
     * generateRandomString
     *
     * @param  string $x
     * @param  int $length
     * @return string
     */
    private static function generateRandomString(string $x, int $length = 10): string
    {
        return substr(str_shuffle(str_repeat($x, (int) ceil($length / strlen($x)))), 1, $length);
    }

    /**
     * GenerateNonce
     *
     * @return string
     */
    private static function GenerateNonce(): string
    {
        $buf = random_bytes(8);
        $timestamp = floor(time() / 60);
        $timestampBytes = pack('N', $timestamp);
        $buf .= $timestampBytes;
        $base64 = base64_encode($buf);
        return $base64;
    }

    /**
     * SignedNonce
     *
     * @param  string $SSecurity
     * @param  string $nonce
     * @return string
     */
    private static function SignedNonce(string $SSecurity, string $nonce): string
    {
        $s = base64_decode($SSecurity);
        $n = base64_decode($nonce);
        $hash = hash('sha256', $s . $n, true);
        return base64_encode($hash);
    }

    /**
     * GenerateSignature
     *
     * @param  string $Path
     * @param  string $SignedNonce
     * @param  array $Params
     * @return string
     */
    private static function GenerateSignature(string $Path, string $SignedNonce, array $Params): string
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

    /**
     * rc4
     *
     * @param  string $pwd
     * @param  string $data
     * @return string
     */
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
