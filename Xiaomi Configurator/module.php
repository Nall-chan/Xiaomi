<?php

declare(strict_types=1);

eval('declare(strict_types=1);namespace XiaomiConfigurator {?>' . file_get_contents(__DIR__ . '/../libs/helper/DebugHelper.php') . '}');
require_once dirname(__DIR__) . '/libs/XiaomiConsts.php';
/**
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 */
class XiaomiConfigurator extends IPSModule
{
    use \XiaomiConfigurator\DebugHelper;

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->RequireParent(\Xiaomi\GUID::CloudIO);
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
    public function GetConfigurationForm()
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if ($this->GetStatus() == IS_CREATING) {
            return json_encode($Form);
        }
        $Devices = [];
        if ($this->HasActiveParent()) {
            $Devices = $this->GetDevices();
        }
        $DeviceValues = [];
        $InstanceIDListe = $this->GetInstanceList(\Xiaomi\GUID::MiDevice, 'Host');
        foreach ($Devices as $Device) {
            if (!array_key_exists('localip', $Device)) {
                continue;
            }
            if ($Device['localip'] == '') {
                continue;
            }
            //todo -> for now we skip all Gateways...
            if (strpos($Device['spec_type'], 'urn:miot-spec-v2:device:gateway') === 0) {
                continue;
            }
            //todo -> Checkbox in Actions für on/offline
            if (!$Device['isOnline']) {
                continue;
            }
            $AddDevice = [
                'IPAddress'              => $Device['localip'],
                'MAC'                    => $Device['mac'],
                'Model'                  => $Device['model'],
                'name'                   => $Device['name']
            ];
            $InstanceIdDevice = array_search($Device['localip'], $InstanceIDListe);
            if ($InstanceIdDevice !== false) {
                $AddDevice['name'] = IPS_GetName($InstanceIdDevice);
                $AddDevice['instanceID'] = $InstanceIdDevice;
                $AddDevice['host'] = $Device['localip'];
                unset($InstanceIDListe[$InstanceIdDevice]);
            }

            $AddDevice['create'] = [

                'moduleID'      => \Xiaomi\GUID::MiDevice,
                'location'      => [$this->Translate('Mi Home Devices')],
                'configuration' => [
                    \Xiaomi\Device\Property::Host     => $Device['localip']
                ]

            ];

            $DeviceValues[] = $AddDevice;
        }
        foreach ($InstanceIDListe as $InstanceIdDevice => $IPAddress) {
            $AddDevice = [
                'instanceID'             => $InstanceIdDevice,
                'IPAddress'              => $IPAddress,
                'MAC'                    => '',
                'Model'                  => '',
                'name'                   => IPS_GetName($InstanceIdDevice)
            ];
            $DeviceValues[] = $AddDevice;
        }
        $Form['actions'][0]['values'] = $DeviceValues;
        $this->SendDebug('FORM', json_encode($Form), 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);
        return json_encode($Form);
    }
    public function GetDevices(): array
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
    protected function GetConfigParam(&$item1, int $InstanceID, string $ConfigParam): void
    {
        $item1 = IPS_GetProperty($InstanceID, $ConfigParam);
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
}
