<?php

declare(strict_types=1);

eval('declare(strict_types=1);namespace XiaomiConfigurator {?>' . file_get_contents(__DIR__ . '/../libs/helper/DebugHelper.php') . '}');
require_once dirname(__DIR__) . '/libs/XiaomiConsts.php';
/**
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 */
class XiaomiMIoTConfigurator extends IPSModule
{
    use \XiaomiConfigurator\DebugHelper;

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->RequireParent(\Xiaomi\GUID::CloudIO);
        $this->RegisterAttributeBoolean(\Xiaomi\Configurator\Attribute::ShowOffline, true);
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
    }
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case \Xiaomi\Configurator\Attribute::ShowOffline:
                $this->WriteAttributeBoolean(\Xiaomi\Configurator\Attribute::ShowOffline, (bool) $Value);
                $this->ReloadForm();
                return;
            case 'InstallRoborockModule':
                $this->UpdateFormField('AlertPopup', 'visible', false);
                $this->UpdateFormField('WaitPopup', 'visible', true);
                $this->InstallRoborockModule();
                $this->ReloadForm();
                return;
        }
    }

    public function GetConfigurationForm()
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if ($this->GetStatus() == IS_CREATING) {
            return json_encode($Form);
        }
        $RoborockModulAvailable = $this->isRoborockModuleInstalled();
        $ShowRoborockModuleHint = false;
        $Devices = [];
        if ($this->HasActiveParent()) {
            $Devices = $this->GetDevices();
        }
        $ShowOffline = $this->ReadAttributeBoolean(\Xiaomi\Configurator\Attribute::ShowOffline);

        $DeviceValues = [];
        $InstanceIDList = $this->GetInstanceList(\Xiaomi\GUID::MiDevice, \Xiaomi\Device\Property::DeviceId);
        if ($RoborockModulAvailable) {
            $RoborockInstanceIDList = $this->GetInstanceList(\Xiaomi\Roborock\GUID::Device, \Xiaomi\Roborock\Property::Ip);
        }
        foreach ($Devices as $Device) {
            if (!array_key_exists('localip', $Device)) {
                continue;
            }
            if ($Device['localip'] == '') {
                continue;
            }
            //we skip all Gateways...
            if (strpos($Device['spec_type'], 'urn:miot-spec-v2:device:gateway') === 0) {
                continue;
            }

            $AddDevice = [
                'IPAddress'              => $Device['localip'],
                'MAC'                    => $Device['mac'],
                'Model'                  => $Device['model'],
                'name'                   => $Device['name']
            ];
            $InstanceIdDevice = array_search($Device['did'], $InstanceIDList);
            if ($InstanceIdDevice !== false) {
                $AddDevice['name'] = IPS_GetName($InstanceIdDevice);
                $AddDevice['instanceID'] = $InstanceIdDevice;
                $AddDevice['host'] = $Device['localip'];
                unset($InstanceIDList[$InstanceIdDevice]);
            }
            // Erst hier auf offline filtern, damit offline Instanzen nicht rot angezeigt werden.
            if (!$Device['isOnline'] && !$ShowOffline) {
                continue;
            }
            if (in_array($Device['model'], \Xiaomi\Roborock\Models::DEVICELIST)) { // supported model in Roborock-Modul
                if ($RoborockModulAvailable) {
                    $InstanceIdDevice = array_search($Device['localip'], $RoborockInstanceIDList);
                    if ($InstanceIdDevice !== false) {
                        $AddDevice['name'] = IPS_GetName($InstanceIdDevice);
                        $AddDevice['instanceID'] = $InstanceIdDevice;
                        $AddDevice['host'] = $Device['localip'];
                        unset($RoborockInstanceIDList[$InstanceIdDevice]);
                    }
                    $IOId = IPS_GetInstance($this->InstanceID)['ConnectionID'];
                    $AddDevice['create'] = [
                        'moduleID'      => \Xiaomi\Roborock\GUID::Device,
                        'location'      => [$this->Translate('Roborocks')],
                        'configuration' => [
                            \Xiaomi\Roborock\Property::Ip          => $Device['localip'],
                            \Xiaomi\Roborock\Property::Server      => IPS_GetProperty($IOId, \Xiaomi\Cloud\Property::Country),
                            \Xiaomi\Roborock\Property::User        => IPS_GetProperty($IOId, \Xiaomi\Cloud\Property::Username),
                            \Xiaomi\Roborock\Property::Password    => IPS_GetProperty($IOId, \Xiaomi\Cloud\Property::Password)
                        ]
                    ];
                    $DeviceValues[] = $AddDevice;
                    continue;
                } else {
                    $ShowRoborockModuleHint = true;
                }
            }
            $AddDevice['create'] = [
                'moduleID'      => \Xiaomi\GUID::MiDevice,
                'location'      => ((float) IPS_GetKernelVersion() < 7) ? [$this->Translate('Mi Home Devices')] : [],
                'configuration' => [
                    \Xiaomi\Device\Property::Active         => $Device['isOnline'],
                    \Xiaomi\Device\Property::Host           => $Device['localip'],
                    \Xiaomi\Device\Property::DeviceId       => $Device['did']
                ]
            ];
            $DeviceValues[] = $AddDevice;
        }
        foreach ($InstanceIDList as $InstanceIdDevice => $DID) {
            $AddDevice = [
                'instanceID'             => $InstanceIdDevice,
                'IPAddress'              => IPS_GetProperty($InstanceIdDevice, \Xiaomi\Device\Property::Host),
                'MAC'                    => '',
                'Model'                  => '',
                'name'                   => IPS_GetName($InstanceIdDevice)
            ];
            $DeviceValues[] = $AddDevice;
        }
        if ($RoborockModulAvailable) {
            foreach ($RoborockInstanceIDList as $InstanceIdDevice => $IPAddress) {
                $AddDevice = [
                    'instanceID'             => $InstanceIdDevice,
                    'IPAddress'              => $IPAddress,
                    'MAC'                    => '',
                    'Model'                  => '',
                    'name'                   => IPS_GetName($InstanceIdDevice)
                ];
                $DeviceValues[] = $AddDevice;
            }
        }
        if ($ShowRoborockModuleHint) {
            $Form['actions'][2]['visible'] = true;
            if (!$this->StoreAvailable()) {
                $Form['actions'][2]['popup']['buttons'] = [

                    [
                        'caption'=> 'Module-Store not available',
                        'enabled'=> false
                    ]
                ];
            }
        }
        $Form['actions'][0]['items'][0]['value'] = $ShowOffline;
        $Form['actions'][1]['values'] = $DeviceValues;
        $this->SendDebug('FORM', json_encode($Form), 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);
        return json_encode($Form);
    }
    protected function GetConfigParam(&$item1, int $InstanceID, string $ConfigParam): void
    {
        $item1 = IPS_GetProperty($InstanceID, $ConfigParam);
    }
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
        $Result = $this->Request(\Xiaomi\Cloud\ApiUrl::Device_List, $Request);
        $this->SendDebug(__FUNCTION__, $Result, 0);
        if ($Result) {
            return json_decode($Result, true)['result']['list'];
        }
        return [];
    }

    private function Request(string $Uri, string $Params): ?string
    {
        $Result = $this->SendDataToParent(\Xiaomi\Cloud\ForwardData::ToJson($Uri, $Params));
        return ($Result == '') ? null : $Result;
    }

    private function GetInstanceList(string $GUID, string $ConfigParam): array
    {
        $InstanceIDList = IPS_GetInstanceListByModuleID($GUID);
        $InstanceIDList = array_flip(array_values($InstanceIDList));
        array_walk($InstanceIDList, [$this, 'GetConfigParam'], $ConfigParam);
        $this->SendDebug('Filter', $InstanceIDList, 0);
        return $InstanceIDList;
    }
    private function isRoborockModuleInstalled(): bool
    {
        return IPS_LibraryExists(\Xiaomi\Roborock\GUID::Module);
    }
    private function StoreAvailable(): bool
    {
        $Id = IPS_GetInstanceListByModuleID(\Xiaomi\Roborock\GUID::Store)[0];
        return SC_GetLastConfirmedStoreConditions($Id) == 3;
    }
    private function InstallRoborockModule()
    {
        $Id = IPS_GetInstanceListByModuleID(\Xiaomi\Roborock\GUID::Store)[0];
        $Context = stream_context_create(\Xiaomi\Roborock\Store::$Opts);
        $Version = urlencode('{"version":"6.4","date":' . time() . '}');
        $Bundles = json_decode(file_get_contents('https://api.symcon.de/store/modules?language=de&search=roborock&compatibility=' . $Version, false, $Context), true);
        $Bundles = array_values(array_filter($Bundles, function ($item)
        {
            return $item['bundle'] == \Xiaomi\Roborock\Store::BundleId;
        }));
        $Module = [];
        foreach ($Bundles as $Channel) {
            $Module[$Channel['channel']] = $Channel;
        }
        if (array_key_exists('beta', $Module)) {
            $Install = $Module['beta'];
        } else {
            $Install = $Module['stable'];
        }
        return SC_InstallModule($Id, $Install['bundle'], 1, $Install['release']);
    }
}
