<?php

declare(strict_types=1);
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/DebugHelper.php') . '}');
eval('declare(strict_types=1);namespace XiaomiMiDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/BufferHelper.php') . '}');

/**
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 * @property string $TokenKey
 * @property string $TokenIV
 * @property bool  $WaitForHandshake
 * @property int $ServerStamp
 * @property int $ServerStampTime
 */
 class XiaomiMiDevice extends IPSModule
 {
     use \XiaomiMiDevice\DebugHelper;
     use \XiaomiMiDevice\BufferHelper;


     public function Create()
     {
         //Never delete this line!
         parent::Create();

         $this->RegisterPropertyString(\Xiaomi\Device\Property::Host, '');
         $this->RegisterPropertyString(\Xiaomi\Device\Property::DeviceId, '');
         $this->RegisterPropertyString(\Xiaomi\Device\Property::Token, '');
     }

     public function Destroy()
     {
         //Never delete this line!
         parent::Destroy();
     }

     public function ApplyChanges()
     {
         $this->TokenKey = '';
         $this->TokenIV = '';
         $this->WaitForHandshake = false;
         $this->ServerStamp = 0;
         $this->ServerStampTime = 0;
         //Never delete this line!
         parent::ApplyChanges();
         // Anzeige IP in der INFO Spalte
         $this->SetSummary($this->ReadPropertyString(\Xiaomi\Device\Property::Host));
         if (!$this->ReadPropertyString(\Xiaomi\Device\Property::DeviceId) || !$this->ReadPropertyString(\Xiaomi\Device\Property::Token)) {
             $this->SetReceiveDataFilter('.*"ClientIP":"".*');
             $this->SetStatus(IS_EBASE + 1);
             return;
         } else {
             $this->SetReceiveDataFilter('.*"ClientIP":"' . $this->ReadPropertyString(\Xiaomi\Device\Property::Host) . '".*');
         }

         $token = hex2bin($this->ReadPropertyString(\Xiaomi\Device\Property::Token));
         $this->TokenKey = md5($token, true);
         $this->TokenIV = md5($this->TokenKey . $token, true);
         $this->SendDebug('Token', $token, 1);
         $this->SendDebug('Key', $this->TokenKey, 1);
         $this->SendDebug('IV', $this->TokenIV, 1);
         if (IPS_GetKernelRunlevel() != KR_READY) {
             return;
         }
         if (!$this->SendHandshake()) {
             $this->SetStatus(IS_EBASE + 2);
             return;
         }
         // Info Paket abholen mit model
         // Wenn model neu anders als alt => Fähigkeiten neu laden
        // 
         $this->SetStatus(IS_ACTIVE);
         //$this->RequestStateLocal();
     }

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
     }
     public function Send(string $Method, array $Prams)
     {
         //$this->SendHandshake();
         $Payload = json_encode(
             [
                 'id'    => time(),
                 'method'=> $Method,
                 'params'=> $Prams
             ]
         );
         $this->SendDebug('Send', $Payload, 0);
         $Data = $this->EncryptMessage($Payload);
         $this->SendDataToParent(
             json_encode(
                 [
                     'DataID'     => self::SendToUDPSocket,
                     'ClientIP'   => $this->ReadPropertyString(self::Property_Host),
                     'ClientPort' => 54321,
                     'Broadcast'  => false,
                     'Buffer'     => utf8_encode($Data)
                 ]
             )
         );
     }
     private function SendHandshake(): bool
     {
         if ($this->WaitForHandshake) {
             return false;
         }
         $this->WaitForHandshake = true;
         $Data = hex2bin('21310020ffffffffffffffffffffffffffffffffffffffffffffffffffffffff');
         $this->SendDebug('Send Handshake', $Data, 1);
         $this->SendDataToParent(
             json_encode(
                 [
                     'DataID'     => self::SendToUDPSocket,
                     'ClientIP'   => $this->ReadPropertyString(self::Property_Host),
                     'ClientPort' => 54321,
                     'Broadcast'  => false,
                     'Buffer'     => utf8_encode($Data)
                 ]
             )
         );
         return $this->WaitForHandshake();
     }
     private function WaitForHandshake(): bool
     {
         for ($i = 0; $i < 1000; $i++) {
             if (!$this->WaitForHandshake) {
                 return true;
             } else {
                 IPS_Sleep(5);
             }
         }
         $this->WaitForHandshake = false;
         $this->SendDebug('Error Handshake Timeout', '', 0);

         return false;
     }
     private function EncryptMessage(string $data): ?string
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
         $Payload .= hex2bin($this->ReadPropertyString(\Xiaomi\Device\Property::Token));
         $Payload .= $Encrypted;
         // MD5 Checksum
         $Checksum = md5($Payload, true);
         for ($i = 0; $i < 16; $i++) {
             $Payload[$i + 16] = $Checksum[$i];
         }
         $this->SendDebug('Encrypted', $Payload, 1);
         return $Payload;
     }

     private function DecryptMessage(string $msg): ?string
     {
         //const device = this.getDevice(address);
         $Data = str_split($msg, 4);
         if (substr($Data[0], 0, 2) != "\x21\x31") {
             $this->SendDebug('Error on decrypt', 'header wrong', 0);
             return null;
         }
         $len = unpack('n', $Data[0], 2)[1];
         if (strlen($msg) != $len) {
             $this->SendDebug('Error on decrypt', 'Paket should ' . $len . ' bytes', 0);
             return null;
         }
         if (count($Data) < 8) {
             $this->SendDebug('Error on decrypt', 'Paket to short', 0);
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
             return '';
         }
         $calcChecksum = md5(
             substr($msg, 0, 16) .
            hex2bin($this->ReadPropertyString(\Xiaomi\Device\Property::Token)) .
            $encryptedMsg,
             true
         );

         if ($calcChecksum != $recvChecksum) {
             $this->SendDebug('Error in checksum', 'Received: ' . bin2hex($recvChecksum) . ' Calc: ' . bin2hex($calcChecksum), 0);
             return null;
         }
         //Received: 5d62e8b9 Calc: 5d62e8b9f45fa97dbf53a654f13dc6a6

         $Data = openssl_decrypt($encryptedMsg, 'aes-128-cbc', $this->TokenKey, OPENSSL_RAW_DATA, $this->TokenIV);
         return $Data;
     }
 }
