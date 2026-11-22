<?php

declare(strict_types=1);

eval('declare(strict_types=1);namespace XiaomiConfigurator {?>' . file_get_contents(__DIR__ . '/../libs/helper/DebugHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiConfigurator {?>' . file_get_contents(__DIR__ . '/../libs/helper/BufferHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiConfigurator {?>' . file_get_contents(__DIR__ . '/../libs/helper/ParentIOHelper.php') . '}');
require_once dirname(__DIR__) . '/libs/XiaomiConsts.php';

/**
 * Konfigurator: Listet die Geräte des verbundenen Cloud-Accounts und legt Geräte-Instanzen an.
 *
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 * @method int RegisterParent()
 * @method bool IORequestAction(string $Ident, mixed $Value)
 * @property int $ParentID Verbundene Cloud IO (ParentIOHelper)
 */
class XiaomiMIoTConfigurator extends IPSModuleStrict
{
    use \XiaomiConfigurator\DebugHelper;
    use \XiaomiConfigurator\BufferHelper;
    use \XiaomiConfigurator\InstanceStatus {
        \XiaomiConfigurator\InstanceStatus::MessageSink as IOMessageSink;
        \XiaomiConfigurator\InstanceStatus::RequestAction as IORequestAction;
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
        $this->ParentID = 0;
    }

    /**
     * Migrate
     *
     * Entfernt das Attribut ShowOffline (bis 2.0: Filter „Zeige offline Geräte“, jetzt Spalte Status).
     *
     * @param  string $JSONData
     * @return string
     */
    public function Migrate(string $JSONData): string
    {
        //Never delete this line!
        parent::Migrate($JSONData);
        $j = json_decode($JSONData);
        if (!isset($j->attributes) || !property_exists($j->attributes, \Xiaomi\Configurator\ObsoleteKeys::ShowOffline)) {
            // Leerer String = keine Änderung (SDK)
            return '';
        }
        unset($j->attributes->{\Xiaomi\Configurator\ObsoleteKeys::ShowOffline});
        return json_encode($j);
    }

    /**
     * GetCompatibleParents
     *
     * Ohne gültige Cloud IO `require` (neue Cloud IO anlegen, ersetzt RequireParent aus IPSModule),
     * mit vorhandener Cloud IO `connect`, damit beim Wechseln auch die bestehenden Cloud IOs angeboten werden.
     *
     * @return string
     */
    public function GetCompatibleParents(): string
    {
        $ParentId = @IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        return json_encode([
            'type'      => (($ParentId > 1) && IPS_InstanceExists($ParentId)) ? 'connect' : 'require',
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
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        // Statuswechsel der Cloud IO verfolgen (IM_CHANGESTATUS), siehe IOChangeState
        $this->RegisterParent();
        $this->UpdateSummary();
    }

    /**
     * MessageSink
     *
     * Verbinden/Trennen und Statuswechsel der Cloud IO wertet der ParentIOHelper aus und ruft IOChangeState.
     *
     * @param  int $TimeStamp
     * @param  int $SenderID
     * @param  int $Message
     * @param  array $Data
     * @return void
     */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        $this->IOMessageSink($TimeStamp, $SenderID, $Message, $Data);
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
        echo $this->Translate('Invalid Ident') . PHP_EOL;
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
        $this->UpdateSummary();
        $IOId = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $IOActive = ($IOId > 1) && (IPS_GetInstance($IOId)['InstanceStatus'] == IS_ACTIVE);
        // Hinweis, wenn keine aktive Cloud IO verbunden ist; mit verbundener IO zusätzlich ein Knopf, der sie öffnet
        $Form['actions'][0]['visible'] = !$IOActive;
        $Form['actions'][1]['visible'] = !$IOActive && ($IOId > 1);
        $Form['actions'][1]['objectID'] = ($IOId > 1) ? $IOId : 0;

        // Alle Geräte-Instanzen im System: InstanzID => [DeviceId, Cloud IO]
        $Instances = $this->GetInstanceList();
        $Devices = $IOActive ? $this->GetDevices() : [];
        $DeviceValues = [];
        foreach ($Devices as $Device) {
            //we skip all Gateways...
            if (strpos($Device[\Xiaomi\Cloud\DeviceKey::Urn], \Xiaomi\SpecTypes::Gateway) === 0) {
                continue;
            }
            $DeviceId = $Device[\Xiaomi\Cloud\DeviceKey::DeviceId];
            $LocalIp = $Device[\Xiaomi\Cloud\DeviceKey::LocalIp];
            $InstanceIdDevice = array_search($DeviceId, array_column($Instances, 'did', 'id'));
            // Instanz an einer anderen Cloud IO (z.B. Konto eines Familienmitglieds): hier nicht anzeigen
            if (($InstanceIdDevice !== false) && ($Instances[$InstanceIdDevice]['io'] != $IOId)) {
                unset($Instances[$InstanceIdDevice]);
                continue;
            }
            $Online = $Device[\Xiaomi\Cloud\DeviceKey::Online];
            $AddDevice = [
                'IPAddress' => ($LocalIp !== '') ? $LocalIp : $this->Translate('Cloud only'),
                'State'     => $this->Translate($Online ? 'Online' : 'Offline'),
                'Model'     => $Device[\Xiaomi\Cloud\DeviceKey::Model],
                'Origin'    => $Device[\Xiaomi\Cloud\DeviceKey::Shared] ? sprintf($this->Translate('Shared by %s'), $Device[\Xiaomi\Cloud\DeviceKey::Owner]) : $this->Translate('Own account'),
                // Name der Instanz, sonst der Name aus der Cloud
                'name'      => $Device[\Xiaomi\Cloud\DeviceKey::Name],
                'icons'     => ['State' => $Online ? \Xiaomi\Configurator\Icon::Online : \Xiaomi\Configurator\Icon::Offline]
            ];
            if ($InstanceIdDevice !== false) {
                $AddDevice['name'] = IPS_GetName($InstanceIdDevice);
                $AddDevice['instanceID'] = $InstanceIdDevice;
                unset($Instances[$InstanceIdDevice]);
            }
            $AddDevice['create'] = [
                'moduleID'      => \Xiaomi\GUID::MiDevice,
                'configuration' => [
                    \Xiaomi\Device\Property::Active     => $Device[\Xiaomi\Cloud\DeviceKey::Online],
                    \Xiaomi\Device\Property::Host       => $LocalIp,
                    \Xiaomi\Device\Property::DeviceId   => $DeviceId,
                    // Ohne lokale IP nur über die Cloud erreichbar
                    \Xiaomi\Device\Property::ForceCloud => ($LocalIp === '')
                ]
            ];
            $DeviceValues[] = $AddDevice;
        }
        // Vorhandene Instanzen an dieser Cloud IO, die (aktuell) nicht in der Cloud gefunden wurden
        foreach ($Instances as $InstanceIdDevice => $Instance) {
            if ($Instance['io'] != $IOId) {
                continue;
            }
            $DeviceValues[] = [
                'instanceID' => $InstanceIdDevice,
                'IPAddress'  => IPS_GetProperty($InstanceIdDevice, \Xiaomi\Device\Property::Host),
                'State'      => '',
                'Model'      => '',
                'Origin'     => '',
                'name'       => IPS_GetName($InstanceIdDevice)
            ];
        }
        $Form['actions'][2]['values'] = $DeviceValues;
        $this->SendDebug('FORM', \Xiaomi\Sensitive::mask(json_encode($Form)), 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);
        return json_encode($Form);
    }

    /**
     * IOChangeState
     *
     * Wechsel der Cloud IO oder ihres Status: Zusammenfassung und Formular (Hinweis, Knopf, Geräteliste) aktualisieren.
     *
     * @param  int $State Status der Cloud IO
     * @return void
     */
    protected function IOChangeState(int $State): void
    {
        $this->UpdateSummary();
        $this->ReloadForm();
    }

    /**
     * UpdateSummary
     *
     * Zeigt den Namen der verbundenen Cloud IO in der Zusammenfassung (Unterscheidung mehrerer Accounts).
     *
     * @return void
     */
    private function UpdateSummary(): void
    {
        $IOId = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $this->SetSummary(($IOId > 1) ? IPS_GetName($IOId) : '');
    }

    /**
     * GetDevices
     *
     * Geräteliste (eigene und freigegebene Geräte) von der Cloud IO, immer frisch geladen.
     *
     * @return array
     */
    private function GetDevices(): array
    {
        $Result = $this->SendDataToParent(\Xiaomi\Cloud\Command::ToJson(\Xiaomi\Cloud\Command::GetDeviceList, ['refresh' => true]));
        $Result = json_decode($Result, true);
        if (!is_array($Result) || !isset($Result['result'])) {
            $this->SendDebug(__FUNCTION__, 'no device list', 0);
            return [];
        }
        $this->SendDebug(__FUNCTION__, count($Result['result']) . ' devices', 0);
        return $Result['result'];
    }

    /**
     * GetInstanceList
     *
     * Liefert alle Geräte-Instanzen mit DeviceId und verbundener Cloud IO.
     *
     * @return array InstanzID => ['id' => InstanzID, 'did' => DeviceId, 'io' => Cloud IO]
     */
    private function GetInstanceList(): array
    {
        $Instances = [];
        foreach (IPS_GetInstanceListByModuleID(\Xiaomi\GUID::MiDevice) as $InstanceID) {
            $Instances[$InstanceID] = [
                'id'  => $InstanceID,
                'did' => (string) IPS_GetProperty($InstanceID, \Xiaomi\Device\Property::DeviceId),
                'io'  => IPS_GetInstance($InstanceID)['ConnectionID']
            ];
        }
        return $Instances;
    }
}
