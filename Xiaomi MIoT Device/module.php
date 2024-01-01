<?php

declare(strict_types=1);
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/DebugHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/BufferHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/AttributeArrayHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/VariableProfileHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/SemaphoreHelper.php') . '}');
require_once dirname(__DIR__) . '/libs/XiaomiConsts.php';
/**
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 * @method void RegisterAttributeArray(string $name, array $Value, int $Size = 0)
 * @method array ReadAttributeArray(string $name)
 * @method void WriteAttributeArray(string $name, mixed $value)
 * @method void RegisterProfileEx(int $VarTyp, string $Name, string $Icon, string $Prefix, string $Suffix, int|array $Associations, float $MaxValue = -1, float $StepSize = 0, int $Digits = 0)
 * @method void RegisterProfile(int $VarTyp, string $Name, string $Icon, string $Prefix, string $Suffix, float $MinValue, float $MaxValue, float $StepSize, int $Digits = 0)
 * @method bool lock(string $ident)
 * @method void unlock(string $ident)
 * @property bool $ShowVariableWarning
 * @property int $Retries
 * @property int $ServerStamp
 * @property int $ServerStampTime
 */
class XiaomiMIoTDevice extends IPSModule
{
    use \XiaomiMiDevice\DebugHelper;
    use \XiaomiMiDevice\BufferHelper;
    use \XiaomiMiDevice\AttributeArrayHelper;
    use \XiaomiMiDevice\VariableProfileHelper;
    use \XiaomiMiDevice\Semaphore;

    public const PORT_UDP = 54321;

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
        $this->RegisterPropertyInteger(\Xiaomi\Device\Property::RefreshInterval, 60);
        $this->RegisterPropertyBoolean(\Xiaomi\Device\Property::ForceCloud, false);
        $this->RegisterPropertyBoolean(\Xiaomi\Device\Property::DeniedCloud, false);
        $this->RegisterAttributeString(\Xiaomi\Device\Attribute::Token, '');
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::Specs, []);
        $this->RegisterAttributeString(\Xiaomi\Device\Attribute::ProductName, '');
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::Info, []);
        $this->RegisterAttributeString(\Xiaomi\Device\Attribute::Icon, '');
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::Locales, []);
        $this->RegisterAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, false);
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::ActionIdentsWithValues, []);
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::ActionIdents, []);
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsRead, []);
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsWrite, []);
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::LockedStateVariables, []);
        $this->RegisterTimer(\Xiaomi\Device\Timer::RefreshState, 0, 'IPS_RequestAction(' . $this->InstanceID . ',"' . \Xiaomi\Device\Timer::RefreshState . '",true);');
        $this->RegisterTimer(\Xiaomi\Device\Timer::Reconnect, 0, 'IPS_RequestAction(' . $this->InstanceID . ',"' . \Xiaomi\Device\Timer::Reconnect . '",true);');
        $this->Retries = 2;
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        $this->SetTimerInterval(\Xiaomi\Device\Timer::RefreshState, 0);
        $this->SetTimerInterval(\Xiaomi\Device\Timer::Reconnect, 0);
        $this->RegisterProfileEx(
            VARIABLETYPE_INTEGER,
            'XIAOMI.ExecuteAction',
            'Execute',
            '',
            '',
            [
                [0, 'Execute', '', -1]
            ]
        );
        $this->ServerStamp = 0;
        $this->ServerStampTime = 0;

        //Never delete this line!
        parent::ApplyChanges();
        // Anzeige IP in der INFO Spalte
        $this->SetSummary($this->ReadPropertyString(\Xiaomi\Device\Property::Host));
        // Noch keine Events, somit kein Filter
        //$this->SetReceiveDataFilter('.*"ClientIP":"".*');
        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        if ((!$this->ReadPropertyBoolean(\Xiaomi\Device\Property::Active)) || (!$this->ReadPropertyString(\Xiaomi\Device\Property::Host))) {
            $this->Retries = 2;
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        if (!$this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId)) {
            $this->SetStatus(\Xiaomi\Device\InstanceStatus::ConfigError);
            return;
        }
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

        // Info Paket abholen mit model
        if (!$this->GetModelData()) {
            $this->SetStatus(\Xiaomi\Device\InstanceStatus::GetSpecsFailed);
            return;
        }
        $this->CreateStateVariables();
        $this->SetStatus(IS_ACTIVE);
        if ($this->ReadPropertyBoolean(\Xiaomi\Device\Property::ForceCloud)) {
            $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, true);
        } else {
            $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, false);
        }
        if ($this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud)) { //cloud an -> nur ein Versuch
            if (!$this->RequestState()) {
                $this->SetStatus(\Xiaomi\Device\InstanceStatus::InCloudOffline);
                return;
            }
        } else {
            if (!$this->RequestState()) { // wenn erster Versuch fehlschlägt
                if ($this->ReadPropertyBoolean(\Xiaomi\Device\Property::DeniedCloud)) { // und Cloud verboten
                    $this->SetStatus(\Xiaomi\Device\InstanceStatus::TimeoutError);
                    return;
                }
                $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, true); // umschalten auf Cloud
                if (!$this->RequestState()) {
                    $this->SetStatus(\Xiaomi\Device\InstanceStatus::InCloudOffline);
                    return;
                }
            }
        }

        $this->LogMessage($this->Translate('Connection established'), KL_MESSAGE);
        $this->Retries = 2;
        $this->SetTimerInterval(\Xiaomi\Device\Timer::RefreshState, $this->ReadPropertyInteger(\Xiaomi\Device\Property::RefreshInterval) * 1000);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case \Xiaomi\Device\Property::ForceCloud:
                $this->UpdateFormField(\Xiaomi\Device\Property::DeniedCloud, 'enabled', !$Value);
                return;
            case \Xiaomi\Device\Property::DeniedCloud:
                $this->UpdateFormField(\Xiaomi\Device\Property::ForceCloud, 'enabled', !$Value);
                return;
            case \Xiaomi\Device\Timer::RefreshState:
                $this->RequestState();
                return;
            case \Xiaomi\Device\Timer::Reconnect:
                $this->ApplyChanges();
                return;
            case 'ForceReloadModel':
                $this->SetTimerInterval(\Xiaomi\Device\Timer::RefreshState, 0);
                $this->WriteAttributeString(\Xiaomi\Device\Attribute::Token, '');
                $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Specs, []);
                $this->WriteAttributeString(\Xiaomi\Device\Attribute::ProductName, '');
                $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Info, []);
                $this->WriteAttributeString(\Xiaomi\Device\Attribute::Icon, '');
                $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Locales, []);
                $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, false);
                $this->WriteAttributeArray(\Xiaomi\Device\Attribute::ActionIdentsWithValues, []);
                $this->WriteAttributeArray(\Xiaomi\Device\Attribute::ActionIdents, []);
                $this->WriteAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsRead, []);
                $this->WriteAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsWrite, []);
                IPS_RunScriptText('IPS_Applychanges(' . $this->InstanceID . ');');
                return;
            case 'ReloadForm':
                $this->ReloadForm();
                return;
            case 'VariablePanel':
                if ($this->ShowVariableWarning) {
                    $this->UpdateFormField('ErrorPopup', 'visible', true);
                    $this->UpdateFormField('ErrorTitle', 'caption', 'Attention!');
                    $this->UpdateFormField('ErrorText', 'caption', 'Deselecting a variable will delete it immediately.');
                    $this->ShowVariableWarning = false;
                }
                return;
            case 'ChangeVariableEnabled':
                list($VariableIdent, $Enabled) = json_decode($Value, true);
                $LockedStateVariables = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::LockedStateVariables);
                if ($Enabled) {
                    $Index = array_search($VariableIdent, $LockedStateVariables);
                    if ($Index !== false) {
                        unset($LockedStateVariables[$Index]);
                        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::LockedStateVariables, $LockedStateVariables);
                        $this->CreateStateVariables();
                    }
                } else {
                    if (!in_array($VariableIdent, $LockedStateVariables)) {
                        $LockedStateVariables[] = $VariableIdent;
                        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::LockedStateVariables, $LockedStateVariables);
                        $this->UnregisterVariable($VariableIdent);
                    }
                }
                return;
            default:
                $Parts = explode('_', $Ident);
                if (count($Parts) == 3) {
                    list($Type, $ServiceId, $PropertyOrActionId) = $Parts;
                    switch ($Type) {
                        case \Xiaomi\IdentPrefix::Action:
                            if (array_key_exists($Ident, $this->ReadAttributeArray(\Xiaomi\Device\Attribute::ActionIdents))) {
                                $this->ExecuteAction((int) $ServiceId, (int) $PropertyOrActionId, []);
                                return;
                            }
                            if (array_key_exists($Ident, $this->ReadAttributeArray(\Xiaomi\Device\Attribute::ActionIdentsWithValues))) {
                                $this->ExecuteAction((int) $ServiceId, (int) $PropertyOrActionId, [$Value]);
                                return;
                            }
                            break;
                        case \Xiaomi\IdentPrefix::Property:
                            if (array_key_exists($Ident, $this->ReadAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsWrite))) {
                                $this->WriteValue((int) $ServiceId, (int) $PropertyOrActionId, $Value);
                                return;
                            }
                    }
                }
                break;
        }
        trigger_error($this->Translate('Invalid Ident'), E_USER_NOTICE);
    }

    public function RequestState(): bool
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            trigger_error($this->Translate('Instance is not active'), E_USER_NOTICE);
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
        $DisabledStateVariables = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::LockedStateVariables);
        foreach ($Result as $Value) {
            if (array_key_exists('code', $Value)) {
                if ($Value['code'] == -704042011) {
                    if ($this->GetStatus() == IS_ACTIVE) {
                        $this->LogMessage($this->Translate('Device in cloud offline'), KL_ERROR);
                        $this->SetStatus(\Xiaomi\Device\InstanceStatus::InCloudOffline);
                    }
                } elseif ($Value['code'] == -704220043) {
                    $this->SendDebug((string) $Value['siid'] . '_' . (string) $Value['piid'], $this->Translate(\Xiaomi\Device\ApiError::$CodeToText[$Value['code']]), 0);
                    continue;
                } elseif ($Value['code'] != 0) {
                    $this->LogMessage($this->Translate('Unknown error: ') . $Value['code'], KL_ERROR);
                    continue;
                }
            }
            if (!array_key_exists('value', $Value)) {
                continue;
            }
            $Ident = \Xiaomi\IdentPrefix::Property . '_' . (string) $Value['siid'] . '_' . (string) $Value['piid'];
            if (in_array($Ident, $DisabledStateVariables)) {
                continue;
            }
            $this->SendDebug((string) $Value['siid'] . '_' . (string) $Value['piid'], $Value['value'], 0);
            $this->SetValue($Ident, $Value['value']);
        }
        return true;
    }

    public function WriteValueBoolean(int $ServiceId, int $PropertyId, bool $Value): bool
    {
        return $this->WriteValue($ServiceId, $PropertyId, $Value);
    }

    public function WriteValueInteger(int $ServiceId, int $PropertyId, int $Value): bool
    {
        return $this->WriteValue($ServiceId, $PropertyId, $Value);
    }

    public function WriteValueFloat(int $ServiceId, int $PropertyId, float $Value): bool
    {
        return $this->WriteValue($ServiceId, $PropertyId, $Value);
    }

    public function WriteValueString(int $ServiceId, int $PropertyId, string $Value): bool
    {
        return $this->WriteValue($ServiceId, $PropertyId, $Value);
    }

    public function GetConfigurationForm()
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if ($this->GetStatus() == IS_CREATING) {
            return json_encode($Form);
        }
        $Form['elements'][1]['items'][1]['items'][0]['enabled'] = !$this->ReadPropertyBoolean(\Xiaomi\Device\Property::DeniedCloud);
        $Form['elements'][1]['items'][1]['items'][1]['enabled'] = !$this->ReadPropertyBoolean(\Xiaomi\Device\Property::ForceCloud);
        $Icon = $this->ReadAttributeString(\Xiaomi\Device\Attribute::Icon);
        if ($Icon) {
            $Icon = 'data:image/png;base64, ' . $Icon;
        }
        $Form['elements'][1]['items'][2]['image'] = $Icon;
        $Info = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Info);
        $Form['actions'][1]['items'][0]['items'] = [
            [
                'width'     => '400px',
                'type'      => 'Label',
                'caption'   => $this->Translate('Names: ') . $this->ReadAttributeString(\Xiaomi\Device\Attribute::ProductName)
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
            ],
            [
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
                'caption'   => $this->Translate('Specs: ') . (isset($Info['model']) ? \Xiaomi\Device\SpecUrls::Device . $Info['model'] : '')
            ],
            [
                'type'      => 'Label',
                'bold'      => $this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud),
                'color'     => ($this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud) ? '0080C0' : ''),
                'caption'   => $this->Translate('Connection: ') . ($this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud) ? 'Cloud' : 'local')
            ]
        ];
        $this->ShowVariableWarning = true;
        $Form['actions'][1]['items'][1]['onClick'] = 'IPS_RequestAction($id,"VariablePanel", true);';

        $StateVariables = [];
        $LockedStateVariables = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::LockedStateVariables);
        $WriteParam = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsWrite);
        foreach ($this->ReadAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsRead) as $Ident => $Name) {
            $VarId = @$this->GetIDForIdent($Ident);
            $StateVariables[] =
            [
                'ident'   => $Ident,
                'type'    => $this->Translate('Value ' . (array_key_exists($Ident, $WriteParam) ? 'Read/Write' : 'Read')),
                'name'    => (IPS_VariableExists($VarId) ? IPS_GetName($VarId) : $Name),
                'enabled' => !in_array($Ident, $LockedStateVariables)
            ];
        }

        foreach ($this->ReadAttributeArray(\Xiaomi\Device\Attribute::ActionIdents) as $Ident => $Name) {
            $VarId = @$this->GetIDForIdent($Ident);
            $StateVariables[] =
            [
                'ident'   => $Ident,
                'type'    => $this->Translate('Action'),
                'name'    => (IPS_VariableExists($VarId) ? IPS_GetName($VarId) : $Name),
                'enabled' => !in_array($Ident, $LockedStateVariables)
            ];
        }

        foreach ($this->ReadAttributeArray(\Xiaomi\Device\Attribute::ActionIdentsWithValues) as $Ident => $Name) {
            $VarId = @$this->GetIDForIdent($Ident);
            $StateVariables[] =
            [
                'ident'   => $Ident,
                'type'    => $this->Translate('Action with value'),
                'name'    => (IPS_VariableExists($VarId) ? IPS_GetName($VarId) : $Name),
                'enabled' => !in_array($Ident, $LockedStateVariables)
            ];
        }
        $Form['actions'][1]['items'][1]['items'][0]['values'] = $StateVariables;
        $this->SendDebug('FORM', json_encode($Form), 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);
        return json_encode($Form);
    }

    public function ExecuteAction(int $ServiceId, int $ActionId, array $Parameter)
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            trigger_error($this->Translate('Instance is not active'), E_USER_NOTICE);
            return false;
        }
        $Params = [
            'did'  => $this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId),
            'siid' => (int) $ServiceId,
            'aiid' => (int) $ActionId,
            'in'   => $Parameter
        ];
        if ($this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud)) {
            $Params = json_encode(['params'=>$Params]);
            $Result = $this->SendCloud(\Xiaomi\Cloud\ApiUrl::ExecuteAction, $Params);
        } else {
            $Result = $this->SendLocal(\Xiaomi\Device\ApiMethod::ExecuteAction, $Params);
        }
        if (is_null($Result)) {
            return false;
        }
        $this->SendDebug('ActionResult', $Result, 0);
        if ($Result['code'] < 0) {
            echo $this->Translate(\Xiaomi\Device\ApiError::$CodeToText[$Result['code']]);
            return false;
        }
        if (array_key_exists('out', $Result)) {
            return $Result['out'];
        }
        return true;
    }

    protected function SetStatus($State)
    {
        switch ($State) {
            case IS_ACTIVE:
                if ($this->GetStatus() > IS_EBASE) {
                    $this->LogMessage($this->Translate('Reconnect successfully'), KL_MESSAGE);
                }
                break;
            case IS_INACTIVE:
                $this->LogMessage($this->Translate('disconnected'), KL_MESSAGE);
                break;
            default:
                if ($this->Retries < 3600) {
                    $this->Retries++;
                }
                $this->LogMessage('Retry in ' . $this->Retries . ' seconds', KL_MESSAGE);
                $this->SetTimerInterval(\Xiaomi\Device\Timer::Reconnect, $this->Retries * 1000);
                break;
        }
        parent::SetStatus($State);
    }

    private function KernelReady()
    {
        $this->UnregisterMessage(0, IPS_KERNELSTARTED);
        $this->ApplyChanges();
    }

    private function SendLocal(string $Method, array $Prams = []): ?array
    {
        $Payload = json_encode(
            [
                'id'    => random_int(1, 65535),
                'method'=> $Method,
                'params'=> $Prams
            ]
        );
        if ($this->lock(__FUNCTION__)) {
            $this->SendDebug('Send', $Payload, 0);
            $Data = $this->EncryptMessage($Payload);
            $State = IS_ACTIVE;
            $Result = $this->SocketSend($Data, $State);
            if ($State == \Xiaomi\Device\InstanceStatus::TimeoutError) {
                if ($this->ReadPropertyBoolean(\Xiaomi\Device\Property::DeniedCloud)) { // und verboten
                    $this->SetStatus(\Xiaomi\Device\InstanceStatus::TimeoutError);
                }
            }
            $this->unlock(__FUNCTION__);
            return $Result;
        }
        trigger_error($this->Translate('Send blocked'), E_USER_NOTICE);
        return null;
    }

    private function SendCloud(string $Uri, string $Params): ?array
    {
        $this->SendDebug('Cloud Request Uri', $Uri, 0);
        $this->SendDebug('Cloud Request Data', $Params, 0);
        $Response = $this->SendDataToParent(\Xiaomi\Cloud\ForwardData::ToJson($Uri, $Params));
        $this->SendDebug('Cloud Response', $Response, 0);
        if (($Response == '') || ($Response == false)) {
            return null;
        }
        $Result = json_decode($Response, true);
        if ($Result['code'] != 0) {
            echo $this->Translate(\Xiaomi\Cloud\ApiError::$CodeToText[$Result['code']]);
            return null;
        }
        return $Result['result'];
    }

    private function WriteValue(int $ServiceId, int $PropertyId, $Value): bool
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            trigger_error($this->Translate('Instance is not active'), E_USER_NOTICE);
            return false;
        }
        //todo prüfen ob in Specs
        if (false) {
            trigger_error($this->Translate('Invalid ServiceId oder PropertyId'), E_USER_NOTICE);
            return false;
        }
        $Params = [];
        $Params[] = [
            'did'  => $this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId),
            'siid' => (int) $ServiceId,
            'piid' => (int) $PropertyId,
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
        $Ident = \Xiaomi\IdentPrefix::Property . '_' . (string) $ServiceId . '_' . (string) $PropertyId; // . '_' . $Property['prop'];
        if (@$this->GetIDForIdent($Ident)) {
            $this->SetValue($Ident, $Value);
        }
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
                ];
            }
        }
        return $PropList;
    }

    private function GetVariableData(int $Siid, array $Property, &$Locales): array
    {
        $Piid = $Property['iid'];
        $IpsVarType = \Xiaomi\Convert::ToIPSVar($Property['format']);
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
                case 'percentage':
                    $Suffix = ' %';
                    break;
                case 'arcdegrees':
                    $Suffix = ' °';
                    break;
                case 'kelvin':
                    $Profile = '~TWColor';
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
        return [$IpsVarType, $Profile];
    }

    private function CreateStateVariables()
    {
        $Specs = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Specs);
        $Locales = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Locales);
        $DisabledStateVariables = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::LockedStateVariables);
        $this->SendDebug('Specs', json_encode($Specs), 0);
        $ActionsIdentsWithValue = [];
        $ActionsIdents = [];
        $ParamIdentsWrite = [];
        $ParamIdentsRead = [];
        $Pos = 0;
        foreach ($Specs['services'] as $Service) {
            if ($Service['type'] != 'service') {
                continue;
            }
            if (array_key_exists('properties', $Service)) {
                foreach ($Service['properties'] as $Property) {
                    if (!in_array('read', $Property['access']) && !in_array('write', $Property['access'])) {
                        continue;
                    }
                    $Ident = \Xiaomi\IdentPrefix::Property . '_' . (string) $Service['iid'] . '_' . (string) $Property['iid']; // . '_' . $Property['prop'];
                    $Name = $Property['description'];
                    $LocaleKey = sprintf('service:%03d:property:%03d', $Service['iid'], $Property['iid']);
                    if (array_key_exists($LocaleKey, $Locales)) {
                        $Name = $Locales[$LocaleKey];
                    } else {
                        $Name = \Xiaomi\Translate::getLocaleName($Name);
                    }

                    $LocaleServiceKey = sprintf('service:%03d', $Service['iid']);
                    if (array_key_exists($LocaleServiceKey, $Locales)) {
                        $Name = $Locales[$LocaleServiceKey] . ': ' . $Name;
                    }
                    if (!in_array($Ident, $DisabledStateVariables)) {
                        list($IpsVarType, $Profile) = $this->GetVariableData($Service['iid'], $Property, $Locales);
                        $this->MaintainVariable($Ident, $Name, $IpsVarType, $Profile, $Pos++, true);
                        if (in_array('write', $Property['access'])) {
                            $this->EnableAction($Ident);
                        }
                    }
                    if (in_array('write', $Property['access'])) {
                        $ParamIdentsWrite[$Ident] = $Name;
                    }
                    $ParamIdentsRead[$Ident] = $Name;
                }
            }
            if (array_key_exists('actions', $Service)) {
                foreach ($Service['actions'] as $Action) {
                    /*if (count($Action['out'])) { // Aktionen mit ausgaben sind nur per Script erreichbar
                        continue;
                    }*/
                    if (count($Action['in']) > 1) { // Aktionen mit mehr als einen Parameter sind nur per Script erreichbar
                        continue;
                    }
                    $Ident = \Xiaomi\IdentPrefix::Action . '_' . (string) $Service['iid'] . '_' . (string) $Action['iid'];
                    if (in_array($Ident, $DisabledStateVariables)) {
                        continue;
                    }
                    $Name = $Action['description'];
                    $LocaleKey = sprintf('service:%03d:action:%03d', $Service['iid'], $Action['iid']);
                    if (array_key_exists($LocaleKey, $Locales)) {
                        $Name = $Locales[$LocaleKey];
                    } else {
                        $Name = \Xiaomi\Translate::getLocaleName($Name);
                    }

                    $LocaleServiceKey = sprintf('service:%03d', $Service['iid']);
                    if (array_key_exists($LocaleServiceKey, $Locales)) {
                        $Name = $Locales[$LocaleServiceKey] . ': ' . $Name;
                    }

                    if (count($Action['in'])) { // ein Parameter -> Variable mit passendem Profil
                        $PropertyIndex = $Action['in'][0];
                        if (!in_array($Ident, $DisabledStateVariables)) {
                            list($IpsVarType, $Profile) = $this->GetVariableData($Service['iid'], $Service['properties'][$PropertyIndex], $Locales);
                            $this->MaintainVariable($Ident, $Name, $IpsVarType, $Profile, $Pos++, true);
                            $this->SetValue($Ident, -1);
                        }
                        $ActionsIdentsWithValue[$Ident] = $Name;
                    } else { // kein Parameter, nur 'Execute' Profile
                        if (!in_array($Ident, $DisabledStateVariables)) {
                            $this->MaintainVariable($Ident, $Name, VARIABLETYPE_INTEGER, 'XIAOMI.ExecuteAction', $Pos++, true);
                        }
                        $ActionsIdents[$Ident] = $Name;
                    }
                    $this->EnableAction($Ident);
                }
            }
        }
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::ActionIdentsWithValues, $ActionsIdentsWithValue);
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::ActionIdents, $ActionsIdents);
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsRead, $ParamIdentsRead);
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsWrite, $ParamIdentsWrite);
    }

    private function SocketSend(string $Data, int &$State = IS_ACTIVE, bool $Retry = true): ?array
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
                $State = \Xiaomi\Device\InstanceStatus::TimeoutError;
                return null;
            }
            $this->SendDebug('Socket', 'created', 0);
        }
        socket_set_option($this->Socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 7, 'usec' => 0]);
        if (!(@socket_sendto($this->Socket, $Data, strlen($Data), 0, $this->ReadPropertyString(\Xiaomi\Device\Property::Host), self::PORT_UDP))) {
            $ErrorCode = socket_last_error();
            $ErrorMsg = socket_strerror($ErrorCode);
            $this->SendDebug('Socket Error', $ErrorCode . ' message: ' . $ErrorMsg, 0);
            $State = \Xiaomi\Device\InstanceStatus::TimeoutError;
            return null;
        }
        $this->SendDebug('Send (' . $this->ReadPropertyString(\Xiaomi\Device\Property::Host) . ')', $Data, 1);
        $Response = '';
        $RemoteIp = '';
        $RemotePort = 0;
        if (($bytes = @socket_recvfrom($this->Socket, $Response, 4096, 0, $RemoteIp, $RemotePort)) !== false) {
            $this->SendDebug('Receive [' . $RemoteIp . ':' . (string) $RemotePort . ']', $Response, 1);
            $DecodeError = 0;
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
                    if ($this->ReadPropertyBoolean(\Xiaomi\Device\Property::DeniedCloud)) { // und cloud verboten
                        trigger_error('Error: ' . $Result['error']['code'] . PHP_EOL . $Result['error']['message'], E_USER_NOTICE);
                        $this->SetStatus(\Xiaomi\Device\InstanceStatus::ApiError);
                        $State = \Xiaomi\Device\InstanceStatus::ApiError;
                    } else { // cloud erlaubt
                        $this->ConnectParent(\Xiaomi\GUID::CloudIO);
                        $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, true);
                    }
                } else {
                    trigger_error('Error: ' . $Result['error']['code'] . PHP_EOL . $Result['error']['message'], E_USER_NOTICE);
                    $this->SetStatus(\Xiaomi\Device\InstanceStatus::ApiError);
                    $State = \Xiaomi\Device\InstanceStatus::ApiError;
                }
                return null;
            }
            if (array_key_exists('params', $Result)) {
                if ($this->ReadPropertyBoolean(\Xiaomi\Device\Property::DeniedCloud)) { // und cloud verboten
                    trigger_error('Error: receive params index' . PHP_EOL . json_encode($Result['params']), E_USER_NOTICE);
                    $this->SetStatus(\Xiaomi\Device\InstanceStatus::ApiError);
                    $State = \Xiaomi\Device\InstanceStatus::ApiError;
                } else {
                    $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, true);
                }
                return null;
            }
            return $Result['result'];
        }
        $this->SendDebug('Receive Timeout', '', 0);
        if ($Retry) {
            $this->SendDebug('Retry', '', 0);
            if ($this->SendHandshake()) {
                return $this->SocketSend($Data, $State, false);
            }
        }
        $State = \Xiaomi\Device\InstanceStatus::TimeoutError;
        return null;
    }

    private function GetModelData(): bool
    {
        // Info Paket laden
        $Result = $this->SendLocal(\Xiaomi\Device\ApiMethod::Info);
        $this->SendDebug('GetModelData', $Result, 0);
        if (is_null($Result)) {
            $this->SendDebug('Error get model', '', 0);
            return false;
        }
        $OldSpecs = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Info);
        $OldModel = array_key_exists('model', $OldSpecs) ? $OldSpecs['model'] : '';
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Info, $Result);
        $this->SendDebug('Model loaded', $Result['model'], 0);
        $this->SetSummary($this->ReadPropertyString(\Xiaomi\Device\Property::Host) . ' (' . $Result['model'] . ')');
        // das Attribute schon vorhanden ist brauchen wir vielleicht nicht neu laden
        // Fallback von Versionen wo das Attribute fehlte
        if (count($this->ReadAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsRead))) {
            // Wenn model nicht geändert alles okay
            if ($Result['model'] == $OldModel) {
                $this->SendDebug('Model not changed', $OldModel, 0);
                return true;
            }
        }
        $this->SendDebug('Model changed', 'Load specs...', 0);
        $this->LogMessage('Load specs...', KL_NOTIFY);
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
        // Wenn nicht vorhanden, dann geht auch nicht das get/set_properties + siid/piid Protokoll
        $this->loadLocale($Specs['props']['spec']['urn']);
        // Form verzögert neu laden, da die Attribute erst in CreateStateVariables gefüllt werden.
        IPS_RunScriptText('IPS_Sleep(500);IPS_RequestAction(' . $this->InstanceID . ',"ReloadForm",true);');
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
        if (array_key_exists($locale, $Data)) {
            $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Locales, array_merge($Data['en'], $Data[$locale]));
        } else {
            $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Locales, $Data['en']);
        }
        return true;
    }

    private function SendHandshake(): bool
    {
        $Data = hex2bin('21310020ffffffffffffffffffffffffffffffffffffffffffffffffffffffff');
        $this->SendDebug('Send Handshake', $Data, 1);
        $State = IS_ACTIVE;
        $Result = $this->SocketSend($Data, $State, false);
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
        list($TokenKey, $TokenIV) = $this->GetKeyAndIV();
        $Encrypted = openssl_encrypt($data, 'aes-128-cbc', $TokenKey, OPENSSL_RAW_DATA, $TokenIV);
        $Payload = "\x21\x31";
        $Payload .= pack('n', 32 + strlen($Encrypted));
        $Payload .= "\x00\x00\x00\x00";
        $Payload .= pack('N', (int) $this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId));
        if ($this->ServerStampTime) {
            $SecondsPassed = time() - $this->ServerStampTime;
            $Payload .= pack('N', $this->ServerStamp + $SecondsPassed);
        } else {
            $Payload .= "\xff\xff\xff\xff";
        }
        $Payload .= hex2bin($this->ReadAttributeString(\Xiaomi\Device\Attribute::Token));
        $Payload .= $Encrypted;
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
            if ($this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId) == (string) $DeviceId) {
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
        list($TokenKey, $TokenIV) = $this->GetKeyAndIV();
        $Data = openssl_decrypt($encryptedMsg, 'aes-128-cbc', $TokenKey, OPENSSL_RAW_DATA, $TokenIV);
        return $Data;
    }

    private function GetKeyAndIV(): array
    {
        $token = hex2bin($this->ReadAttributeString(\Xiaomi\Device\Attribute::Token));
        $TokenKey = md5($token, true);
        $TokenIV = md5($TokenKey . $token, true);
        return [$TokenKey, $TokenIV];
    }
}
