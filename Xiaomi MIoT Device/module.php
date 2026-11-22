<?php

declare(strict_types=1);
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/DebugHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/BufferHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/AttributeArrayHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/VariableProfileHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/ParentIOHelper.php') . '}');
require_once dirname(__DIR__) . '/libs/XiaomiConsts.php';
require_once dirname(__DIR__) . '/libs/MiioProtocol.php';
require_once dirname(__DIR__) . '/libs/MiotSpec.php';
require_once dirname(__DIR__) . '/libs/MiotPresentation.php';
require_once dirname(__DIR__) . '/libs/MiotTerms.php';

/**
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 * @method void RegisterAttributeArray(string $name, array $Value, int $Size = 0)
 * @method array ReadAttributeArray(string $name)
 * @method void WriteAttributeArray(string $name, mixed $value)
 * @method void UnregisterProfile(string $Name)
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
class XiaomiMIoTDevice extends IPSModuleStrict
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
     * Begriffe und Einheiten der App, nur während CreateStateVariables (libs/MiotTerms.php)
     *
     * @var array
     */
    private array $Terms = [];

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
    public function Create(): void
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
        $this->RegisterAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, false);
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::ActionIdentsWithValues, []);
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::ActionIdents, []);
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsRead, []);
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsWrite, []);
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::LockedStateVariables, []);
        $this->RegisterAttributeArray(\Xiaomi\Device\Attribute::ObsoleteProfiles, []);
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
    public function Migrate(string $JSONData): string
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
        // Ab 2.0 Darstellungen statt Profile: bisher genutzte XIAOMI.*-Profile der eigenen Variablen merken,
        // ApplyChanges entfernt sie, sobald keine Variable sie mehr nutzt (CleanupProfiles).
        $Profiles = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $ChildId) {
            if (IPS_VariableExists($ChildId)) {
                $Profile = IPS_GetVariable($ChildId)['VariableProfile'];
                if (str_starts_with($Profile, \Xiaomi\Cloud\ProfilePrefix::Name)) {
                    $Profiles[] = $Profile;
                }
            }
        }
        if (count($Profiles)) {
            $Profiles[] = 'XIAOMI.ExecuteAction';
            if (!isset($j->attributes)) {
                $j->attributes = new \stdClass();
            }
            $Known = json_decode((string) ($j->attributes->{\Xiaomi\Device\Attribute::ObsoleteProfiles} ?? '[]'), true);
            $j->attributes->{\Xiaomi\Device\Attribute::ObsoleteProfiles} = json_encode(array_values(array_unique(array_merge(is_array($Known) ? $Known : [], $Profiles))));
            $Changed = true;
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
     * GetCompatibleParents
     *
     * Geräte nutzen eine vorhandene oder neue Cloud IO (ersetzt ConnectParent, das es in IPSModuleStrict nicht gibt).
     *
     * @return string
     */
    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type'      => 'connect',
            'moduleIDs' => [\Xiaomi\GUID::CloudIO]
        ]);
    }

    /**
     * ApplyChanges
     *
     * @return void
     */
    public function ApplyChanges(): void
    {
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
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
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
    public function RequestAction(string $Ident, mixed $Value): void
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
                // Nach einer vorgezogenen Abfrage (ScheduleRefresh) wieder das eingestellte Intervall; im Fehlerzustand bleibt der Timer aus
                if ($this->GetStatus() == IS_ACTIVE) {
                    $this->SetTimerInterval(\Xiaomi\Device\Timer::RefreshState, $this->ReadPropertyInteger(\Xiaomi\Device\Property::RefreshInterval) * 1000);
                }
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
                // Gerätedaten und Beschreibung an der Cloud IO vorbei am Zwischenspeicher neu laden.
                // Alte Daten bleiben erhalten, bis die neuen erfolgreich geladen sind.
                if (!$this->HasActiveParent()) {
                    echo $this->Translate('Cloud IO is not active') . PHP_EOL;
                    return;
                }
                $this->WriteAttributeBoolean(\Xiaomi\Device\Attribute::useCloud, false);
                $this->LoadDeviceData(true);
                if (!$this->LoadSpec(true)) {
                    echo $this->Translate('Device description could not be loaded') . PHP_EOL;
                }
                $this->LoadProduct(true);
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
                                $this->SendAction((int) $ServiceId, (int) $PropertyOrActionId, []);
                                return;
                            }
                            if (array_key_exists($Ident, $this->ReadAttributeArray(\Xiaomi\Device\Attribute::ActionIdentsWithValues))) {
                                $this->SendAction((int) $ServiceId, (int) $PropertyOrActionId, [$Value]);
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
    public function GetConfigurationForm(): string
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if ($this->GetStatus() == IS_CREATING) {
            return json_encode($Form);
        }
        $Form['elements'][1]['items'][1]['items'][0]['enabled'] = !$this->ReadPropertyBoolean(\Xiaomi\Device\Property::DeniedCloud);
        // „Cloud verbieten“ nur mit lokaler IP möglich
        $Form['elements'][1]['items'][1]['items'][1]['enabled'] = !$this->ReadPropertyBoolean(\Xiaomi\Device\Property::ForceCloud) && ($this->ReadPropertyString(\Xiaomi\Device\Property::Host) !== '');
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
                'caption'   => $this->Translate('Product name: ') . $this->ReadAttributeString(\Xiaomi\Device\Attribute::ProductName)
            ],
            [
                'type'      => 'Label',
                'link'      => false,
                'caption'   => $this->Translate('Model: ') . (isset($Info['model']) ? $Info['model'] : '')
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
                'caption'   => 'URN: ' . ($Info['urn'] ?? '')
            ],
            [
                'type'      => 'Label',
                'link'      => true,
                'caption'   => $this->Translate('Specs: ') . (isset($Info['model']) ? \Xiaomi\Device\SpecUrls::Overview . $Info['model'] : '')
            ],
            [
                'type'      => 'Label',
                'bold'      => $this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud),
                'color'     => ($this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud) ? '0080C0' : ''),
                'caption'   => $this->Translate('Connection: ') . ($this->ReadAttributeBoolean(\Xiaomi\Device\Attribute::useCloud) ? 'Cloud' : $this->Translate('Local'))
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
     * CallAction
     *
     * Führt eine Aktion des Gerätes aus (ab 2.0, ersetzt ExecuteAction).
     *
     * @param  int $ServiceId
     * @param  int $ActionId
     * @param  array $Parameter
     * @return mixed false bei Fehler, sonst die Rückgabewerte der Aktion (out) als Array mit dem Kurztyp der Eigenschaft
     *               als Schlüssel (z.B. 'stream-address'), oder true, wenn das Gerät keine liefert
     */
    public function CallAction(int $ServiceId, int $ActionId, array $Parameter): mixed
    {
        $Out = $this->SendAction($ServiceId, $ActionId, $Parameter);
        if (!is_array($Out)) {
            return $Out;
        }
        return \Xiaomi\Spec\Normalizer::NamedOutput($this->ReadAttributeArray(\Xiaomi\Device\Attribute::Specs), $ServiceId, $ActionId, $Out);
    }

    /**
     * ExecuteAction
     *
     * Abgekündigt seit 2.0, entfällt frühestens mit 2.10. Ersatz: CallAction (liefert benannte Rückgabewerte).
     *
     * @param  int $ServiceId
     * @param  int $ActionId
     * @param  array $Parameter
     * @return mixed false bei Fehler, sonst die Rückgabewerte der Aktion (out) als Liste ohne Namen (wie bis 1.30),
     *               oder true, wenn das Gerät keine liefert
     */
    public function ExecuteAction(int $ServiceId, int $ActionId, array $Parameter): mixed
    {
        $this->LogMessage($this->Translate('XIAOMI_ExecuteAction is deprecated and will be removed in version 2.10 at the earliest. Please use XIAOMI_CallAction instead.'), KL_WARNING);
        return $this->SendAction($ServiceId, $ActionId, $Parameter);
    }

    /**
     * SetStatus
     *
     * @param  mixed $State
     * @return void
     */
    protected function SetStatus(int $State): bool
    {
        switch ($State) {
            case IS_ACTIVE:
                // „Wiederverbinden erfolgreich“ meldet RequestState nach der ersten erfolgreichen Abfrage.
                break;
            case \Xiaomi\Device\InstanceStatus::ConfigError:
                // Konfigurationsfehler: kein Wiederverbinden, keine Verbindungsmeldung
                $this->SetTimerInterval(\Xiaomi\Device\Timer::RefreshState, 0);
                $this->SetTimerInterval(\Xiaomi\Device\Timer::Reconnect, 0);
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
                if (!$this->ReadPropertyBoolean(\Xiaomi\Device\Property::Active)) { // Zwischen senden und empfangen wurde die Instanz auf inaktiv gesetzt
                    return false;
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
        return parent::SetStatus($State);
    }

    /**
     * Wird ausgeführt wenn sich der Status vom Parent ändert.
     */
    protected function IOChangeState(int $State): void
    {
        switch ($State) {
            case IS_ACTIVE:
                // Bereits verbundene Instanzen (z.B. lokal, schon in ApplyChanges verbunden) nicht erneut verbinden.
                // Läuft gerade ein Verbindungsaufbau, wird auf ihn gewartet und danach erneut geprüft.
                $this->InitConnection(true);
                break;
        }
    }

    /**
     * SendAction
     *
     * Sendet eine Aktion lokal oder über die Cloud und plant danach das Nachlesen der Werte.
     *
     * @param  int $ServiceId
     * @param  int $ActionId
     * @param  array $Parameter
     * @return array|bool false bei Fehler, true ohne Rückgabewerte, sonst die Rückgabewerte (out) als Liste
     */
    private function SendAction(int $ServiceId, int $ActionId, array $Parameter): array|bool
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
        $this->ScheduleRefresh();
        if (array_key_exists('out', $Result)) {
            return (array) $Result['out'];
        }
        return true;
    }

    /**
     * InitConnection
     *
     * Nur ein Verbindungsaufbau gleichzeitig: ApplyChanges und IOChangeState können sich überschneiden.
     * Ein zweiter Aufruf wartet auf den laufenden (nicht überspringen: endet der erste inaktiv, z.B. weil
     * die Cloud IO beim Reload noch nicht aktiv war, würde sonst niemand mehr verbinden).
     *
     * @param  bool $OnlyIfNotActive true = nach dem Warten nur verbinden, wenn die Instanz nicht aktiv ist
     * @return void
     */
    private function InitConnection(bool $OnlyIfNotActive = false): void
    {
        $Lock = __CLASS__ . '.' . (string) $this->InstanceID . '.InitConnection';
        if (!IPS_SemaphoreEnter($Lock, \Xiaomi\Device\LocalProtocol::ConnectWait)) {
            $this->SendDebug(__FUNCTION__, 'timeout waiting for running connect', 0);
            return;
        }
        try {
            if ($OnlyIfNotActive && ($this->GetStatus() == IS_ACTIVE)) {
                $this->SendDebug(__FUNCTION__, 'already connected', 0);
                return;
            }
            $this->Connect();
        } finally {
            IPS_SemaphoreLeave($Lock);
        }
    }

    /**
     * Connect
     *
     * Verbindungsaufbau: Gerätedaten und Beschreibung von der Cloud IO, Handshake, erste Abfrage.
     *
     * @return void
     */
    private function Connect(): void
    {
        $this->SetTimerInterval(\Xiaomi\Device\Timer::RefreshState, 0);
        $this->SetTimerInterval(\Xiaomi\Device\Timer::Reconnect, 0);
        $this->ServerStamp = 0;
        $this->ServerStampTime = 0;
        $this->Retries = 0;
        // Anzeige IP in der INFO Spalte
        $this->SetSummary($this->ReadPropertyString(\Xiaomi\Device\Property::Host));

        if (!$this->ReadPropertyBoolean(\Xiaomi\Device\Property::Active)) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        if (!$this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId)) {
            $this->SetStatus(\Xiaomi\Device\InstanceStatus::ConfigError);
            return;
        }
        // Die Cloud IO ist Pflicht (Gerätedaten, Token, Beschreibung). Ohne aktive IO warten, IOChangeState verbindet neu.
        if (!$this->HasActiveParent()) {
            $this->SendDebug(__FUNCTION__, 'waiting for active Cloud IO', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        $HasHost = ($this->ReadPropertyString(\Xiaomi\Device\Property::Host) !== '');
        // Ohne lokale IP nur über die Cloud
        $ForceCloud = $this->ReadPropertyBoolean(\Xiaomi\Device\Property::ForceCloud) || !$HasHost;
        $DeniedCloud = $this->ReadPropertyBoolean(\Xiaomi\Device\Property::DeniedCloud) && $HasHost;
        // Ist die Cloud gerade nicht erreichbar, mit den gespeicherten Daten weiterarbeiten
        $this->LoadDeviceData(false);
        if (!$ForceCloud && ($this->ReadAttributeString(\Xiaomi\Device\Attribute::Token) === '')) {
            $this->SetStatus(\Xiaomi\Device\InstanceStatus::GetTokenFailed);
            return;
        }
        if (!$this->LoadSpec(false)) {
            $this->SetStatus(\Xiaomi\Device\InstanceStatus::GetSpecsFailed);
            return;
        }
        $this->LoadProduct(false);
        if (!$ForceCloud) {
            if (!$this->SendHandshake()) {
                return;
            }
            $this->LoadLocalInfo();
        }
        $this->CreateStateVariables();
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
            $Id = $this->NextMessageId();
            $Payload = json_encode(
                [
                    'id'    => $Id,
                    'method'=> $Method,
                    'params'=> $Prams
                ]
            );
            $this->SendDebug('Send', $Payload, 0);
            $State = IS_ACTIVE;
            $Result = $this->SocketSend($this->EncryptMessage($Payload), $State, true, \Xiaomi\Device\LocalProtocol::ReceiveTimeout, $Id);
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
        $this->ScheduleRefresh();
        return true;
    }

    /**
     * ScheduleRefresh
     *
     * Zieht die nächste Abfrage auf RefreshAfterWrite ms vor, damit Folgeänderungen des Gerätes ankommen.
     * Mehrere Befehle kurz hintereinander starten den Timer neu und ergeben nur eine Abfrage.
     *
     * @return void
     */
    private function ScheduleRefresh(): void
    {
        $this->SetTimerInterval(\Xiaomi\Device\Timer::RefreshState, \Xiaomi\Device\Timer::RefreshAfterWrite);
    }

    /**
     * SendCommand
     *
     * Befehl an die Cloud IO (DataID SendCommandToCloud).
     *
     * @param  string $Command
     * @param  array $Params
     * @return ?array Inhalt von 'result', null bei Fehler
     */
    private function SendCommand(string $Command, array $Params): ?array
    {
        $Response = $this->SendDataToParent(\Xiaomi\Cloud\Command::ToJson($Command, $Params));
        $this->SendDebug('Command ' . $Command, \Xiaomi\Sensitive::mask($Response), 0);
        $Result = json_decode($Response, true);
        if (!is_array($Result) || !array_key_exists('result', $Result)) {
            return null;
        }
        return is_array($Result['result']) ? $Result['result'] : null;
    }

    /**
     * LoadDeviceData
     *
     * Modell, URN, Token und lokale IP aus der Geräteliste der Cloud IO. Ein geänderter Token wird übernommen.
     *
     * @param  bool $Refresh true = Geräteliste in der Cloud IO neu laden
     * @return bool false, wenn die Cloud IO keine Daten liefert (gespeicherte Daten bleiben)
     */
    private function LoadDeviceData(bool $Refresh): bool
    {
        $Device = $this->SendCommand(\Xiaomi\Cloud\Command::GetDevice, ['did' => $this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId), 'refresh' => $Refresh]);
        if (is_null($Device)) {
            return false;
        }
        $Token = (string) ($Device[\Xiaomi\Cloud\DeviceKey::Token] ?? '');
        if (($Token !== '') && ($Token !== $this->ReadAttributeString(\Xiaomi\Device\Attribute::Token))) {
            $this->WriteAttributeString(\Xiaomi\Device\Attribute::Token, $Token);
        }
        $Info = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Info);
        $Info['model'] = (string) ($Device[\Xiaomi\Cloud\DeviceKey::Model] ?? '');
        $Info['urn'] = (string) ($Device[\Xiaomi\Cloud\DeviceKey::Urn] ?? '');
        $Info['mac'] = (string) ($Device[\Xiaomi\Cloud\DeviceKey::Mac] ?? '');
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Info, $Info);
        $Host = $this->ReadPropertyString(\Xiaomi\Device\Property::Host);
        $this->SetSummary(($Host !== '') ? $Host . ' (' . $Info['model'] . ')' : $Info['model']);
        return true;
    }

    /**
     * LoadSpec
     *
     * Lädt die Gerätebeschreibung von der Cloud IO, wenn sie fehlt, veraltet ist (Schema/URN) oder erzwungen wird.
     * Die bisherige Beschreibung wird erst nach erfolgreichem Laden ersetzt.
     *
     * @param  bool $Force true = immer neu laden (auch am Zwischenspeicher der Cloud IO vorbei)
     * @return bool true, wenn eine nutzbare Beschreibung vorliegt
     */
    private function LoadSpec(bool $Force): bool
    {
        $Urn = (string) ($this->ReadAttributeArray(\Xiaomi\Device\Attribute::Info)['urn'] ?? '');
        $Specs = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Specs);
        $Usable = isset($Specs['services']);
        $Current = (($Specs[\Xiaomi\Spec\Key::Schema] ?? 0) == \Xiaomi\Spec\Normalizer::SchemaVersion) && (($Specs[\Xiaomi\Spec\Key::Urn] ?? '') === $Urn);
        if (($Current && !$Force) || ($Urn === '')) {
            return $Usable;
        }
        $NewSpecs = $this->SendCommand(\Xiaomi\Cloud\Command::GetSpec, ['urn' => $Urn, 'refresh' => $Force]);
        if (is_null($NewSpecs) || !isset($NewSpecs[\Xiaomi\Spec\Key::Services])) {
            $this->SendDebug(__FUNCTION__, 'device description not available', 0);
            return $Usable;
        }
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Specs, $NewSpecs);
        $this->LogMessage($this->Translate('Device description loaded'), KL_NOTIFY);
        return true;
    }

    /**
     * LoadProduct
     *
     * Produktname und Bild für das Formular aus dem Produktkatalog der Cloud IO. Optional, Fehler nur ins Debug.
     * Das Bild wird nur geladen, wenn sich seine Adresse geändert hat.
     *
     * @param  bool $Force true = auch laden, wenn schon ein Produktname vorhanden ist
     * @return void
     */
    private function LoadProduct(bool $Force): void
    {
        if (!$Force && ($this->ReadAttributeString(\Xiaomi\Device\Attribute::ProductName) !== '')) {
            return;
        }
        $Info = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Info);
        $Product = $this->SendCommand(\Xiaomi\Cloud\Command::GetProduct, ['model' => (string) ($Info['model'] ?? '')]);
        if (is_null($Product)) {
            $this->SendDebug(__FUNCTION__, 'product not in catalog', 0);
            return;
        }
        $this->WriteAttributeString(\Xiaomi\Device\Attribute::ProductName, (string) $Product['name']);
        $IconUrl = (string) $Product['icon'];
        if (($IconUrl !== '') && ($Force || ($IconUrl !== ($Info['icon'] ?? '')))) {
            $Icon = @Sys_GetURLContentEx($IconUrl, ['Timeout' => 5000]);
            if ($Icon) {
                $this->WriteAttributeString(\Xiaomi\Device\Attribute::Icon, base64_encode($Icon));
                $Info['icon'] = $IconUrl;
                $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Info, $Info);
            }
        }
    }

    /**
     * LoadLocalInfo
     *
     * miIO.info (Firmware, Hardware, miIO-Version) für die Anzeige im Formular. Optional, Fehler nur ins Debug.
     *
     * @return void
     */
    private function LoadLocalInfo(): void
    {
        $Result = $this->SendLocal(\Xiaomi\Device\ApiMethod::Info);
        if (!is_array($Result)) {
            $this->SendDebug(__FUNCTION__, 'no miIO.info', 0);
            return;
        }
        $Info = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Info);
        foreach (['miio_ver', 'fw_ver', 'hw_ver'] as $Key) {
            if (isset($Result[$Key])) {
                $Info[$Key] = $Result[$Key];
            }
        }
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::Info, $Info);
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
        if (!$this->LoadDeviceData(true)) {
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
            if (!array_key_exists('properties', $Service) || in_array($Service['type'] ?? '', \Xiaomi\Device\SkipService::Types)) {
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
     * Variablentyp und Profil einer Eigenschaft (Gerätebeschreibung im eigenen Format, libs/MiotSpec.php).
     *
     * @param  array $Property Eigenschaft
     * @param  bool $Writable Variable bekommt eine Aktion
     * @return array [Variablentyp, Darstellung]
     */
    private function GetVariableData(array $Property, bool $Writable): array
    {
        switch ($Property[\Xiaomi\Spec\Key::Unit]) {
            case '':
            case 'none':
            case 'rgb':
                $Suffix = '';
                break;
            case 'percentage':
                $Suffix = ' %';
                break;
            case 'arcdegrees':
                $Suffix = ' °';
                break;
            case 'kelvin':
                $Suffix = ' K';
                break;
            default:
                $Suffix = ' ' . \Xiaomi\Terms\Terms::Unit($this->Terms, $Property[\Xiaomi\Spec\Key::Unit]);
                break;
        }
        // Werte ohne Übersetzung der Spec über die Begriffstabelle
        foreach ($Property[\Xiaomi\Spec\Key::Values] as &$Value) {
            $Value[\Xiaomi\Spec\Key::Name] = \Xiaomi\Terms\Terms::Name($this->Terms, (string) $Value[\Xiaomi\Spec\Key::Name]);
        }
        unset($Value);
        return \Xiaomi\Presentation\Builder::ForProperty($Property, $Writable, $Suffix);
    }

    /**
     * ElementName
     *
     * Name aus der Gerätebeschreibung; ohne Übersetzung der Spec (Name = Beschreibung) über die Begriffstabelle.
     *
     * @param  array $Element Service, Eigenschaft oder Aktion
     * @return string
     */
    private function ElementName(array $Element): string
    {
        $Name = (string) $Element[\Xiaomi\Spec\Key::Name];
        // Ohne Übersetzung der Spec ist der Name die (lesbar gemachte) Beschreibung: dann die Begriffe der App versuchen
        if ($Name === \Xiaomi\Spec\Normalizer::Readable((string) $Element[\Xiaomi\Spec\Key::Description])) {
            return \Xiaomi\Terms\Terms::Name($this->Terms, $Name);
        }
        return $Name;
    }

    /**
     * CreateStateVariables
     *
     * @return void
     */
    private function CreateStateVariables(): void
    {
        $Specs = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::Specs);
        // Nur mit Beschreibung im aktuellen Format (alte Formate werden durch LoadSpec ersetzt)
        if (($Specs[\Xiaomi\Spec\Key::Schema] ?? 0) != \Xiaomi\Spec\Normalizer::SchemaVersion) {
            return;
        }
        // Begriffe der App für Namen ohne Übersetzung der Spec und für Einheiten (optional)
        $this->Terms = $this->SendCommand(\Xiaomi\Cloud\Command::GetTerms, []) ?? [];
        $DisabledStateVariables = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::LockedStateVariables);
        $ActionsIdentsWithValue = [];
        $ActionsIdents = [];
        $ParamIdentsWrite = [];
        $ParamIdentsRead = [];
        $Pos = 0;
        foreach ($Specs[\Xiaomi\Spec\Key::Services] as $Service) {
            if (in_array($Service[\Xiaomi\Spec\Key::Type], \Xiaomi\Device\SkipService::Types)) {
                continue;
            }
            $ServiceName = $this->ElementName($Service);
            $Siid = $Service[\Xiaomi\Spec\Key::Iid];
            $PropertiesByIid = array_column($Service[\Xiaomi\Spec\Key::Properties], null, \Xiaomi\Spec\Key::Iid);
            foreach ($Service[\Xiaomi\Spec\Key::Properties] as $Property) {
                $Access = $Property[\Xiaomi\Spec\Key::Access];
                if (!in_array('read', $Access) && !in_array('write', $Access)) {
                    continue;
                }
                $Ident = \Xiaomi\IdentPrefix::Property . '_' . (string) $Siid . '_' . (string) $Property[\Xiaomi\Spec\Key::Iid];
                $Name = $ServiceName . ': ' . $this->ElementName($Property);
                if (!in_array($Ident, $DisabledStateVariables)) {
                    [$IpsVarType, $Profile] = $this->GetVariableData($Property, in_array('write', $Access));
                    $this->MaintainVariable($Ident, $Name, $IpsVarType, $Profile, $Pos++, true);
                    if (in_array('write', $Access)) {
                        $this->EnableAction($Ident);
                    }
                }
                if (in_array('write', $Access)) {
                    $ParamIdentsWrite[$Ident] = $Name;
                }
                $ParamIdentsRead[$Ident] = $Name;
            }
            foreach ($Service[\Xiaomi\Spec\Key::Actions] as $Action) {
                $In = $Action[\Xiaomi\Spec\Key::In];
                if (count($In) > 1) { // Aktionen mit mehr als einen Parameter sind nur per Script erreichbar
                    continue;
                }
                $Ident = \Xiaomi\IdentPrefix::Action . '_' . (string) $Siid . '_' . (string) $Action[\Xiaomi\Spec\Key::Iid];
                if (in_array($Ident, $DisabledStateVariables)) {
                    continue;
                }
                $Name = $ServiceName . ': ' . $this->ElementName($Action);
                if (count($In)) { // ein Parameter -> Variable mit passendem Profil
                    if (!isset($PropertiesByIid[$In[0]])) {
                        continue;
                    }
                    [$IpsVarType, $Profile] = $this->GetVariableData($PropertiesByIid[$In[0]], true);
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
                    $ActionsIdentsWithValue[$Ident] = $Name;
                } else { // kein Parameter: Aufzählung mit einer Option „Ausführen“
                    [$IpsVarType, $Presentation] = \Xiaomi\Presentation\Builder::ForExecuteAction($this->Translate('Execute'));
                    $this->MaintainVariable($Ident, $Name, $IpsVarType, $Presentation, $Pos++, true);
                    $ActionsIdents[$Ident] = $Name;
                }
                $this->EnableAction($Ident);
            }
        }
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::ActionIdentsWithValues, $ActionsIdentsWithValue);
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::ActionIdents, $ActionsIdents);
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsRead, $ParamIdentsRead);
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::ParamIdentsWrite, $ParamIdentsWrite);
        $this->CleanupProfiles();
    }

    /**
     * CleanupProfiles
     *
     * Entfernt früher genutzte XIAOMI.*-Profile (Liste aus Migrate), sobald sie außerhalb dieser Instanz unbenutzt sind.
     * Noch genutzte bleiben in der Liste; die letzte umgestellte Instanz entfernt sie.
     *
     * @return void
     */
    private function CleanupProfiles(): void
    {
        $Profiles = $this->ReadAttributeArray(\Xiaomi\Device\Attribute::ObsoleteProfiles);
        if (count($Profiles) == 0) {
            return;
        }
        $Remaining = [];
        foreach ($Profiles as $Profile) {
            $this->UnregisterProfile($Profile);
            if (IPS_VariableProfileExists($Profile)) {
                $Remaining[] = $Profile;
            }
        }
        $this->SendDebug(__FUNCTION__, sprintf('%d removed, %d still in use', count($Profiles) - count($Remaining), count($Remaining)), 0);
        $this->WriteAttributeArray(\Xiaomi\Device\Attribute::ObsoleteProfiles, $Remaining);
    }

    /**
     * SocketSend
     *
     * @param  string $Data
     * @param  int $State
     * @param  bool $Retry
     * @param  int $Timeout Antwortzeit in Sekunden
     * @param  int $ExpectedId Nachrichten-ID der Anfrage (0 = nicht prüfen, z.B. Handshake)
     * @return ?array
     */
    private function SocketSend(string $Data, int &$State = IS_ACTIVE, bool $Retry = true, int $Timeout = \Xiaomi\Device\LocalProtocol::ReceiveTimeout, int $ExpectedId = 0): ?array
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
        // Verspätete Antworten früherer Anfragen (fremde ID) verwerfen und weiter auf die eigene warten
        for ($Late = 0; $Late <= \Xiaomi\Device\LocalProtocol::MaxLateAnswers; $Late++) {
            if (@socket_recvfrom($this->Socket, $Response, 4096, 0, $RemoteIp, $RemotePort) === false) {
                break;
            }
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
            if (($ExpectedId > 0) && isset($Result['id']) && ((int) $Result['id'] !== $ExpectedId)) {
                $this->SendDebug('Receive', sprintf('discard late answer (id %d, expected %d)', (int) $Result['id'], $ExpectedId), 0);
                continue;
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
                return $this->SocketSend($Data, $State, false, $Timeout, $ExpectedId);
            }
        }
        $State = \Xiaomi\Device\InstanceStatus::TimeoutError;
        return null;
    }

    /**
     * SendHandshake
     *
     * @return bool
     */
    private function SendHandshake(): bool
    {
        $Data = \Xiaomi\Miio\Packet::Handshake();
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
        // Ohne bekannten Gerätezeitstempel (vor dem ersten Handshake) 0xFFFFFFFF senden
        $Stamp = $this->ServerStampTime ? \Xiaomi\Miio\Packet::NextStamp($this->ServerStamp, time() - $this->ServerStampTime) : 0xFFFFFFFF;
        $Payload = \Xiaomi\Miio\Packet::Encode(
            $data,
            (string) hex2bin($this->ReadAttributeString(\Xiaomi\Device\Attribute::Token)),
            (int) $this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId),
            $Stamp
        );
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
        $Packet = \Xiaomi\Miio\Packet::Decode($msg, (string) hex2bin($this->ReadAttributeString(\Xiaomi\Device\Attribute::Token)));
        if ($Packet['error'] == \Xiaomi\Miio\Packet::ErrorPacket) {
            $this->SendDebug('Error on decrypt', 'invalid packet', 0);
            $DecodeError = \Xiaomi\Device\ApiError::PaketError;
            return null;
        }
        $this->SendDebug('Receive DeviceID', $Packet['deviceId'], 0);
        $this->SendDebug('Receive Stamp', $Packet['stamp'], 0);
        if ($Packet['stamp'] > 0) {
            $this->ServerStamp = $Packet['stamp'];
            $this->ServerStampTime = time();
        }
        if ($Packet['error'] == \Xiaomi\Miio\Packet::ErrorChecksum) {
            $this->SendDebug('Error in checksum', 'token does not match', 0);
            $DecodeError = \Xiaomi\Device\ApiError::ChecksumError;
            return null;
        }
        if ($Packet['payload'] === '') { // Handshake
            if ($this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId) == (string) $Packet['deviceId']) {
                return '';
            }
            $DecodeError = \Xiaomi\Device\InstanceStatus::DidNotMatch;
            return null;
        }
        return $Packet['payload'];
    }
}
