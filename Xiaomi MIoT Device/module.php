<?php

declare(strict_types=1);
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/DebugHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/BufferHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/AttributeArrayHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/VariableProfileHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/ParentIOHelper.php') . '}');
require_once dirname(__DIR__) . '/libs/XiaomiConsts.php';

/**
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 * @method void RegisterAttributeArray(string $name, array $Value, int $Size = 0)
 * @method array ReadAttributeArray(string $name)
 * @method void WriteAttributeArray(string $name, mixed $value)
 * @method void RegisterProfileEx(int $VarTyp, string $Name, string $Icon, string $Prefix, string $Suffix, int|array $Associations, float $MaxValue = -1, float $StepSize = 0, int $Digits = 0)
 * @method void RegisterProfile(int $VarTyp, string $Name, string $Icon, string $Prefix, string $Suffix, float $MinValue, float $MaxValue, float $StepSize, int $Digits = 0)
 * @method int RegisterParent()
 * @method bool IORequestAction(string $Ident, mixed $Value)
 * @property bool $ShowVariableWarning
 * @property int $Retries Abstand bis zum nächsten Verbindungsversuch in Sekunden (0 = kein Fehlschlag)
 * @property bool $Reconnecting
 * @property int $ServerStamp
 * @property int $ServerStampTime
 * @property int $ParentID
 * @property int $MessageId
 */
class XiaomiMIoTDevice extends IPSModule
{
    use \XiaomiMiDevice\DebugHelper;
    use \XiaomiMiDevice\BufferHelper;
    use \XiaomiMiDevice\AttributeArrayHelper;
    use \XiaomiMiDevice\VariableProfileHelper;
    use \XiaomiMiDevice\InstanceStatus {
        \XiaomiMiDevice\InstanceStatus::MessageSink as IOMessageSink;
        \XiaomiMiDevice\InstanceStatus::RequestAction as IORequestAction;
    }
    public const PORT_UDP = 54321;

    /**
     * Socket
     *
     * @var resource
     */
    private $Socket = false;

    /**
     * __destruct
     *
     * @return void
     */
    public function __destruct()
    {
        if ($this->Socket) {
            socket_close($this->Socket);
            $this->Socket = false;
        }
    }

    /**
     * Create
     *
     * @return void
     */
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
        $this->Retries = 0;
        $this->Reconnecting = false;
        $this->ParentID = 0;
        $this->MessageId = random_int(1, \Xiaomi\Device\LocalProtocol::MaxMessageId);
    }

    /**
     * Migrate
     *
     * @param  string $JSONData
     * @return string
     */
    public function Migrate($JSONData)
    {
        //Never delete this line!
        parent::Migrate($JSONData);
        $j = json_decode($JSONData);
        $Changed = false;
        // Die Persistenz enthält auch nicht mehr registrierte Schlüssel und wird vollständig zurückgeschrieben: alte entfernen.
        foreach (['configuration' => \Xiaomi\Device\ObsoleteKeys::Properties, 'attributes' => \Xiaomi\Device\ObsoleteKeys::Attributes] as $Section => $Keys) {
            foreach ($Keys as $Key) {
                if (isset($j->{$Section}) && property_exists($j->{$Section}, $Key)) {
                    unset($j->{$Section}->{$Key});
                    $Changed = true;
                }
            }
        }
        // Specs im alten Format (vorhanden, aber ohne 'urn') zurücksetzen, damit Token und Specs neu geladen werden.
        if (isset($j->attributes)) {
            $Specs = json_decode((string) ($j->attributes->{\Xiaomi\Device\Attribute::Specs} ?? '[]'), true);
            if (is_array($Specs) && (count($Specs) > 0) && !isset($Specs['urn'])) {
                $Token = (string) ($j->attributes->{\Xiaomi\Device\Attribute::Token} ?? '');
                $j->attributes->{\Xiaomi\Device\Attribute::Token} = '';
                $j->attributes->{\Xiaomi\Device\Attribute::Specs} = '[]';
                $Changed = true;
                if ($Token !== '') {
                    $this->LogMessage($this->Translate('Device specifications in outdated format, token and specifications will be reloaded'), KL_NOTIFY);
                }
            }
        }
        // Leerer String = keine Änderung (SDK)
        return $Changed ? json_encode($j) : '';
    }

    /**
     * ApplyChanges
     *
     * @return void
     */
    public function ApplyChanges()
    {
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

        //Never delete this line!
        parent::ApplyChanges();
        $this->MessageId = random_int(1, \Xiaomi\Device\LocalProtocol::MaxMessageId);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        // Wenn Kernel nicht fertig, dann nix machen und darauf warten
        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        $this->RegisterParent();
        $this->InitConnection();
    }

    /**
     * MessageSink
     *
     * @param  int $TimeStamp
     * @param  int $SenderID
     * @param  int $Message
     * @param  array $Data
     * @return void
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $myState = IPS_GetInstance($this->InstanceID)['InstanceStatus']; // NICHT $this->GetStatus benutzen, da MessageSink auch aufgerufen wird, wenn die Instanz noch nicht erstellt wurde (z.B. beim Modul Update)
        if ($myState == IS_CREATING || $myState == IS_DELETING) {
            return;
        }

        $this->IOMessageSink($TimeStamp, $SenderID, $Message, $Data);

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
        if ($this->IORequestAction($Ident, $Value)) {
            return;
        }
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
                // Reconnect Timer abgelaufen. Verbindungsaufbau versuchen.
                $this->SetTimerInterval(\Xiaomi\Device\Timer::Reconnect, 0);
                $this->SendDebug('Reconnect', 'try handshake', 0);
                if ($this->SendHandshake()) {
                    // Retries und Logmeldung erst nach der ersten erfolgreichen Abfrage (RequestState),
                    // ein Handshake allein beweist bei instabilen Geräten noch keine Verbindung.
                    $this->SetStatus(IS_ACTIVE);
                    $this->SetTimerInterval(\Xiaomi\Device\Timer::RefreshState, $this->ReadPropertyInteger(\Xiaomi\Device\Property::RefreshInterval) * 1000);
                }
                return;
            case 'ForceReloadModel':
                // User hat Geräteinfos neu laden über die Instanz-Konfig ausgeführt.
                // Alle Timer anhalten
                $this->SetTimerInterval(\Xiaomi\Device\Timer::RefreshState, 0);
                $this->SetTimerInterval(\Xiaomi\Device\Timer::Reconnect, 0);
                // Alle Attribute löschen
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
                // Neu laden von allen Einstellungen und Attributen
                $this->InitConnection();
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
        echo $this->Translate('Invalid Ident') . PHP_EOL;
    }

    /**
     * RequestState
     *
     * @return bool
     */
    public function RequestState(): bool
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            echo $this->Translate('Instance is not active') . PHP_EOL;
            return false;
        }
        $Params = $this->GetPropertiesParams();
        if (empty($Params)) {
            return false;
        }
        if ($this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud)) {
            $Params = json_encode(['params'=>$Params]);
            $Result = $this->SendCloud(\Xiaomi\Cloud\ApiUrl::GetProperties, $Params);
        } else {
            // Lokal höchstens 10 Eigenschaften je Anfrage, manche Geräte verwerfen größere Anfragen.
            $Result = [];
            foreach (array_chunk($Params, \Xiaomi\Device\LocalProtocol::MaxPropertiesPerRequest) as $Chunk) {
                $Part = $this->SendLocal(\Xiaomi\Device\ApiMethod::GetProperties, $Chunk);
                if (is_null($Part)) {
                    $Result = null;
                    break;
                }
                $Result = array_merge($Result, $Part);
            }
        }
        if (is_null($Result)) {
            return false;
        }
        $DisabledStateVariables = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::LockedStateVariables);
        foreach ($Result as $Value) {
            $Ident = \Xiaomi\IdentPrefix::Property . '_' . (string) $Value['siid'] . '_' . (string) $Value['piid'];
            if (in_array($Ident, $DisabledStateVariables)) {
                continue;
            }
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
                    $Message = $this->GetErrorText(\Xiaomi\Device\ApiError::$CodeToText, (int) $Value['code']);
                    $this->LogMessage($Message . ' - ' . $this->GetIDForIdent($Ident) . ' ' . IPS_GetName($this->GetIDForIdent($Ident)), KL_ERROR);
                    continue;
                }
            }
            if (!array_key_exists('value', $Value)) {
                continue;
            }
            $this->SendDebug((string) $Value['siid'] . '_' . (string) $Value['piid'], $Value['value'], 0);
            $this->SetValue($Ident, $Value['value']);
        }
        if ($this->Reconnecting && ($this->GetStatus() == IS_ACTIVE)) {
            // Erst jetzt gilt die Verbindung als wiederhergestellt (siehe SetStatus)
            $this->Reconnecting = false;
            $this->Retries = 0;
            $this->LogMessage($this->Translate('Reconnect successfully'), KL_MESSAGE);
        }
        return true;
    }

    /**
     * WriteValueBoolean
     *
     * @param  int $ServiceId
     * @param  int $PropertyId
     * @param  bool $Value
     * @return bool
     */
    public function WriteValueBoolean(int $ServiceId, int $PropertyId, bool $Value): bool
    {
        return $this->WriteValue($ServiceId, $PropertyId, $Value);
    }

    /**
     * WriteValueInteger
     *
     * @param  int $ServiceId
     * @param  int $PropertyId
     * @param  int $Value
     * @return bool
     */
    public function WriteValueInteger(int $ServiceId, int $PropertyId, int $Value): bool
    {
        return $this->WriteValue($ServiceId, $PropertyId, $Value);
    }

    /**
     * WriteValueFloat
     *
     * @param  int $ServiceId
     * @param  int $PropertyId
     * @param  float $Value
     * @return bool
     */
    public function WriteValueFloat(int $ServiceId, int $PropertyId, float $Value): bool
    {
        return $this->WriteValue($ServiceId, $PropertyId, $Value);
    }

    /**
     * WriteValueString
     *
     * @param  int $ServiceId
     * @param  int $PropertyId
     * @param  string $Value
     * @return bool
     */
    public function WriteValueString(int $ServiceId, int $PropertyId, string $Value): bool
    {
        return $this->WriteValue($ServiceId, $PropertyId, $Value);
    }

    /**
     * GetConfigurationForm
     *
     * @return string
     */
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
                'caption'   => 'Token: ' . \Xiaomi\Sensitive::preview($this->ReadAttributeString(\Xiaomi\Device\Attribute::Token))
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

    /**
     * ExecuteAction
     *
     * @param  int $ServiceId
     * @param  int $ActionId
     * @param  array $Parameter
     * @return mixed false bei Fehler, sonst die Rückgabewerte der Aktion (out) als Array oder true, wenn das Gerät keine liefert
     */
    public function ExecuteAction(int $ServiceId, int $ActionId, array $Parameter): mixed
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            echo $this->Translate('Instance is not active') . PHP_EOL;
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
        $this->SendDebug('ActionResult', \Xiaomi\Sensitive::mask($Result), 0);
        $Code = (int) ($Result['code'] ?? 0);
        if ($Code < 0) {
            echo $this->GetErrorText(\Xiaomi\Device\ApiError::$CodeToText, $Code) . PHP_EOL;
            return false;
        }
        // Liefert die Aktion Rückgabewerte (out), werden diese zurückgegeben.
        if (array_key_exists('out', $Result)) {
            return $Result['out'];
        }
        return true;
    }

    /**
     * SetStatus
     *
     * @param  mixed $State
     * @return void
     */
    protected function SetStatus($State)
    {
        switch ($State) {
            case IS_ACTIVE:
                // „Wiederverbinden erfolgreich“ meldet RequestState nach der ersten erfolgreichen Abfrage.
                break;
            case IS_INACTIVE:
                $this->Retries = 0;
                $this->Reconnecting = false;
                // Nur melden, wenn vorher eine Verbindung bestand (nicht bei jedem ApplyChanges inaktiver Instanzen)
                if ($this->GetStatus() == IS_ACTIVE) {
                    $this->LogMessage($this->Translate('disconnected'), KL_MESSAGE);
                }
                break;
            default:
                // Keine zyklischen Abfragen im Fehlerzustand, der Reconnect-Timer übernimmt.
                $this->SetTimerInterval(\Xiaomi\Device\Timer::RefreshState, 0);
                if (!$this->ReadPropertyBoolean(\Xiaomi\Device\Property::Active)) { // Zwischen senden und empfangen wurde die Instanz auf inactik gesetzt
                    return;
                }
                if ($this->GetTimerInterval(\Xiaomi\Device\Timer::Reconnect) > 0) {
                    // Fehlschlag dieses Versuchs ist bereits gezählt und gemeldet (z.B. Handshake und Anfrage)
                    break;
                }
                // Abstand: 15 Sekunden, dann verdoppeln bis 32 Minuten (wie Event Control). Jeder Fehlschlag ins Log.
                $this->Retries = ($this->Retries == 0) ? \Xiaomi\Device\ReconnectDelay::First : min($this->Retries * 2, \Xiaomi\Device\ReconnectDelay::Max);
                $this->Reconnecting = true;
                $this->SendDebug('Reconnect', sprintf('failed, next try in %d seconds', $this->Retries), 0);
                if ($this->Retries < 60) {
                    $Message = sprintf($this->Translate('Connection failed, next attempt in %d seconds'), $this->Retries);
                } elseif ($this->Retries < 120) {
                    $Message = $this->Translate('Connection failed, next attempt in 1 minute');
                } else {
                    $Message = sprintf($this->Translate('Connection failed, next attempt in %d minutes'), intdiv($this->Retries, 60));
                }
                $this->LogMessage($Message, KL_ERROR);
                $this->SetTimerInterval(\Xiaomi\Device\Timer::Reconnect, $this->Retries * 1000);
                break;
        }
        parent::SetStatus($State);
    }

    /**
     * Wird ausgeführt wenn sich der Status vom Parent ändert.
     */
    protected function IOChangeState($State)
    {
        switch ($State) {
            case IS_ACTIVE:
                // Bereits verbundene Instanzen (z.B. lokal, schon in ApplyChanges verbunden) nicht erneut verbinden.
                if ($this->GetStatus() != IS_ACTIVE) {
                    $this->ApplyChanges();
                }
                break;
        }
    }

    /**
     * InitConnection
     *
     * @return void
     */
    private function InitConnection(): void
    {
        $this->SetTimerInterval(\Xiaomi\Device\Timer::RefreshState, 0);
        $this->SetTimerInterval(\Xiaomi\Device\Timer::Reconnect, 0);
        $this->ServerStamp = 0;
        $this->ServerStampTime = 0;
        $this->Retries = 0;
        // Anzeige IP in der INFO Spalte
        $this->SetSummary($this->ReadPropertyString(\Xiaomi\Device\Property::Host));

        if ((!$this->ReadPropertyBoolean(\Xiaomi\Device\Property::Active)) || (!$this->ReadPropertyString(\Xiaomi\Device\Property::Host))) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        if (!$this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId)) {
            $this->SetStatus(\Xiaomi\Device\InstanceStatus::ConfigError);
            return;
        }

        if (!$this->ReadAttributeString(\Xiaomi\Device\Attribute::Token)) {
            if ($this->HasActiveParent()) {
                // Kein Token -> Token aus Cloud holen.
                if (!$this->GetToken()) {
                    $this->SetStatus(\Xiaomi\Device\InstanceStatus::GetTokenFailed);
                    return;
                }
            } else {
                $this->SetStatus(IS_INACTIVE);
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
        $ForceCloud = $this->ReadPropertyBoolean(\Xiaomi\Device\Property::ForceCloud);
        $DeniedCloud = $this->ReadPropertyBoolean(\Xiaomi\Device\Property::DeniedCloud);
        if ($ForceCloud) {
            $UseCloud = true;
        } elseif ($DeniedCloud) {
            $UseCloud = false;
        } else {
            // Zuletzt funktionierenden Weg zuerst versuchen. Das erspart Geräten,
            // die lokal nicht antworten, bei jedem ApplyChanges den lokalen Timeout.
            $UseCloud = $this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud);
        }
        $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, $UseCloud);
        if ($UseCloud && !$this->HasActiveParent()) {
            // Cloud IO (noch) nicht aktiv, z.B. beim Systemstart: warten, IOChangeState verbindet neu.
            // Ein Fallback auf lokal würde sonst den falschen Weg merken.
            $this->SendDebug(__FUNCTION__, 'waiting for active Cloud IO', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        $this->SetStatus(IS_ACTIVE);
        if (!$this->RequestState()) {
            if ($ForceCloud || $DeniedCloud) { // nur ein Weg erlaubt
                $this->SetStatus($UseCloud ? \Xiaomi\Device\InstanceStatus::InCloudOffline : \Xiaomi\Device\InstanceStatus::TimeoutError);
                return;
            }
            if ($this->GetStatus() != IS_ACTIVE) { // Fehlerzustand wurde schon gesetzt (z.B. Handshake), Reconnect-Timer läuft
                return;
            }
            // Anderen Weg versuchen (SocketSend kann bereits selbst auf Cloud umgeschaltet haben)
            if ($this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud) == $UseCloud) {
                $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, !$UseCloud);
            }
            if (!$this->RequestState()) {
                if ($this->GetStatus() == IS_ACTIVE) {
                    $this->SetStatus($this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud) ? \Xiaomi\Device\InstanceStatus::InCloudOffline : \Xiaomi\Device\InstanceStatus::TimeoutError);
                }
                return;
            }
        }
        $this->LogMessage($this->Translate('Connection established'), KL_MESSAGE);
        $this->SetTimerInterval(\Xiaomi\Device\Timer::RefreshState, $this->ReadPropertyInteger(\Xiaomi\Device\Property::RefreshInterval) * 1000);
    }

    /**
     * KernelReady
     *
     * @return void
     */
    private function KernelReady(): void
    {
        $this->UnregisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterParent();
        $this->InitConnection();
    }

    /**
     * SendLocal
     *
     * @param  string $Method
     * @param  array $Prams
     * @return ?array
     */
    private function SendLocal(string $Method, array $Prams = []): ?array
    {
        $Waited = false;
        if (!$this->LockLocal($Waited)) {
            echo $this->Translate('Send blocked') . PHP_EOL;
            return null;
        }
        if ($Waited && ($this->GetStatus() != IS_ACTIVE)) {
            // Die Anfrage, auf die gewartet wurde, hat die Verbindung verloren: nicht erneut auf den Timeout warten.
            $this->UnlockLocal();
            echo $this->Translate('Instance is not active') . PHP_EOL;
            return null;
        }
        $Retry = true;
        while (true) {
            $Payload = json_encode(
                [
                    'id'    => $this->NextMessageId(),
                    'method'=> $Method,
                    'params'=> $Prams
                ]
            );
            $this->SendDebug('Send', $Payload, 0);
            $State = IS_ACTIVE;
            $Result = $this->SocketSend($this->EncryptMessage($Payload), $State);
            if ($Retry && ($State == \Xiaomi\Device\ApiError::ChecksumError) && $this->RefreshToken()) {
                // Token hat sich geändert (z.B. Gerät neu angelernt): einmal mit neuem Token wiederholen
                $Retry = false;
                continue;
            }
            if ($Retry && ($State == \Xiaomi\Device\ApiError::DeviceBusy)) {
                // Gerät beschäftigt: einmal nach kurzer Pause wiederholen
                $this->SendDebug('Retry', 'device busy', 0);
                $Retry = false;
                usleep(\Xiaomi\Device\LocalProtocol::BusyRetryDelay);
                continue;
            }
            break;
        }
        if ($State == \Xiaomi\Device\ApiError::DeviceBusy) {
            echo sprintf($this->Translate('Device error %d: %s'), \Xiaomi\Device\ApiError::DeviceBusy, $this->GetErrorText(\Xiaomi\Device\ApiError::$CodeToText, \Xiaomi\Device\ApiError::DeviceBusy)) . PHP_EOL;
            $this->SetStatus(\Xiaomi\Device\InstanceStatus::ApiError);
        }
        if ($State == \Xiaomi\Device\InstanceStatus::TimeoutError) {
            // Eine verspätete Antwort darf nicht zur nächsten Anfrage passen (wie python-miio).
            $this->MessageId = ($this->MessageId + \Xiaomi\Device\LocalProtocol::MessageIdSkip) % \Xiaomi\Device\LocalProtocol::MaxMessageId;
            if ($this->ReadPropertyBoolean(\Xiaomi\Device\Property::DeniedCloud)) { // und verboten
                $this->SetStatus(\Xiaomi\Device\InstanceStatus::TimeoutError);
            }
        }
        $this->UnlockLocal();
        return $Result;
    }

    /**
     * NextMessageId
     *
     * Fortlaufende Nachrichten-ID für das lokale Protokoll (1 … MaxMessageId).
     *
     * @return int
     */
    private function NextMessageId(): int
    {
        $Id = $this->MessageId + 1;
        if ($Id > \Xiaomi\Device\LocalProtocol::MaxMessageId) {
            $Id = 1;
        }
        $this->MessageId = $Id;
        return $Id;
    }

    /**
     * LockLocal
     *
     * Nur eine lokale Anfrage je Instanz gleichzeitig. Wartet höchstens LockWait ms auf eine laufende Anfrage.
     *
     * @param  bool $Waited true, wenn auf eine laufende Anfrage gewartet wurde
     * @return bool
     */
    private function LockLocal(bool &$Waited): bool
    {
        $Name = __CLASS__ . '.' . (string) $this->InstanceID . '.SendLocal';
        if (IPS_SemaphoreEnter($Name, 1)) {
            return true;
        }
        $Waited = true;
        return IPS_SemaphoreEnter($Name, \Xiaomi\Device\LocalProtocol::LockWait);
    }

    /**
     * UnlockLocal
     *
     * @return void
     */
    private function UnlockLocal(): void
    {
        IPS_SemaphoreLeave(__CLASS__ . '.' . (string) $this->InstanceID . '.SendLocal');
    }

    /**
     * SendCloud
     *
     * @param  string $Uri
     * @param  string $Params
     * @return ?array
     */
    private function SendCloud(string $Uri, string $Params): ?array
    {
        $this->SendDebug('Cloud Request Uri', $Uri, 0);
        $this->SendDebug('Cloud Request Data', $Params, 0);
        $Response = $this->SendDataToParent(\Xiaomi\Cloud\ForwardData::ToJson($Uri, $Params));
        $this->SendDebug('Cloud Response', \Xiaomi\Sensitive::mask((string) $Response), 0);
        if (($Response == '') || ($Response == false)) {
            return null;
        }
        $Result = json_decode($Response, true);
        if ($Result === null) {
            return null;
        }
        $Code = (int) ($Result['code'] ?? 0);
        if ($Code != 0) {
            echo $this->GetErrorText(\Xiaomi\Cloud\ApiError::$CodeToText, $Code) . PHP_EOL;
            return null;
        }
        return $Result['result'] ?? null;
    }

    /**
     * GetErrorText
     *
     * Liefert den übersetzten Text zu einem Fehlercode, auch für unbekannte Codes.
     *
     * @param  array $CodeToText Zuordnung Fehlercode => Text
     * @param  int $Code Fehlercode aus der Antwort von Gerät oder Cloud
     * @return string
     */
    private function GetErrorText(array $CodeToText, int $Code): string
    {
        if (array_key_exists($Code, $CodeToText)) {
            return $this->Translate($CodeToText[$Code]);
        }
        return $this->Translate('Unknown error: ') . $Code;
    }

    /**
     * WriteValue
     *
     * @param  int $ServiceId
     * @param  int $PropertyId
     * @param  mixed $Value
     * @return bool
     */
    private function WriteValue(int $ServiceId, int $PropertyId, $Value): bool
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            echo $this->Translate('Instance is not active') . PHP_EOL;
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
        // Fehlercodes sind negativ, 0 = ok, 1 = angenommen (wird ausgeführt).
        $Code = (int) ($Result[0]['code'] ?? 0);
        if ($Code < 0) {
            echo $this->GetErrorText(\Xiaomi\Device\ApiError::$CodeToText, $Code) . PHP_EOL;
            return false;
        }
        $Ident = \Xiaomi\IdentPrefix::Property . '_' . (string) $ServiceId . '_' . (string) $PropertyId; // . '_' . $Property['prop'];
        if (@$this->GetIDForIdent($Ident)) {
            $this->SetValue($Ident, $Value);
        }
        return true;
    }

    /**
     * GetToken
     *
     * @return bool
     */
    private function GetToken(): bool
    {
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
        if (empty($Result['list'][0]['token'])) {
            $this->SendDebug(__FUNCTION__, 'no token in result', 0);
            return false;
        }
        $this->WriteAttributeString(\Xiaomi\Device\Attribute::Token, $Result['list'][0]['token']);
        return true;
    }

    /**
     * RefreshToken
     *
     * Holt nach einem Prüfsummenfehler den Token erneut aus der Cloud.
     *
     * @return bool true, wenn ein neuer (geänderter) Token geladen wurde
     */
    private function RefreshToken(): bool
    {
        if (!$this->HasActiveParent()) {
            return false;
        }
        $OldToken = $this->ReadAttributeString(\Xiaomi\Device\Attribute::Token);
        if (!$this->GetToken()) {
            return false;
        }
        if ($this->ReadAttributeString(\Xiaomi\Device\Attribute::Token) === $OldToken) {
            $this->SendDebug(__FUNCTION__, 'token unchanged', 0);
            return false;
        }
        $this->LogMessage($this->Translate('Device token has changed and was reloaded from the cloud'), KL_MESSAGE);
        return true;
    }

    /**
     * GetPropertiesParams
     *
     * @return array
     */
    private function GetPropertiesParams(): array
    {
        $PropList = [];
        $Specs = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Specs);
        if (!isset($Specs['services'])) {
            return [];
        }
        foreach ($Specs['services'] as $Service) {
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

    /**
     * GetVariableData
     *
     * @param  int $Siid
     * @param  array $Property
     * @param  mixed $Locales
     * @return array
     */
    private function GetVariableData(int $Siid, array $Property, string $ProfileSuffix, &$Locales): array
    {
        $Piid = $Property['iid'];
        $IpsVarType = \Xiaomi\Convert::ToIPSVar($Property['format']);
        $Profile = \Xiaomi\Convert::getProfileName($Piid, $Property['type'], $ProfileSuffix);
        $this->SendDebug(__FUNCTION__, $Profile, 0);
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
        if (array_key_exists('valueList', $Property)) {
            foreach ($Property['valueList'] as $Index => $Values) {
                $LocaleKey = $Values['i18nKey']; //sprintf('service:%03d:property:%03d:valuelist:%03d', $Siid, $Piid, $Index);
                if (array_key_exists($LocaleKey, $Locales)) {
                    $Values['description'] = $Locales[$LocaleKey];
                } else {
                    $Values['description'] = \Xiaomi\Translate::getLocaleName($Values['description']);
                }
                $Assoziation[] = [$Values['value'], $Values['description'], '', -1];
            }
        }
        if (isset($Property['valueRange'])) {
            list($Min, $Max, $Step) = $Property['valueRange'];
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

    /**
     * CreateStateVariables
     *
     * @return void
     */
    private function CreateStateVariables(): void
    {
        $Specs = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Specs);
        if (!isset($Specs['urn'])) {
            return;
        }
        $ProfileSuffix = array_reverse(explode(':', $Specs['urn']))[1];
        $Locales = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Locales);
        $DisabledStateVariables = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::LockedStateVariables);
        $this->SendDebug('Specs', json_encode($Specs), 0);
        $ActionsIdentsWithValue = [];
        $ActionsIdents = [];
        $ParamIdentsWrite = [];
        $ParamIdentsRead = [];
        $Pos = 0;
        foreach ($Specs['services'] as $Service) {
            $ServiceName = $Service['description'];
            $ServiceKey = $Service['i18nKey'];
            if (array_key_exists($ServiceKey, $Locales)) {
                $ServiceName = $Locales[$ServiceKey];
            }

            if (array_key_exists('properties', $Service)) {
                foreach ($Service['properties'] as $Property) {
                    if (!in_array('read', $Property['access']) && !in_array('write', $Property['access'])) {
                        continue;
                    }
                    $Ident = \Xiaomi\IdentPrefix::Property . '_' . (string) $Service['iid'] . '_' . (string) $Property['iid']; // . '_' . $Property['prop'];
                    $PropertyName = $Property['description'];
                    $PropertyKey = sprintf('service:%03d:property:%03d', $Service['iid'], $Property['iid']);
                    if (array_key_exists($PropertyKey, $Locales)) {
                        $PropertyName = $Locales[$PropertyKey];
                    } else {
                        $PropertyName = \Xiaomi\Translate::getLocaleName($PropertyName);
                    }
                    $Name = $ServiceName . ': ' . $PropertyName;
                    if (!in_array($Ident, $DisabledStateVariables)) {
                        list($IpsVarType, $Profile) = $this->GetVariableData($Service['iid'], $Property, $ProfileSuffix, $Locales);
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
                    if (count($Action['in']) > 1) { // Aktionen mit mehr als einen Parameter sind nur per Script erreichbar
                        continue;
                    }
                    $Ident = \Xiaomi\IdentPrefix::Action . '_' . (string) $Service['iid'] . '_' . (string) $Action['iid'];
                    if (in_array($Ident, $DisabledStateVariables)) {
                        continue;
                    }
                    $ActionName = $Action['description'];
                    $ActionKey = sprintf('service:%03d:action:%03d', $Service['iid'], $Action['iid']);
                    if (array_key_exists($ActionKey, $Locales)) {
                        $ActionName = $Locales[$ActionKey];
                    } else {
                        $ActionName = \Xiaomi\Translate::getLocaleName($ActionName);
                    }
                    $Name = $ServiceName . ': ' . $ActionName;
                    if (count($Action['in'])) { // ein Parameter -> Variable mit passendem Profil
                        $PropertyKey = array_search($Action['in'][0], array_column($Service['properties'], 'iid'));
                        if (!in_array($Ident, $DisabledStateVariables)) {
                            list($IpsVarType, $Profile) = $this->GetVariableData($Service['iid'], $Service['properties'][$PropertyKey], $ProfileSuffix, $Locales);
                            $this->MaintainVariable($Ident, $Name, $IpsVarType, $Profile, $Pos++, true);
                            // Aktionen haben keinen Zustand: neutralen Wert passend zum Variablentyp setzen
                            switch ($IpsVarType) {
                                case VARIABLETYPE_BOOLEAN:
                                    $this->SetValue($Ident, false);
                                    break;
                                case VARIABLETYPE_STRING:
                                    $this->SetValue($Ident, '');
                                    break;
                                default:
                                    $this->SetValue($Ident, -1);
                                    break;
                            }
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

    /**
     * SocketSend
     *
     * @param  string $Data
     * @param  int $State
     * @param  bool $Retry
     * @param  int $Timeout Antwortzeit in Sekunden
     * @return ?array
     */
    private function SocketSend(string $Data, int &$State = IS_ACTIVE, bool $Retry = true, int $Timeout = \Xiaomi\Device\LocalProtocol::ReceiveTimeout): ?array
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
        socket_set_option($this->Socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $Timeout, 'usec' => 0]);
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
                if (($DecodeError == \Xiaomi\Device\InstanceStatus::DidNotMatch) || ($DecodeError == \Xiaomi\Device\ApiError::ChecksumError)) {
                    $State = $DecodeError;
                }
                return null;
            }
            if ($JSONResult === '') { //handshake
                return [];
            }
            $this->SendDebug('Receive', \Xiaomi\Sensitive::mask($JSONResult), 0);
            $Result = json_decode(trim($JSONResult), true);
            if (!is_array($Result)) {
                $this->SendDebug('Receive', 'invalid JSON', 0);
                return null;
            }
            if (array_key_exists('error', $Result)) {
                if ($Result['error']['code'] == \Xiaomi\Device\ApiError::DeviceBusy) {
                    // Wiederholung und Meldung übernimmt SendLocal
                    $State = \Xiaomi\Device\ApiError::DeviceBusy;
                    return null;
                }
                if ((($Result['error']['code'] == -32601) || ($Result['error']['code'] == -9999)) && !$this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud)) {
                    if ($this->ReadPropertyBoolean(\Xiaomi\Device\Property::DeniedCloud)) { // und cloud verboten
                        echo sprintf($this->Translate('Device error %d: %s'), (int) $Result['error']['code'], (string) ($Result['error']['message'] ?? '')) . PHP_EOL;
                        $this->SetStatus(\Xiaomi\Device\InstanceStatus::ApiError);
                        $State = \Xiaomi\Device\InstanceStatus::ApiError;
                    } else { // cloud erlaubt
                        $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, true);
                    }
                } else {
                    echo sprintf($this->Translate('Device error %d: %s'), (int) $Result['error']['code'], (string) ($Result['error']['message'] ?? '')) . PHP_EOL;
                    $this->SetStatus(\Xiaomi\Device\InstanceStatus::ApiError);
                    $State = \Xiaomi\Device\InstanceStatus::ApiError;
                }
                return null;
            }
            if (array_key_exists('params', $Result)) {
                if ($this->ReadPropertyBoolean(\Xiaomi\Device\Property::DeniedCloud)) { // und cloud verboten
                    $this->SendDebug('Receive', 'unexpected params: ' . json_encode($Result['params']), 0);
                    echo $this->Translate('Unexpected response from device') . PHP_EOL;
                    $this->SetStatus(\Xiaomi\Device\InstanceStatus::ApiError);
                    $State = \Xiaomi\Device\InstanceStatus::ApiError;
                } else {
                    $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, true);
                }
                return null;
            }
            return $Result['result'] ?? null;
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

    /**
     * GetModelData
     *
     * @return bool
     */
    private function GetModelData(): bool
    {
        // Info Paket laden
        $Result = $this->SendLocal(\Xiaomi\Device\ApiMethod::Info);
        $this->SendDebug('GetModelData', \Xiaomi\Sensitive::mask($Result), 0);
        if (is_null($Result)) {
            $this->SendDebug('Error get model', '', 0);
            return false;
        }
        $OldSpecs = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Info);
        $OldModel = array_key_exists('model', $OldSpecs) ? $OldSpecs['model'] : 'invalid';
        $Specs = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Specs);
        if (!isset($Specs['urn'])) {
            $OldModel = 'invalid';
        }
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Info, $Result);
        $this->SendDebug('Model loaded', $Result['model'], 0);
        $this->SetSummary($this->ReadPropertyString(\Xiaomi\Device\Property::Host) . ' (' . $Result['model'] . ')');
        // wenn das Attribute schon vorhanden ist brauchen wir vielleicht nicht neu laden
        // Fallback von Versionen wo das Attribute fehlte
        if (count($this->ReadAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsRead))) {
            // Wenn model nicht geändert alles okay
            if ($Result['model'] == $OldModel) {
                $this->SendDebug('Model not changed', $OldModel, 0);
                return true;
            }
        }
        $this->SendDebug('Model changed (old)', $OldModel, 0);
        $this->SendDebug('Model changed', 'Load specs...', 0);
        $this->LogMessage('Load specs...', KL_NOTIFY);
        // Fähigkeiten neu laden
        /**
         * @todo Kaputt weil die Seite offline ist :(
         */
        $Data = @Sys_GetURLContentEx(\Xiaomi\Device\SpecUrls::Device . $Result['model'], ['Timeout'=>15000]);
        if (!$Data) {
            $this->SendDebug('ERROR load SpecsDom', \Xiaomi\Device\SpecUrls::Device . $Result['model'], 0);
            $this->LogMessage('ERROR load SpecsDom ' . \Xiaomi\Device\SpecUrls::Device . $Result['model'], KL_ERROR);
            return false;
        }
        $this->SendDebug('Get SpecsDom', $Data, 0);
        $HtmlDoc = Dom\HTMLDocument::createFromString($Data);
        $xpath = new Dom\XPath($HtmlDoc);
        $AppDataNodes = $xpath->query('//*[@data-page="app"]', null, false);
        if ($AppDataNodes->length != 1) {
            $this->SendDebug('Error get model node', $AppDataNodes->length, 0);
            $this->LogMessage('Error get model node', KL_ERROR);
            return false;
        }
        $JsonSpecs = $AppDataNodes->item(0)->textContent;
        $Specs = json_decode($JsonSpecs, true);
        // services/property in AttributeArray schreiben
        $this->SendDebug('Get Specs', $JsonSpecs, 0);

        $IconURLs = array_intersect_key($Specs['props']['product'], array_flip(['icon', 'icon_real', 'icon_on', 'icon_off']));
        foreach ($IconURLs as $IconURL) {
            if ($IconURL) {
                $IconRaw = @Sys_GetURLContentEx($IconURL, ['Timeout'=>5000]);
                $this->WriteAttributeString(\Xiaomi\Device\Attribute::Icon, base64_encode($IconRaw));
                break;
            }
        }

        $this->SendDebug('Model name', $Specs['props']['product']['name'], 0);
        $this->SendDebug('Model specs', json_encode($Specs['props']['tree']), 0);
        $this->WriteAttributeString(\Xiaomi\Device\Attribute::ProductName, $Specs['props']['product']['name']);
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Specs, array_merge($Specs['props']['tree'], $Specs['props']['spec']));
        $this->writeLocale($Specs['props']['i18n']);
        $this->LogMessage('Load specs...finished', KL_NOTIFY);
        return true;
    }

    /**
     * writeLocale
     *
     * @param  array $Data
     * @return bool
     */
    private function writeLocale(array $Data): bool
    {
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Locales, []);
        $locale = explode('_', IPS_GetSystemLanguage())[0];
        if (array_key_exists($locale, $Data)) {
            $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Locales, array_merge($Data['en'], $Data[$locale]));
        } else {
            $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Locales, $Data['en']);
        }
        return true;
    }

    /**
     * SendHandshake
     *
     * @return bool
     */
    private function SendHandshake(): bool
    {
        $Data = hex2bin('21310020ffffffffffffffffffffffffffffffffffffffffffffffffffffffff');
        $this->SendDebug('Send Handshake', $Data, 1);
        $State = IS_ACTIVE;
        $Result = $this->SocketSend($Data, $State, false, \Xiaomi\Device\LocalProtocol::HandshakeTimeout);
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

    /**
     * EncryptMessage
     *
     * @param  string $data
     * @return string
     */
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

    /**
     * DecryptMessage
     *
     * @param  string $msg
     * @param  int $DecodeError
     * @return ?string
     */
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
        if ($Data === false) {
            $this->SendDebug('Error on decrypt', 'decryption failed', 0);
            $DecodeError = \Xiaomi\Device\ApiError::PaketError;
            return null;
        }
        return $Data;
    }

    /**
     * GetKeyAndIV
     *
     * @return array
     */
    private function GetKeyAndIV(): array
    {
        $token = hex2bin($this->ReadAttributeString(\Xiaomi\Device\Attribute::Token));
        $TokenKey = md5($token, true);
        $TokenIV = md5($TokenKey . $token, true);
        return [$TokenKey, $TokenIV];
    }
}
