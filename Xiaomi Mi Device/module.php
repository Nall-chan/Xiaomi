<?php

declare(strict_types=1);
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/DebugHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/BufferHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/AttributeArrayHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/VariableProfileHelper.php') . '}');
require_once dirname(__DIR__) . '/libs/XiaomiConsts.php';
/**
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 * @method void RegisterAttributeArray(string $name, array $Value, int $Size = 0)
 * @method array ReadAttributeArray(string $name)
 * @method void WriteAttributeArray(string $name, mixed $value)
 * @method void RegisterProfileEx(int $VarTyp, string $Name, string $Icon, string $Prefix, string $Suffix, int|array $Associations, float $MaxValue = -1, float $StepSize = 0, int $Digits = 0)
 * @method void RegisterProfile(int $VarTyp, string $Name, string $Icon, string $Prefix, string $Suffix, float $MinValue, float $MaxValue, float $StepSize, int $Digits = 0)
 * @property string $TokenKey
 * @property string $TokenIV
 * @property string $Model
 * @property int $ServerStamp
 * @property int $ServerStampTime
 */
class XiaomiMiDevice extends IPSModule
{
    use \XiaomiMiDevice\DebugHelper;
    use \XiaomiMiDevice\BufferHelper;
    use \XiaomiMiDevice\AttributeArrayHelper;
    use \XiaomiMiDevice\VariableProfileHelper;

    const PORT_UDP = 54321;

    private $Socket = false;

    public function __destruct()
    {
        if ($this->Socket) {
            socket_close($this->Socket);
            $this->Socket = false;
        }
    }

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyBoolean(\Xiaomi\Device\Property::Active, false);
        $this->RegisterPropertyString(\Xiaomi\Device\Property::Host, '');
        $this->RegisterPropertyString(\Xiaomi\Device\Property::DeviceId, '');
        $this->RegisterAttributeString(\Xiaomi\Device\Attribute::Token, '');
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::Specs, []);
        $this->RegisterAttributeString(\Xiaomi\Device\Attribute::ProductName, '');
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::Info, []);
        $this->RegisterAttributeString(\Xiaomi\Device\Attribute::Icon, '');
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::Locales, []);
        $this->RegisterAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, false);
        $this->RegisterPropertyInteger('RefreshInterval', 60);
        $this->RegisterTimer('RefreshState', 0, 'IPS_RequestAction(' . $this->InstanceID . ',"RefreshState",true);');
        $this->Model = '';
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        $this->SetTimerInterval('RefreshState', 0);
        $this->TokenKey = '';
        $this->TokenIV = '';
        $this->ServerStamp = 0;
        $this->ServerStampTime = 0;
        //Never delete this line!
        parent::ApplyChanges();
        // Anzeige IP in der INFO Spalte
        $this->SetSummary($this->ReadPropertyString(\Xiaomi\Device\Property::Host));
        // Noch keine Events, somit kein Filter
        //$this->SetReceiveDataFilter('.*"ClientIP":"".*');
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        if ((!$this->ReadPropertyBoolean(\Xiaomi\Device\Property::Active)) || (!$this->ReadPropertyString(\Xiaomi\Device\Property::Host))) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        if (!$this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId)) {
            $this->SetStatus(\Xiaomi\Device\InstanceStatus::ConfigError);
            return;
        }
        //$isOnlineInCloud=false;
        if (!$this->ReadAttributeString(\Xiaomi\Device\Attribute::Token)) {
            // Kein Token -> Token aus Cloud holen.
            if (!$this->GetToken()) {
                $this->SetStatus(\Xiaomi\Device\InstanceStatus::GetTokenFailed);
                return;
            }
        }
        if (!$this->SendHandshake()) {
            return;
        }
        // Noch keine Events, somit kein Filter
        //$this->SetReceiveDataFilter('.*"ClientIP":"' . $this->ReadPropertyString(\Xiaomi\Device\Property::Host) . '".*');
        $token = hex2bin($this->ReadAttributeString(\Xiaomi\Device\Attribute::Token));
        $this->TokenKey = md5($token, true);
        $this->TokenIV = md5($this->TokenKey . $token, true);
        $this->SendDebug('Token', $token, 1);
        $this->SendDebug('Key', $this->TokenKey, 1);
        $this->SendDebug('IV', $this->TokenIV, 1);
        // Info Paket abholen mit model
        if (!$this->GetModelData()) {
            $this->SetStatus(\Xiaomi\Device\InstanceStatus::GetSpecsFailed);
            return;
        }
        $this->CreateStateVariables();
        $this->ReloadForm(); // Damit alle verbundenen Konsole die neuen Daten anzeigen und nicht nur die eine, welche auf übernehmen geklickt hat
        $this->SetStatus(IS_ACTIVE);
        if ($this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud)) { //cloud an -> nur ein Versuch
            $this->RequestState();
        } else {
            if (!$this->RequestState()) { // wenn erster Versuch fehlschlägt
                $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, true); // umschalten auf Cloud
                $this->RequestState(); //zweiter versuch
            }
        }
        $this->SetTimerInterval('RefreshState', $this->ReadPropertyInteger('RefreshInterval') * 1000);
    }

    public function SendLocal(string $Method, array $Prams = []): ?array
    {
        $Payload = json_encode(
            [
                'id'    => random_int(1, 65535),
                'method'=> $Method,
                'params'=> $Prams
            ]
        );
        $this->SendDebug('Send', $Payload, 0);
        $Data = $this->EncryptMessage($Payload);
        return $this->SocketSend($Data);
    }

    /* todo für Events von IO?!
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        $this->SendDebug('Receive', $data, 0);
        $Msg = $this->DecryptMessage(utf8_decode($data['Buffer']));
        if (is_null($Msg)) {
            return '';
        } elseif ($Msg === '') { //handshake
            $this->SendDebug('Receive Handshake', '', 0);
            $this->WaitForHandshake = false;
            return '';
        }
        $this->SendDebug('Receive Data', $Msg, 0);
        //todo
        return '';
    }*/
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'RefreshState':
                $this->RequestState();
                return;
            case 'ForceReloadModel':
                $this->Model = '';
                $this->WriteAttributeString(\Xiaomi\Device\Attribute::Token, '');
                $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Specs, []);
                $this->WriteAttributeString(\Xiaomi\Device\Attribute::ProductName, '');
                $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Info, []);
                $this->WriteAttributeString(\Xiaomi\Device\Attribute::Icon, '');
                $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Locales, []);
                $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, false);
                IPS_RunScriptText('IPS_Applychanges(' . $this->InstanceID . ');');
                return;
            default:
            //todo prüfen ob in Specs
            if (true) {
                $this->WriteValue($Ident, $Value);
                return;
            }
            break;
        }
        trigger_error('invalid Ident', E_USER_NOTICE);
    }
    public function RequestState(): bool
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            trigger_error('instance is not active ', E_USER_NOTICE);
            return false;
        }
        if ($this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud)) {
            $Params = json_encode(['params'=>$this->GetPropertiesParams()]);
            $Result = $this->SendCloud(\Xiaomi\Cloud\ApiUrl::GetProperties, $Params);
        } else {
            $Result = $this->SendLocal(\Xiaomi\Device\ApiMethod::GetProperties, $this->GetPropertiesParams());
        }
        if (is_null($Result)) {
            return false;
        }
        foreach ($Result as $Value) {
            if (!array_key_exists('value', $Value)) {
                continue;
            }
            $this->SendDebug((string) $Value['siid'] . '_' . (string) $Value['piid'], $Value['value'], 0);
            $this->SetValue(\Xiaomi\IdentPrefix::Property . '_' . (string) $Value['siid'] . '_' . (string) $Value['piid'], $Value['value']);
        }
        return true;
    }
    public function WriteValueBoolean(string $Ident, bool $Value): bool
    {
        return $this->WriteValue($Ident, $Value);
    }
    public function WriteValueInteger(string $Ident, int $Value): bool
    {
        return $this->WriteValue($Ident, $Value);
    }
    public function WriteValueFloat(string $Ident, float $Value): bool
    {
        return $this->WriteValue($Ident, $Value);
    }
    public function WriteValueString(string $Ident, string $Value): bool
    {
        return $this->WriteValue($Ident, $Value);
    }
    public function GetConfigurationForm()
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if ($this->GetStatus() == IS_CREATING) {
            return json_encode($Form);
        }
        $Icon = $this->ReadAttributeString(\Xiaomi\Device\Attribute::Icon);
        if ($Icon) {
            $Icon = 'data:image/png;base64, ' . $Icon;
        }
        $Form['elements'][1]['items'][1]['image'] = $Icon;
        $Info = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Info);
        $Form['actions'][0]['items'][1]['items'] = [
            [
                'width'     => '400px',
                'type'      => 'Label',
                'caption'   => 'Names: ' . $this->ReadAttributeString(\Xiaomi\Device\Attribute::ProductName)
            ],
            [
                'type'      => 'Label',
                'link'      => false,
                'caption'   => 'Model: ' . (isset($Info['model']) ? $Info['model'] : '')
            ],
            [
                'type'      => 'Label',
                'caption'   => 'Token: ' . $this->ReadAttributeString(\Xiaomi\Device\Attribute::Token)
            ],
            [
                'type'      => 'Label',
                'caption'   => 'miio version: ' . (isset($Info['miio_ver']) ? $Info['miio_ver'] : '')
            ],            [
                'type'      => 'Label',
                'caption'   => 'Firmware: ' . (isset($Info['fw_ver']) ? $Info['fw_ver'] : '')
            ],
            [
                'type'      => 'Label',
                'caption'   => 'Hardware: ' . (isset($Info['hw_ver']) ? $Info['hw_ver'] : '')
            ],
            [
                'type'      => 'Label',
                'caption'   => 'MAC: ' . (isset($Info['mac']) ? $Info['mac'] : '')
            ],
            [
                'type'      => 'Label',
                'caption'   => 'Infosite: ' . (isset($Info['model']) ? \Xiaomi\Device\SpecUrls::Device . $Info['model'] : '')
            ],
            [
                'type'      => 'Label',
                'bold'      => $this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud),
                'color'     => ($this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud) ? '#0080C0' : ''),
                'caption'   => 'Connection: ' . ($this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud) ? 'Cloud' : 'local')
            ]
        ];
        $this->SendDebug('FORM', json_encode($Form), 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);
        return json_encode($Form);
    }
    private function SendCloud(string $Uri, string $Params): ?array
    {
        $this->SendDebug('Cloud Request Uri', $Uri, 0);
        $this->SendDebug('Cloud Request Data', $Params, 0);
        $Response = $this->SendDataToParent(\Xiaomi\Cloud\ForwardData::ToJson($Uri, $Params));
        $this->SendDebug('Cloud Response', $Response, 0);
        if ($Response == '') {
            return null;
        }
        $Result = json_decode($Response, true);
        if ($Result['code'] != 0) {
            echo $this->Translate(\Xiaomi\Cloud\ApiError::$CodeToText[$Result['code']]);
            return null;
        }
        return $Result['result'];
    }
    private function WriteValue(string $Ident, $Value): bool
    {
        list($Type, $Siid, $Piid) = explode('_', $Ident);
        if ($Type != \Xiaomi\IdentPrefix::Property) {
            trigger_error('invalid Ident', E_USER_NOTICE);
            return false;
        }
        if ($this->GetStatus() != IS_ACTIVE) {
            trigger_error('instance is not active ', E_USER_NOTICE);
            return false;
        }
        $Params = [];
        $Params[] = [
            'did'  => $this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId),
            'siid' => (int) $Siid,
            'piid' => (int) $Piid,
            'value'=> $Value
        ];
        if ($this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud)) {
            $Params = json_encode(['params'=>$Params]);
            $Result = $this->SendCloud(\Xiaomi\Cloud\ApiUrl::SetProperties, $Params);
        } else {
            $Result = $this->SendLocal(\Xiaomi\Device\ApiMethod::SetProperties, $Params);
        }
        if (is_null($Result)) {
            return false;
        }
        if ($Result[0]['code'] > 1) {
            echo $this->Translate(\Xiaomi\Device\ApiError::$CodeToText[$Result[0]['code']]);
            return false;
        }
        $this->SetValue($Ident, $Value);
        return true;
    }
    private function GetToken(): bool
    {
        $this->ConnectParent(\Xiaomi\GUID::CloudIO);
        $this->SendDebug(__FUNCTION__, '', 0);
        $Params = json_encode(
            [
                'dids' => [
                    $this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId)
                ]
            ]
        );
        $Result = $this->SendCloud(\Xiaomi\Cloud\ApiUrl::Device_List, $Params);
        if (is_null($Result)) {
            return false;
        }
        if (is_null($Result['list'])) {
            return false;
        }
        $this->WriteAttributeString(\Xiaomi\Device\Attribute::Token, $Result['list'][0]['token']);
        return true;
    }
    private function GetPropsParams(): array
    {
        /// leider falsch :(
        return [];
    }
    private function GetPropertiesParams(): array
    {
        $PropList = [];
        $Specs = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Specs);
        foreach ($Specs['services'] as $Service) {
            if ($Service['type'] != 'service') {
                continue;
            }
            $Siid = $Service['iid'];
            if (!array_key_exists('properties', $Service)) {
                continue;
            }
            foreach ($Service['properties'] as $Property) {
                if (!in_array('read', $Property['access'])) {
                    continue;
                }
                $Piid = $Property['iid'];
                $PropList[] = [
                    'did' => $this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId),
                    'siid'=> $Siid,
                    'piid'=> $Piid,
                    //'prop'=> $Property['prop']

                ];
            }
        }
        return $PropList;
    }
    private function CreateStateVariables()
    {
        $Specs = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Specs);
        $Locales = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Locales);
        $this->SendDebug('Specs', json_encode($Specs), 0);
        //$DeviceUrn = $Specs['urn'];
        $Pos = 0;
        foreach ($Specs['services'] as $Service) {
            if ($Service['type'] != 'service') {
                continue;
            }
            $Siid = $Service['iid'];
            if (array_key_exists('properties', $Service)) {
                foreach ($Service['properties'] as $Property) {
                    if (!in_array('read', $Property['access']) && !in_array('write', $Property['access'])) {
                        continue;
                    }
                    $Name = $Property['description'];
                    $Piid = $Property['iid'];
                    $LocaleKey = sprintf('service:%03d:property:%03d', $Siid, $Piid);
                    if (array_key_exists($LocaleKey, $Locales)) {
                        $Name = $Locales[$LocaleKey];
                    } else {
                        $Name = \Xiaomi\Translate::getLocaleName($Name);
                    }
                    $Ident = \Xiaomi\IdentPrefix::Property . '_' . (string) $Siid . '_' . (string) $Piid; // . '_' . $Property['prop'];
                    $IpsVarType = \Xiaomi\Convert::ToIPSVar($Property['format']);
                    //$this->SendDebug('Property',$Property,0);
                    $Profile = \Xiaomi\Convert::getProfileName($Property['urn'], $Property['name']);
                    $Suffix = '';
                    $Min = 0;
                    $Max = 0;
                    $Step = 0;
                    $Assoziation = [];
                    if (array_key_exists('unit', $Property)) {
                        switch ($Property['unit']) {
                            case 'none':
                                break;
                            case 'rgb':
                                $Profile = '~HexColor';
                                break;
                            default:
                                $Suffix = ' ' . \Xiaomi\Translate::getLocaleUnit($Property['unit']);
                                break;
                        }
                    }
                    if (array_key_exists('value-list', $Property)) {
                        foreach ($Property['value-list'] as $Index => $Values) {
                            $LocaleKey = sprintf('service:%03d:property:%03d:valuelist:%03d', $Siid, $Piid, $Index);
                            if (array_key_exists($LocaleKey, $Locales)) {
                                $Values['description'] = $Locales[$LocaleKey];
                            } else {
                                $Values['description'] = \Xiaomi\Translate::getLocaleName($Values['description']);
                            }
                            $Assoziation[] = [$Values['value'], $Values['description'], '', -1];
                        }
                    }
                    if (array_key_exists('value-range', $Property)) {
                        list($Min, $Max, $Step) = $Property['value-range'];
                    }
                    switch ($IpsVarType) {
                        case VARIABLETYPE_BOOLEAN:
                            if (($Suffix == '') && (count($Assoziation) == 0)) {
                                $Profile = (in_array('write', $Property['access']) ? '~Switch' : '');
                            }
                            break;
                        }
                    if (($Profile) && ($Profile[0] != '~')) {
                        if (count($Assoziation)) {
                            $this->RegisterProfileEx($IpsVarType, $Profile, '', '', $Suffix, $Assoziation);
                        } else {
                            $this->RegisterProfile($IpsVarType, $Profile, '', '', $Suffix, $Min, $Max, $Step);
                        }
                    }
                    $this->MaintainVariable($Ident, $Name, $IpsVarType, $Profile, $Pos++, true);
                    if (in_array('write', $Property['access'])) {
                        $this->EnableAction($Ident);
                    }
                }
            }
            if (array_key_exists('actions', $Service)) {
                foreach ($Service['actions'] as $Action) {
                    //todo
                }
            }
        }
    }
    private function SocketSend(string $Data, int &$State = IS_ACTIVE): ?array
    {
        if ($this->Socket) {
            $this->SendDebug('Socket', 'already created', 0);
        } else {
            $this->Socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if (!$this->Socket) {
                $this->SendDebug('Socket', 'created', 0);
                $ErrorCode = socket_last_error();
                $ErrorMsg = socket_strerror($ErrorCode);
                $this->SendDebug('Socket Error', $ErrorCode . ' message: ' . $ErrorMsg, 0);
                $State =\Xiaomi\Device\InstanceStatus::TimeoutError;
                return null;
            }
            $this->SendDebug('Socket', 'created', 0);
        }
        socket_set_option($this->Socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 7, 'usec' => 0]);
        if (!(@socket_sendto($this->Socket, $Data, strlen($Data), 0, $this->ReadPropertyString(\Xiaomi\Device\Property::Host), self::PORT_UDP))) {
            $State =\Xiaomi\Device\InstanceStatus::TimeoutError;
            return null;
        }
        $Response = '';
        $RemoteIp = '';
        $RemotePort = 0;
        if (($bytes = @socket_recvfrom($this->Socket, $Response, 4096, 0, $RemoteIp, $RemotePort)) !== false) {
            $this->SendDebug('Receive [' . $RemoteIp . ':' . (string) $RemotePort . ']', $Response, 1);
            $DecodeError=0;
            $JSONResult = $this->DecryptMessage($Response, $DecodeError);
            if (is_null($JSONResult)) {
                if ($DecodeError == \Xiaomi\Device\InstanceStatus::DidNotMatch) {
                    $State = $DecodeError;
                }
                return null;
            }
            if ($JSONResult === '') { //handshake
                return [];
            }
            $this->SendDebug('Receive', $JSONResult, 0);
            $Result = json_decode(trim($JSONResult), true);
            if (array_key_exists('error', $Result)) {
                if ((($Result['error']['code'] == -32601) || ($Result['error']['code'] == -9999)) && !$this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud)) {
                    $this->ConnectParent(\Xiaomi\GUID::CloudIO);
                    $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, true);
                } else {
                    trigger_error('Error: ' . $Result['error']['code'] . PHP_EOL . $Result['error']['message'], E_USER_NOTICE);
                    $this->$this->SetStatus(\Xiaomi\Device\InstanceStatus::ApiError);
                    $State =\Xiaomi\Device\InstanceStatus::ApiError;
                }
                return null;
            }
            if (array_key_exists('params', $Result)) {
                $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, true);
                return null;
            }
            return $Result['result'];
        }
        $this->SendDebug('Receive Timeout', '', 0);
        $State =\Xiaomi\Device\InstanceStatus::TimeoutError;
        return null;
    }
    private function GetModelData(): bool
    {
        // übersetzungen :)
        // https://de.api.io.mi.com/app/service/getappconfig?data=%7B%22lang%22%3A%22en%22%2C%22name%22%3A%22card_default_txt%22%2C%22version%22%3A%221%22%7D

        //https://de.api.io.mi.com/app/service/getappconfig?data=%7B%22lang%22%3A%22zh_CN%22%2C%22name%22%3A%22card_control_config%22%2C%22version%22%3A%2213%22%7D
        //Version 1-13 möglich
        // Info Paket laden
        $Result = $this->SendLocal(\Xiaomi\Device\ApiMethod::Info);
        $this->SendDebug('GetModelData', $Result, 0);
        if (is_null($Result)) {
            $this->SendDebug('Error get model', '', 0);
            return false;
        }
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Info, $Result);
        $this->SendDebug('Model loaded', $Result['model'], 0);
        // Wenn model nicht geändert alles okay

        if ($Result['model'] == $this->Model) {
            $this->SendDebug('Model not changed', $this->Model, 0);
            return true;
        }

        $this->SendDebug('Model changed', 'Load specs...', 0);
        // Fähigkeiten neu laden
        $Data = @Sys_GetURLContentEx(\Xiaomi\Device\SpecUrls::Device . $Result['model'], ['Timeout'=>15000]);
        if (!$Data) {
            $this->SendDebug('ERROR load SpecsDom', \Xiaomi\Device\SpecUrls::Device . $Result['model'], 0);
            return false;
        }
        $this->SendDebug('Get SpecsDom', $Data, 0);
        $HtmlDoc = new DOMDocument();
        $HtmlDoc->loadHTML($Data);
        $xpath = new DOMXPath($HtmlDoc);
        $AppDataNodes = $xpath->query('//*[@id="app"]', null, false);
        if ($AppDataNodes->length != 1) {
            $this->SendDebug('Error get model node', $AppDataNodes->length, 0);
            return false;
        }
        $JsonSpecs = $AppDataNodes->item(0)->attributes->getNamedItem('data-page')->nodeValue;
        $Specs = json_decode($JsonSpecs, true);
        // services/property in AttributeArray schreiben
        $IconURLs = array_intersect_key($Specs['props']['product'], array_flip(['icon_real', 'icon_on', 'icon_off']));
        foreach ($IconURLs as $IconURL) {
            if ($IconURL) {
                $IconRaw = @Sys_GetURLContentEx($IconURL, ['Timeout'=>5000]);
                $this->WriteAttributeString(\Xiaomi\Device\Attribute::Icon, base64_encode($IconRaw));
                break;
            }
        }

        $this->SendDebug('Model name', $Specs['props']['product']['name'], 0);
        $this->SendDebug('Model specs', $Specs['props']['spec'], 0);
        $this->WriteAttributeString(\Xiaomi\Device\Attribute::ProductName, implode("\r\n", $Specs['props']['product']['names']));
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Specs, $Specs['props']['spec']);
        $this->loadLocale($Specs['props']['spec']['urn']);
        $this->Model = $Result['model'];
        //Übersetzungen können hier geladen werden:
        //https://miot-spec.org/instance/v2/multiLanguage
        /*Post array off Strings
        {
            "urns": [
                "urn:miot-spec-v2:device:motion-sensor:0000A014:lumi-v2:2,0",
                "urn:miot-spec-v2:device:camera:0000A01C:chuangmi-ipc009:1,0",
                "urn:miot-spec-v2:device:fan:0000A005:dmaker-p18:1,0",
                "urn:miot-spec-v2:device:light:0000A001:yeelink-color1:1,0",
                "urn:miot-spec-v2:device:magnet-sensor:0000A016:lumi-v2:1,0",
                "urn:miot-spec-v2:device:gateway:0000A019:lumi-mieu01:1,0"
            ]
        }*/
        // Wenn nicht vorhanden, dann geht auch nicht das get/set_properties + siid/piid protokoll ?!
        return true;
    }
    private function loadLocale(string $Urn)
    {
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Locales, []);
        $locale = explode('_', IPS_GetSystemLanguage())[0];
        $this->SendDebug(__FUNCTION__, \Xiaomi\Device\SpecUrls::Locales . $Urn, 0);
        $Data = @Sys_GetURLContent(\Xiaomi\Device\SpecUrls::Locales . $Urn);
        if (!$Data) {
            return false;
        }
        $Data = json_decode($Data, true)['data'];
        if (!count($Data)) { //empty
            return false;
        }
        if (!array_key_exists($locale, $Data)) {
            $locale = 'en';
        }
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Locales, $Data[$locale]);
        return true;
    }
    private function SendHandshake(): bool
    {
        $Data = hex2bin('21310020ffffffffffffffffffffffffffffffffffffffffffffffffffffffff');
        $this->SendDebug('Send Handshake', $Data, 1);
        $State = IS_ACTIVE;
        $Result = $this->SocketSend($Data, $State);
        if (is_null($Result)) {
            if ($State == \Xiaomi\Device\InstanceStatus::DidNotMatch) {
                $this->SetStatus(\Xiaomi\Device\InstanceStatus::DidNotMatch);
            } else {
                $this->SetStatus(\Xiaomi\Device\InstanceStatus::TimeoutError);
            }
            return false;
        }
        return true;
    }

    private function EncryptMessage(string $data): string
    {
        // Encrypt the data
        $Encrypted = openssl_encrypt($data, 'aes-128-cbc', $this->TokenKey, OPENSSL_RAW_DATA, $this->TokenIV);
        // Magic start

        $Payload = "\x21\x31";
        // Set the length
        $Payload .= pack('n', 32 + strlen($Encrypted));
        // Unknown
        $Payload .= "\x00\x00\x00\x00";
        // Device ID
        $Payload .= pack('N', (int) $this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId));
        // Stamp
        if ($this->ServerStampTime) {
            $SecondsPassed = time() - $this->ServerStampTime;
            $Payload .= pack('N', $this->ServerStamp + $SecondsPassed);
        } else {
            $Payload .= "\xff\xff\xff\xff";
        }
        $Payload .= hex2bin($this->ReadAttributeString(\Xiaomi\Device\Attribute::Token));
        $Payload .= $Encrypted;
        // MD5 Checksum
        $Checksum = md5($Payload, true);
        for ($i = 0; $i < 16; $i++) {
            $Payload[$i + 16] = $Checksum[$i];
        }
        $this->SendDebug('Encrypted', $Payload, 1);
        return $Payload;
    }

    private function DecryptMessage(string $msg, int &$DecodeError = 0): ?string
    {
        $Data = str_split($msg, 4);
        if (substr($Data[0], 0, 2) != "\x21\x31") {
            $this->SendDebug('Error on decrypt', 'header wrong', 0);
            $DecodeError = \Xiaomi\Device\ApiError::PaketError;
            return null;
        }
        $len = unpack('n', $Data[0], 2)[1];
        if (strlen($msg) != $len) {
            $this->SendDebug('Error on decrypt', 'Paket should ' . $len . ' bytes', 0);
            $DecodeError = \Xiaomi\Device\ApiError::PaketError;
            return null;
        }
        if (count($Data) < 8) {
            $this->SendDebug('Error on decrypt', 'Paket to short', 0);
            $DecodeError = \Xiaomi\Device\ApiError::PaketError;
            return null;
        }
        $DeviceId = unpack('N', $Data[2])[1];
        $this->SendDebug('Receive DeviceID', $DeviceId, 0);
        $Stamp = unpack('N', $Data[3])[1];
        $this->SendDebug('Receive Stamp', $Stamp, 0);

        $recvChecksum = $Data[4] . $Data[5] . $Data[6] . $Data[7];
        $encryptedMsg = substr($msg, 32);

        if ($Stamp > 0) {
            $this->ServerStamp = $Stamp;
            $this->ServerStampTime = time();
        }

        if (strlen($encryptedMsg) === 0) {           // handshake

            if ($this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId) == (string)$DeviceId) {
                return '';
            }
            $DecodeError = \Xiaomi\Device\InstanceStatus::DidNotMatch;
            return null;
        }
        $calcChecksum = md5(
            substr($msg, 0, 16) .
            hex2bin($this->ReadAttributeString(\Xiaomi\Device\Attribute::Token)) .
            $encryptedMsg,
            true
        );

        if ($calcChecksum != $recvChecksum) {
            $this->SendDebug('Error in checksum', 'Received: ' . bin2hex($recvChecksum) . ' Calc: ' . bin2hex($calcChecksum), 0);
            $DecodeError = \Xiaomi\Device\ApiError::ChecksumError;
            return null;
        }
        //Received: 5d62e8b9 Calc: 5d62e8b9f45fa97dbf53a654f13dc6a6
        $Data = openssl_decrypt($encryptedMsg, 'aes-128-cbc', $this->TokenKey, OPENSSL_RAW_DATA, $this->TokenIV);
        return $Data;
    }
}
