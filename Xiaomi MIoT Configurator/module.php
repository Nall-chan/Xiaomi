<?php

declare(strict_types=1);

eval('declare(strict_types=1);namespace XiaomiConfigurator {?>' . file_get_contents(__DIR__ . '/../libs/helper/DebugHelper.php') . '}');
require_once dirname(__DIR__) . '/libs/XiaomiConsts.php';

/**
 * Konfigurator: Listet die Geräte des verbundenen Cloud-Accounts und legt Geräte-Instanzen an.
 *
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 */
class XiaomiMIoTConfigurator extends IPSModule
{
    use \XiaomiConfigurator\DebugHelper;

    /**
     * Create
     *
     * @return void
     */
    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->RequireParent(\Xiaomi\GUID::CloudIO);
        $this->RegisterAttributeBoolean(\Xiaomi\Configurator\Attribute::ShowOffline, true);
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
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->UpdateSummary();
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
        switch ($Message) {
            case FM_CONNECT:
            case FM_DISCONNECT:
                $this->UpdateSummary();
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
            case \Xiaomi\Configurator\Attribute::ShowOffline:
                $this->WriteAttributeBoolean(\Xiaomi\Configurator\Attribute::ShowOffline, (bool) $Value);
                $this->ReloadForm();
                return;
        }
        echo $this->Translate('Invalid Ident') . PHP_EOL;
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
        $this->UpdateSummary();
        $IOId = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $IOActive = ($IOId > 1) && (IPS_GetInstance($IOId)['InstanceStatus'] == IS_ACTIVE);
        // Hinweis, wenn keine aktive Cloud IO verbunden ist
        $Form['actions'][0]['visible'] = !$IOActive;

        $ShowOffline = $this->ReadAttributeBoolean(\Xiaomi\Configurator\Attribute::ShowOffline);
        // Nur Geräte-Instanzen an derselben Cloud IO berücksichtigen
        $InstanceIDList = $this->GetInstanceList($IOId);
        $Devices = $IOActive ? $this->GetDevices() : [];
        $DeviceValues = [];
        foreach ($Devices as $Device) {
            if (empty($Device['localip'])) {
                continue;
            }
            //we skip all Gateways...
            if (strpos((string) ($Device['spec_type'] ?? ''), \Xiaomi\SpecTypes::Gateway) === 0) {
                continue;
            }
            $AddDevice = [
                'IPAddress'              => $Device['localip'],
                'MAC'                    => $Device['mac'] ?? '',
                'Model'                  => $Device['model'] ?? '',
                'name'                   => $Device['name'] ?? ''
            ];
            $InstanceIdDevice = array_search($Device['did'], $InstanceIDList);
            if ($InstanceIdDevice !== false) {
                $AddDevice['name'] = IPS_GetName($InstanceIdDevice);
                $AddDevice['instanceID'] = $InstanceIdDevice;
                $AddDevice['host'] = $Device['localip'];
                unset($InstanceIDList[$InstanceIdDevice]);
            }
            // Erst hier auf offline filtern, damit offline Instanzen nicht rot angezeigt werden.
            if (empty($Device['isOnline']) && !$ShowOffline) {
                continue;
            }
            $AddDevice['create'] = [
                'moduleID'      => \Xiaomi\GUID::MiDevice,
                'configuration' => [
                    \Xiaomi\Device\Property::Active         => (bool) ($Device['isOnline'] ?? false),
                    \Xiaomi\Device\Property::Host           => $Device['localip'],
                    \Xiaomi\Device\Property::DeviceId       => (string) $Device['did']
                ]
            ];
            $DeviceValues[] = $AddDevice;
        }
        // Vorhandene Instanzen, die (aktuell) nicht in der Cloud gefunden wurden
        foreach ($InstanceIDList as $InstanceIdDevice => $DID) {
            $DeviceValues[] = [
                'instanceID'             => $InstanceIdDevice,
                'IPAddress'              => IPS_GetProperty($InstanceIdDevice, \Xiaomi\Device\Property::Host),
                'MAC'                    => '',
                'Model'                  => '',
                'name'                   => IPS_GetName($InstanceIdDevice)
            ];
        }
        $Form['actions'][1]['items'][0]['value'] = $ShowOffline;
        $Form['actions'][2]['values'] = $DeviceValues;
        $this->SendDebug('FORM', \Xiaomi\Sensitive::mask(json_encode($Form)), 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);
        return json_encode($Form);
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
     * @return array
     */
    private function GetDevices(): array
    {
        $this->SendDebug(__FUNCTION__, \Xiaomi\Cloud\ApiUrl::Device_List, 0);
        $Request = json_encode([
            'getVirtualModel'   => true,
            'getHuamiDevices'   => 1,
            'get_split_device'  => true,
            'support_smart_home'=> true,
            'get_cariot_device' => true
        ]);
        $Result = $this->SendDataToParent(\Xiaomi\Cloud\ForwardData::ToJson(\Xiaomi\Cloud\ApiUrl::Device_List, $Request));
        $this->SendDebug(__FUNCTION__, \Xiaomi\Sensitive::mask((string) $Result), 0);
        if (!$Result) {
            return [];
        }
        $Result = json_decode($Result, true);
        if (($Result['code'] ?? -1) != 0) {
            return [];
        }
        return $Result['result']['list'] ?? [];
    }

    /**
     * GetInstanceList
     *
     * Liefert alle Geräte-Instanzen an der angegebenen Cloud IO.
     *
     * @param  int $IOId ID der Cloud IO
     * @return array InstanzID => DeviceId
     */
    private function GetInstanceList(int $IOId): array
    {
        $InstanceIDList = [];
        foreach (IPS_GetInstanceListByModuleID(\Xiaomi\GUID::MiDevice) as $InstanceID) {
            if (IPS_GetInstance($InstanceID)['ConnectionID'] != $IOId) {
                continue;
            }
            $InstanceIDList[$InstanceID] = IPS_GetProperty($InstanceID, \Xiaomi\Device\Property::DeviceId);
        }
        $this->SendDebug('Filter', $InstanceIDList, 0);
        return $InstanceIDList;
    }
}
