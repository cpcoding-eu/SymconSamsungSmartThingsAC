<?php

class SamsungSmartThingsAC extends IPSModule
{
    private string $baseUrl = 'https://api.smartthings.com/v1';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyString('Component', 'main');
        $this->RegisterPropertyInteger('PollingInterval', 60);
        $this->RegisterPropertyInteger('PendingSeconds', 25);
        $this->RegisterPropertyFloat('MinTemperature', 16.0);
        $this->RegisterPropertyFloat('MaxTemperature', 30.0);
        $this->RegisterPropertyFloat('TemperatureStep', 1.0);
        $this->RegisterPropertyBoolean('EnableOptionalMode', true);
        $this->RegisterPropertyBoolean('EnableDebug', false);

        $this->RegisterTimer('RefreshTimer', 0, 'STAC_Refresh($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $this->RegisterVariables();

        $interval = $this->ReadPropertyInteger('PollingInterval');
        $this->SetTimerInterval('RefreshTimer', $interval > 0 ? $interval * 1000 : 0);

        if ($this->ReadPropertyString('DeviceID') === '' || $this->ReadPropertyString('Token') === '') {
            $this->SetStatus(104); // inactive / not fully configured
            return;
        }

        $this->SetStatus(102); // active
    }

    public function RequestAction(string $Ident, $Value): void
    {
        switch ($Ident) {
            case 'Power':
                $this->SetPower((bool)$Value);
                break;

            case 'TargetTemperature':
                $this->SetTargetTemperature((float)$Value);
                break;

            case 'Mode':
                $this->SetMode((int)$Value);
                break;

            case 'FanMode':
                $this->SetFanMode((int)$Value);
                break;

            case 'OptionalMode':
                $this->SetOptionalMode((int)$Value);
                break;

            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    public function TestConnection(): bool
    {
        try {
            $device = $this->GetDevice();
            $label = isset($device['label']) ? $device['label'] : '(ohne Label)';
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
            $status = $this->GetStatus();
            IPS_LogMessage('Samsung SmartThings AC Status', json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            IPS_LogMessage('Samsung SmartThings AC Status', 'Fehler: ' . $e->getMessage());
        }
    }

    public function Refresh(): void
    {
        if ($this->ReadPropertyString('DeviceID') === '' || $this->ReadPropertyString('Token') === '') {
            return;
        }

        try {
            $status = $this->GetStatus();

            $power = $this->ReadAttribute($status, 'switch', 'switch', null);
            if ($power !== null) {
                $this->UpdateFromCloud('Power', $power === 'on');
            }

            $targetTemp = $this->ReadAttribute($status, 'thermostatCoolingSetpoint', 'coolingSetpoint', null);
            if ($targetTemp !== null) {
                $this->UpdateFromCloud('TargetTemperature', (float)$targetTemp);
            }

            $currentTemp = $this->ReadAttribute($status, 'temperatureMeasurement', 'temperature', null);
            if ($currentTemp !== null) {
                $this->SetValue('CurrentTemperature', (float)$currentTemp);
            }

            $humidity = $this->ReadAttribute($status, 'relativeHumidityMeasurement', 'humidity', null);
            if ($humidity !== null && @$this->GetIDForIdent('Humidity')) {
                $this->SetValue('Humidity', (float)$humidity);
            }

            $mode = $this->ReadAttribute($status, 'airConditionerMode', 'airConditionerMode', null);
            if ($mode !== null) {
                $this->UpdateFromCloud('Mode', $this->ModeStringToInt((string)$mode));
            }

            $fan = $this->ReadAttribute($status, 'airConditionerFanMode', 'fanMode', null);
            if ($fan !== null) {
                $this->UpdateFromCloud('FanMode', $this->FanStringToInt((string)$fan));
            }

            if ($this->ReadPropertyBoolean('EnableOptionalMode')) {
                $optional = $this->ReadAttribute($status, 'custom.airConditionerOptionalMode', 'acOptionalMode', null);
                if ($optional !== null) {
                    $this->UpdateFromCloud('OptionalMode', $this->OptionalModeStringToInt((string)$optional));
                }
            }

            $this->SetValue('LastUpdate', time());
            $this->SetValue('LastError', '');
            $this->SetStatus(102);
        } catch (Exception $e) {
            $this->SetValue('LastError', $e->getMessage());
            $this->SetStatus(201);
            IPS_LogMessage('Samsung SmartThings AC', $e->getMessage());
        }
    }

    public function SetPower(bool $value): void
    {
        $command = $value ? 'on' : 'off';
        $this->SendCommand('switch', $command, array());
        $this->SetValue('Power', (bool)$value);
        $this->SetPending('Power', (bool)$value);
    }

    public function SetTargetTemperature(float $temperature): void
    {
        $min = $this->ReadPropertyFloat('MinTemperature');
        $max = $this->ReadPropertyFloat('MaxTemperature');
        $temperature = max($min, min($max, (float)$temperature));

        $this->SendCommand('thermostatCoolingSetpoint', 'setCoolingSetpoint', array($temperature));
        $this->SetValue('TargetTemperature', $temperature);
        $this->SetPending('TargetTemperature', $temperature);
    }

    public function SetMode(int $modeId): void
    {
        $mode = $this->ModeIntToString((int)$modeId);
        $this->SendCommand('airConditionerMode', 'setAirConditionerMode', array($mode));
        $this->SetValue('Mode', (int)$modeId);
        $this->SetPending('Mode', (int)$modeId);
    }

    public function SetFanMode(int $fanId): void
    {
        $fan = $this->FanIntToString((int)$fanId);
        $this->SendCommand('airConditionerFanMode', 'setFanMode', array($fan));
        $this->SetValue('FanMode', (int)$fanId);
        $this->SetPending('FanMode', (int)$fanId);
    }

    public function SetOptionalMode(int $modeId): void
    {
        $mode = $this->OptionalModeIntToString((int)$modeId);
        $this->SendCommand('custom.airConditionerOptionalMode', 'setAcOptionalMode', array($mode));
        $this->SetValue('OptionalMode', (int)$modeId);
        $this->SetPending('OptionalMode', (int)$modeId);
    }

    public function SendRawCommand(string $capability, string $command, string $argumentsJson = '[]'): array
    {
        $arguments = json_decode($argumentsJson, true);
        if (!is_array($arguments)) {
            throw new Exception('argumentsJson muss ein JSON-Array sein.');
        }
        return $this->SendCommand($capability, $command, $arguments);
    }

    private function RegisterVariables()
    {
        $this->RegisterVariableBoolean('Power', 'Power', '~Switch', 10);
        $this->EnableAction('Power');

        $this->RegisterVariableFloat('TargetTemperature', 'Solltemperatur', 'STAC.Temperature', 20);
        $this->EnableAction('TargetTemperature');

        $this->RegisterVariableFloat('CurrentTemperature', 'Isttemperatur', '~Temperature', 30);

        $this->RegisterVariableInteger('Mode', 'Modus', 'STAC.Mode', 40);
        $this->EnableAction('Mode');

        $this->RegisterVariableInteger('FanMode', 'Lüfter', 'STAC.FanMode', 50);
        $this->EnableAction('FanMode');

        if ($this->ReadPropertyBoolean('EnableOptionalMode')) {
            $this->RegisterVariableInteger('OptionalMode', 'Optional Mode', 'STAC.OptionalMode', 60);
            $this->EnableAction('OptionalMode');
        }

        $this->RegisterVariableFloat('Humidity', 'Luftfeuchtigkeit', '~Humidity.F', 70);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp', 90);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 100);
    }

    private function RegisterProfiles()
    {
        $this->RegisterProfileFloat('STAC.Temperature', 'Temperature', '', ' °C', $this->ReadPropertyFloat('MinTemperature'), $this->ReadPropertyFloat('MaxTemperature'), $this->ReadPropertyFloat('TemperatureStep'), 1);

        $this->RegisterProfileInteger('STAC.Mode', 'Climate', '', '', array(
            array(0, 'Cool', '', 0x00AEEF),
            array(1, 'Heat', '', 0xFF6600),
            array(2, 'Dry', '', 0xC0C0C0),
            array(3, 'Fan Only', '', 0x00CC66),
            array(4, 'Auto', '', 0xFFFF00)
        ));

        $this->RegisterProfileInteger('STAC.FanMode', 'Ventilation', '', '', array(
            array(0, 'Auto', '', 0xFFFF00),
            array(1, 'Low', '', 0x99CCFF),
            array(2, 'Medium', '', 0x3399FF),
            array(3, 'High', '', 0x0066CC),
            array(4, 'Turbo', '', 0x003366)
        ));

        $this->RegisterProfileInteger('STAC.OptionalMode', 'Information', '', '', array(
            array(0, 'Off', '', 0xC0C0C0),
            array(1, 'Quiet', '', 0x99CCFF),
            array(2, 'WindFree', '', 0x00CCFF),
            array(3, 'Sleep', '', 0x6666FF)
        ));
    }

    private function RegisterProfileInteger($name, $icon, $prefix, $suffix, $associations)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
        }
        IPS_SetVariableProfileIcon($name, $icon);
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        foreach ($associations as $association) {
            IPS_SetVariableProfileAssociation($name, $association[0], $association[1], $association[2], $association[3]);
        }
    }

    private function RegisterProfileFloat($name, $icon, $prefix, $suffix, $min, $max, $step, $digits)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 2);
        }
        IPS_SetVariableProfileIcon($name, $icon);
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        IPS_SetVariableProfileValues($name, $min, $max, $step);
        IPS_SetVariableProfileDigits($name, $digits);
    }

    private function SendCommand($capability, $command, $arguments)
    {
        $payload = array(
            'commands' => array(
                array(
                    'component' => $this->ReadPropertyString('Component'),
                    'capability' => $capability,
                    'command' => $command,
                    'arguments' => array_values($arguments)
                )
            )
        );

        return $this->Request('POST', '/devices/' . rawurlencode($this->ReadPropertyString('DeviceID')) . '/commands', $payload);
    }

    private function GetStatus()
    {
        return $this->Request('GET', '/devices/' . rawurlencode($this->ReadPropertyString('DeviceID')) . '/components/' . rawurlencode($this->ReadPropertyString('Component')) . '/status', null);
    }

    private function GetDevice()
    {
        return $this->Request('GET', '/devices/' . rawurlencode($this->ReadPropertyString('DeviceID')), null);
    }

    private function Request($method, $endpoint, $payload)
    {
        $token = $this->ReadPropertyString('Token');
        if ($token === '') {
            throw new Exception('SmartThings Token fehlt.');
        }

        $curl = curl_init();
        $headers = array(
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        );

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->baseUrl . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10
        ));

        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($this->ReadPropertyBoolean('EnableDebug')) {
            $this->SendDebug('SmartThings Request', $method . ' ' . $endpoint, 0);
            if ($payload !== null) {
                $this->SendDebug('SmartThings Payload', json_encode($payload), 0);
            }
            $this->SendDebug('SmartThings Response', 'HTTP ' . $code . ': ' . (string)$response, 0);
        }

        if ($error) {
            throw new Exception('cURL Fehler: ' . $error);
        }

        $json = json_decode((string)$response, true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($json) && isset($json['message']) ? $json['message'] : (string)$response;
            throw new Exception('SmartThings HTTP ' . $code . ': ' . $message);
        }

        return is_array($json) ? $json : array();
    }

    private function ReadAttribute($status, $capability, $attribute, $default)
    {
        if (isset($status[$capability]) && isset($status[$capability][$attribute]) && array_key_exists('value', $status[$capability][$attribute])) {
            return $status[$capability][$attribute]['value'];
        }
        return $default;
    }

    private function UpdateFromCloud($ident, $cloudValue)
    {
        if (!$this->HasIdent($ident)) {
            return;
        }

        if ($this->IsPending($ident)) {
            $expected = $this->GetExpected($ident);
            if ($this->ValuesEqual($expected, $cloudValue)) {
                $this->SetValue($ident, $cloudValue);
                $this->ClearPending($ident);
            }
            return;
        }

        $this->SetValue($ident, $cloudValue);
        $this->ClearPending($ident);
    }

    private function SetPending($ident, $expected)
    {
        $seconds = $this->ReadPropertyInteger('PendingSeconds');
        $this->SetBuffer($ident . 'PendingUntil', (string)(time() + $seconds));
        $this->SetBuffer($ident . 'ExpectedValue', json_encode($expected));
    }

    private function ClearPending($ident)
    {
        $this->SetBuffer($ident . 'PendingUntil', '0');
        $this->SetBuffer($ident . 'ExpectedValue', '');
    }

    private function IsPending($ident)
    {
        $until = (int)$this->GetBuffer($ident . 'PendingUntil');
        if ($until <= 0) {
            return false;
        }
        if (time() > $until) {
            $this->ClearPending($ident);
            return false;
        }
        return true;
    }

    private function GetExpected($ident)
    {
        $raw = $this->GetBuffer($ident . 'ExpectedValue');
        if ($raw === '') {
            return null;
        }
        return json_decode($raw, true);
    }

    private function ValuesEqual($a, $b)
    {
        if (is_float($a) || is_float($b) || is_numeric($a) || is_numeric($b)) {
            return abs((float)$a - (float)$b) < 0.05;
        }
        return $a === $b;
    }

    private function HasIdent($ident)
    {
        $id = @$this->GetIDForIdent($ident);
        return $id !== false && $id > 0;
    }

    private function ModeIntToString($value)
    {
        $map = array(0 => 'cool', 1 => 'heat', 2 => 'dry', 3 => 'fanOnly', 4 => 'auto');
        if (!array_key_exists($value, $map)) {
            throw new Exception('Ungültiger Modus: ' . $value);
        }
        return $map[$value];
    }

    private function ModeStringToInt($value)
    {
        $map = array('cool' => 0, 'heat' => 1, 'dry' => 2, 'fanOnly' => 3, 'auto' => 4);
        return array_key_exists($value, $map) ? $map[$value] : 4;
    }

    private function FanIntToString($value)
    {
        $map = array(0 => 'auto', 1 => 'low', 2 => 'medium', 3 => 'high', 4 => 'turbo');
        if (!array_key_exists($value, $map)) {
            throw new Exception('Ungültiger Lüftermodus: ' . $value);
        }
        return $map[$value];
    }

    private function FanStringToInt($value)
    {
        $map = array('auto' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'turbo' => 4);
        return array_key_exists($value, $map) ? $map[$value] : 0;
    }

    private function OptionalModeIntToString($value)
    {
        $map = array(0 => 'off', 1 => 'quiet', 2 => 'windFree', 3 => 'sleep');
        if (!array_key_exists($value, $map)) {
            throw new Exception('Ungültiger Optional Mode: ' . $value);
        }
        return $map[$value];
    }

    private function OptionalModeStringToInt($value)
    {
        $map = array('off' => 0, 'none' => 0, 'quiet' => 1, 'windFree' => 2, 'sleep' => 3);
        return array_key_exists($value, $map) ? $map[$value] : 0;
    }
}
