<?php

declare(strict_types=1);
    class XiaomiAqaraSplitter extends IPSModule
    {
        public function Create()
        {
            //Never delete this line!
            parent::Create();

            $this->ConnectParent('{BAB408E0-0A0F-48C3-B14E-9FB2FA81F66A}');
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

        public function ForwardData($JSONString)
        {
            $data = json_decode($JSONString);
            IPS_LogMessage('Splitter FRWD', utf8_decode($data->Buffer . ' - ' . $data->ClientIP . ' - ' . $data->ClientPort));

            $this->SendDataToParent(json_encode(['DataID' => '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}', 'Buffer' => $data->Buffer, $data->ClientIP, $data->ClientPort]));

            return 'String data for device instance!';
        }

        public function ReceiveData($JSONString)
        {
            $data = json_decode($JSONString);
            IPS_LogMessage('Splitter RECV', utf8_decode($data->Buffer . ' - ' . $data->ClientIP . ' - ' . $data->ClientPort));

            $this->SendDataToChildren(json_encode(['DataID' => '{C12FA8FE-6934-EF85-A0AA-4F9BC0016826}', 'Buffer' => $data->Buffer, $data->ClientIP, $data->ClientPort]));
        }
    }