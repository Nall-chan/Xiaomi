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
            case 'InstallRoborockModule':
                $this->UpdateFormField('AlertPopup', 'visible', false);
                $this->UpdateFormField('WaitPopup', 'visible', true);
                $this->InstallRoborockModule();
                $this->ReloadForm();
                return;
        }
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
        $RoborockModulAvailable = $this->isRoborockModuleInstalled();
        $ShowRoborockModuleHint = false;

        $ShowOffline = $this->ReadAttributeBoolean(\Xiaomi\Configurator\Attribute::ShowOffline);
        $DeviceValues = [];
        $InstanceIDList = $this->GetInstanceList(\Xiaomi\GUID::MiDevice, \Xiaomi\Device\Property::DeviceId);
        $RoborockInstanceIDList = [];
        if ($RoborockModulAvailable) {
            // todo $RoborockInstanceIDList = $this->GetInstanceList(\Xiaomi\Roborock\GUID::Device, \Xiaomi\Roborock\Property::Ip);
        }
        $Devices = [];
        if (IPS_GetInstance($this->InstanceID)['ConnectionID'] > 1) {
            $Devices = $this->GetDevices();

            // Filter auf gleichen IO
            $InstanceIDList = array_filter($InstanceIDList, function ($InstanceIdDevice)
            {
                return IPS_GetInstance($InstanceIdDevice)['ConnectionID'] == IPS_GetInstance($this->InstanceID)['ConnectionID'];
            }, ARRAY_FILTER_USE_KEY);

            // Filter auf gleichen Username der Cloud
            /* todo
            $RoborockInstanceIDList = array_filter($RoborockInstanceIDList, function ($InstanceIdDevice)
            {
                return IPS_GetProperty($InstanceIdDevice, \Xiaomi\Roborock\Property::User) == IPS_GetProperty(IPS_GetInstance($this->InstanceID)['ConnectionID'], \Xiaomi\Cloud\Property::Username);
            }, ARRAY_FILTER_USE_KEY);
             */
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
                /* todo
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
                            'location'      => ((float) IPS_GetKernelVersion() < 7) ? [$this->Translate('Roborocks')] : [],
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
                 */
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

    /**
     * GetConfigParam
     *
     * @param  mixed $item1
     * @param  int $InstanceID
     * @param  string $ConfigParam
     * @return void
     */
    protected function GetConfigParam(&$item1, int $InstanceID, string $ConfigParam): void
    {
        $item1 = IPS_GetProperty($InstanceID, $ConfigParam);
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
        $Result = $this->Request(\Xiaomi\Cloud\ApiUrl::Device_List, $Request);
        $this->SendDebug(__FUNCTION__, $Result, 0);
        if ($Result) {
            $Result = json_decode($Result, true);
            if ($Result['code'] == 0) {
                return $Result['result']['list'];
            }
        }
        return [];
    }

    /**
     * Request
     *
     * @param  string $Uri
     * @param  string $Params
     * @return ?string
     */
    private function Request(string $Uri, string $Params): ?string
    {
        $Result = $this->SendDataToParent(\Xiaomi\Cloud\ForwardData::ToJson($Uri, $Params));
        return ($Result == '') ? null : $Result;
    }

    /**
     * GetInstanceList
     *
     * @param  string $GUID
     * @param  string $ConfigParam
     * @return array
     */
    private function GetInstanceList(string $GUID, string $ConfigParam): array
    {
        $InstanceIDList = IPS_GetInstanceListByModuleID($GUID);
        $InstanceIDList = array_flip(array_values($InstanceIDList));
        array_walk($InstanceIDList, [$this, 'GetConfigParam'], $ConfigParam);
        $this->SendDebug('Filter', $InstanceIDList, 0);
        return $InstanceIDList;
    }

    /**
     * isRoborockModuleInstalled
     *
     * @return bool
     */
    private function isRoborockModuleInstalled(): bool
    {
        return IPS_LibraryExists(\Xiaomi\Roborock\GUID::Module);
    }

    /**
     * StoreAvailable
     *
     * @return bool
     */
    private function StoreAvailable(): bool
    {
        $Id = IPS_GetInstanceListByModuleID(\Xiaomi\Roborock\GUID::Store)[0];
        return SC_GetLastConfirmedStoreConditions($Id) == 3;
    }

    /**
     * InstallRoborockModule
     *
     * @return bool
     */
    private function InstallRoborockModule(): bool
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
