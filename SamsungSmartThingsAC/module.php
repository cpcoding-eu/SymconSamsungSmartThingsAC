<?php

class SamsungSmartThingsAC extends IPSModule
{
    private $baseUrl = 'https://api.smartthings.com/v1';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyString('Component', 'main');
        $this->RegisterPropertyInteger('PollingInterval', 60);
        $this->RegisterPropertyInteger('PendingSeconds', 25);

        $this->RegisterTimer('RefreshTimer', 0, 'STAC_Refresh($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $this->RegisterVariables();

        $interval = $this->ReadPropertyInteger('PollingInterval');
        $this->SetTimerInterval('RefreshTimer', $interval > 0 ? $interval * 1000 : 0);

        if ($this->ReadPropertyString('DeviceID') === '' || $this->ReadPropertyString('Token') === '') {
            $this->SetStatus(104);
            return;
        }

        $this->SetStatus(102);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Power':
                $this->SetPower((bool) $Value);
                break;
            case 'TargetTemperature':
                $this->SetTargetTemperature((float) $Value);
                break;
            case 'Mode':
                $this->SetMode((int) $Value);
                break;
            case 'FanMode':
                $this->SetFanMode((int) $Value);
                break;
            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    public function TestConnection(): bool
    {
        try {
            $device = $this->GetDevice();
            $label = isset($device['label']) ? (string) $device['label'] : '(ohne Label)';
            $this->SetValue('LastError', 'OK: ' . $label);
            IPS_LogMessage('Samsung SmartThings AC', 'Verbindung OK: ' . $label);
            return true;
        } catch (Exception $e) {
            $this->SetValue('LastError', $e->getMessage());
            IPS_LogMessage('Samsung SmartThings AC', 'Verbindung fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
    }

    public function DumpStatus(): void
    {
        try {
            IPS_LogMessage('Samsung SmartThings AC Status', json_encode($this->GetStatus(), JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            IPS_LogMessage('Samsung SmartThings AC Status', $e->getMessage());
        }
    }

    public function Refresh(): void
    {
        try {
            $status = $this->GetStatus();

            $power = $this->ReadAttribute($status, 'switch', 'switch', null);
            $targetTemp = $this->ReadAttribute($status, 'thermostatCoolingSetpoint', 'coolingSetpoint', null);
            $roomTemp = $this->ReadAttribute($status, 'temperatureMeasurement', 'temperature', null);
            $mode = $this->ReadAttribute($status, 'airConditionerMode', 'airConditionerMode', null);
            $fan = $this->ReadAttribute($status, 'airConditionerFanMode', 'fanMode', null);

            if ($roomTemp !== null) {
                $this->SetValue('CurrentTemperature', (float) $roomTemp);
            }

            if (!$this->IsPending('Power') && $power !== null) {
                $this->SetValue('Power', $power === 'on');
            }
            if (!$this->IsPending('TargetTemperature') && $targetTemp !== null) {
                $this->SetValue('TargetTemperature', (float) $targetTemp);
            }
            if (!$this->IsPending('Mode') && $mode !== null) {
                $this->SetValue('Mode', $this->ModeToInt((string) $mode));
            }
            if (!$this->IsPending('FanMode') && $fan !== null) {
                $this->SetValue('FanMode', $this->FanToInt((string) $fan));
            }

            $this->SetValue('LastUpdate', time());
            $this->SetValue('LastError', '');
        } catch (Exception $e) {
            $this->SetValue('LastError', $e->getMessage());
            IPS_LogMessage('Samsung SmartThings AC Refresh', $e->getMessage());
        }
    }

    public function SetPower(bool $Value): void
    {
        $command = $Value ? 'on' : 'off';
        $this->SendCommand('switch', $command, []);
        $this->SetValue('Power', $Value);
        $this->SetPending('Power');
    }

    public function SetTargetTemperature(float $Value): void
    {
        $this->SendCommand('thermostatCoolingSetpoint', 'setCoolingSetpoint', [$Value]);
        $this->SetValue('TargetTemperature', $Value);
        $this->SetPending('TargetTemperature');
    }

    public function SetMode(int $Value): void
    {
        $mode = $this->IntToMode($Value);
        $this->SendCommand('airConditionerMode', 'setAirConditionerMode', [$mode]);
        $this->SetValue('Mode', $Value);
        $this->SetPending('Mode');
    }

    public function SetFanMode(int $Value): void
    {
        $fan = $this->IntToFan($Value);
        $this->SendCommand('airConditionerFanMode', 'setFanMode', [$fan]);
        $this->SetValue('FanMode', $Value);
        $this->SetPending('FanMode');
    }

    private function RegisterVariables(): void
    {
        $this->RegisterVariableBoolean('Power', 'Ein/Aus', '~Switch', 10);
        $this->EnableAction('Power');

        $this->RegisterVariableFloat('TargetTemperature', 'Solltemperatur', '~Temperature', 20);
        $this->EnableAction('TargetTemperature');

        $this->RegisterVariableFloat('CurrentTemperature', 'Isttemperatur', '~Temperature', 30);

        $this->RegisterVariableInteger('Mode', 'Modus', 'STAC.Mode', 40);
        $this->EnableAction('Mode');

        $this->RegisterVariableInteger('FanMode', 'Lüfter', 'STAC.FanMode', 50);
        $this->EnableAction('FanMode');

        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp', 90);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 100);
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('STAC.Mode')) {
            IPS_CreateVariableProfile('STAC.Mode', 1);
            IPS_SetVariableProfileAssociation('STAC.Mode', 0, 'Cool', '', -1);
            IPS_SetVariableProfileAssociation('STAC.Mode', 1, 'Heat', '', -1);
            IPS_SetVariableProfileAssociation('STAC.Mode', 2, 'Dry', '', -1);
            IPS_SetVariableProfileAssociation('STAC.Mode', 3, 'Fan Only', '', -1);
            IPS_SetVariableProfileAssociation('STAC.Mode', 4, 'Auto', '', -1);
        }
        if (!IPS_VariableProfileExists('STAC.FanMode')) {
            IPS_CreateVariableProfile('STAC.FanMode', 1);
            IPS_SetVariableProfileAssociation('STAC.FanMode', 0, 'Auto', '', -1);
            IPS_SetVariableProfileAssociation('STAC.FanMode', 1, 'Low', '', -1);
            IPS_SetVariableProfileAssociation('STAC.FanMode', 2, 'Medium', '', -1);
            IPS_SetVariableProfileAssociation('STAC.FanMode', 3, 'High', '', -1);
            IPS_SetVariableProfileAssociation('STAC.FanMode', 4, 'Turbo', '', -1);
        }
    }

    private function SendCommand(string $Capability, string $Command, array $Arguments): array
    {
        $deviceId = $this->ReadPropertyString('DeviceID');
        $component = $this->ReadPropertyString('Component');
        return $this->Request('POST', '/devices/' . rawurlencode($deviceId) . '/commands', [
            'commands' => [[
                'component' => $component,
                'capability' => $Capability,
                'command' => $Command,
                'arguments' => $Arguments
            ]]
        ]);
    }

    private function GetDevice(): array
    {
        return $this->Request('GET', '/devices/' . rawurlencode($this->ReadPropertyString('DeviceID')), null);
    }

    private function GetStatus(): array
    {
        return $this->Request('GET', '/devices/' . rawurlencode($this->ReadPropertyString('DeviceID')) . '/components/' . rawurlencode($this->ReadPropertyString('Component')) . '/status', null);
    }

    private function Request(string $Method, string $Endpoint, ?array $Payload): array
    {
        $token = $this->ReadPropertyString('Token');
        if ($token === '') {
            throw new Exception('SmartThings Token fehlt.');
        }

        $ch = curl_init();
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ];
        if ($Payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . $Endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $Method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20
        ]);

        if ($Payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($Payload));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error !== '') {
            throw new Exception('cURL Fehler: ' . $error);
        }
        if ($code < 200 || $code >= 300) {
            throw new Exception('SmartThings HTTP ' . $code . ': ' . (string) $response);
        }

        $json = json_decode((string) $response, true);
        return is_array($json) ? $json : [];
    }

    private function ReadAttribute(array $Status, string $Capability, string $Attribute, mixed $Default): mixed
    {
        return $Status[$Capability][$Attribute]['value'] ?? $Default;
    }

    private function SetPending(string $Ident): void
    {
        $this->SetBuffer($Ident . 'PendingUntil', (string) (time() + $this->ReadPropertyInteger('PendingSeconds')));
    }

    private function IsPending(string $Ident): bool
    {
        $value = $this->GetBuffer($Ident . 'PendingUntil');
        return $value !== '' && time() < (int) $value;
    }

    private function IntToMode(int $Value): string
    {
        $map = [0 => 'cool', 1 => 'heat', 2 => 'dry', 3 => 'fanOnly', 4 => 'auto'];
        if (!array_key_exists($Value, $map)) {
            throw new Exception('Ungueltiger Modus: ' . $Value);
        }
        return $map[$Value];
    }

    private function ModeToInt(string $Value): int
    {
        $map = ['cool' => 0, 'heat' => 1, 'dry' => 2, 'fanOnly' => 3, 'auto' => 4];
        return $map[$Value] ?? 0;
    }

    private function IntToFan(int $Value): string
    {
        $map = [0 => 'auto', 1 => 'low', 2 => 'medium', 3 => 'high', 4 => 'turbo'];
        if (!array_key_exists($Value, $map)) {
            throw new Exception('Ungueltiger Lueftermodus: ' . $Value);
        }
        return $map[$Value];
    }

    private function FanToInt(string $Value): int
    {
        $map = ['auto' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'turbo' => 4];
        return $map[$Value] ?? 0;
    }
}
