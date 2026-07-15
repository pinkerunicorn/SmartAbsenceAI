<?php

declare(strict_types=1);

class SmartHomeSecurity extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DoorVariables', '[]');
        $this->RegisterPropertyString('WindowVariables', '[]');

        $this->RegisterPropertyBoolean('AutoLockActive', false);
        $this->RegisterPropertyString('AutoLockTime', '{"hour":22,"minute":0,"second":0}');
        $this->RegisterPropertyBoolean('AutoUnlockActive', false);
        $this->RegisterPropertyString('AutoUnlockTime', '{"hour":7,"minute":0,"second":0}');
        $this->RegisterPropertyBoolean('AutoUnlockOnlyWhenPresent', true);

        $this->RegisterTimer('TimerAutoLock', 0, 'SHS_TimerAutoLock($_IPS[\'TARGET\']);');
        $this->RegisterTimer('TimerAutoUnlock', 0, 'SHS_TimerAutoUnlock($_IPS[\'TARGET\']);');

        // Variablen für den WebFront-Status
        $this->RegisterVariableInteger('OpenWindowsCount', '🚪 Offene Fenster / Türen (Zähler)', '', 1);
        IPS_SetIcon($this->GetIDForIdent('OpenWindowsCount'), 'Window');
        $this->RegisterVariableString('OpenWindowsList', '📝 Offene Fenster / Türen (Namen)', '', 2);
        IPS_SetIcon($this->GetIDForIdent('OpenWindowsList'), 'Window');
        $this->RegisterVariableBoolean('AlarmWindowsOpenDuringAbsence', 'Alarm: Fenster/Tür offen bei Abwesenheit', '', 3);
        IPS_SetIcon($this->GetIDForIdent('AlarmWindowsOpenDuringAbsence'), 'Warning');
        $this->EnableAction('AlarmWindowsOpenDuringAbsence');
        
        $this->RegisterVariableString('VestaboardStatus', 'Kurz-Status (Vestaboard)', '', 4);
        IPS_SetIcon($this->GetIDForIdent('VestaboardStatus'), 'Information');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();


        IPS_SetVariableCustomPresentation($this->GetIDForIdent('OpenWindowsCount'), [
            'PRESENTATION'   => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'           => 'Window',
            'SUFFIX'         => ' offen'
        ]);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('OpenWindowsList'), [
            'PRESENTATION'   => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'           => 'Information'
        ]);

        $this->MaintainVariable('VestaboardStatus', 'Kurz-Status (Vestaboard)', 3, '', 3, true);

        $windowVars = json_decode($this->ReadPropertyString('WindowVariables'), true);
        if (is_array($windowVars)) {
            foreach ($windowVars as $win) {
                $id = $win['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
            }
        }
        $doorVars = json_decode($this->ReadPropertyString('DoorVariables'), true);
        if (is_array($doorVars)) {
            foreach ($doorVars as $door) {
                if (isset($door['SensorVariableID'])) {
                    $id = $door['SensorVariableID'];
                    if ($id > 0 && IPS_VariableExists($id)) {
                        $this->RegisterMessage($id, VM_UPDATE);
                    }
                }
            }
        }
        $this->CalculateOpenWindows();
        $this->UpdateTimers();

        $this->SetStatus(102);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message == VM_UPDATE) {
            $this->CalculateOpenWindows();
        }
    }

    private function CalculateOpenWindows(): void
    {
        $windowVars = json_decode($this->ReadPropertyString('WindowVariables'), true);
        $count = 0;
        $openNames = [];
        if (is_array($windowVars)) {
            foreach ($windowVars as $win) {
                $id = $win['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    $currentVal = GetValue($id);
                    $checkVal = $win['ClosedValue'];
                    
                    $isClosed = false;
                    if (is_bool($currentVal)) {
                        $targetBool = ($checkVal === 'true' || $checkVal === '1' || strtolower($checkVal) === 'wahr');
                        $isClosed = ($currentVal === $targetBool);
                    } else if (is_int($currentVal)) {
                        $isClosed = ($currentVal === (int)$checkVal);
                    } else if (is_float($currentVal)) {
                        $isClosed = ($currentVal === (float)$checkVal);
                    } else if (is_string($currentVal)) {
                        $isClosed = (strtolower(trim($currentVal)) === strtolower(trim($checkVal)));
                    } else {
                        $isClosed = ($currentVal == $checkVal);
                    }
                    
                    if (!$isClosed) {
                        $count++;
                        $name = isset($win['Name']) && $win['Name'] != '' ? $win['Name'] : IPS_GetName($id);
                        $openNames[] = $name;
                    }
                }
            }
        }

        $doorVars = json_decode($this->ReadPropertyString('DoorVariables'), true);
        if (is_array($doorVars)) {
            foreach ($doorVars as $door) {
                if (isset($door['SensorVariableID'])) {
                    $id = $door['SensorVariableID'];
                    if ($id > 0 && IPS_VariableExists($id)) {
                        $currentVal = GetValue($id);
                        $checkVal = isset($door['ClosedValue']) ? $door['ClosedValue'] : 'false';
                        
                        $isClosed = false;
                        if (is_bool($currentVal)) {
                            $targetBool = ($checkVal === 'true' || $checkVal === '1' || strtolower($checkVal) === 'wahr');
                            $isClosed = ($currentVal === $targetBool);
                        } else if (is_int($currentVal)) {
                            $isClosed = ($currentVal === (int)$checkVal);
                        } else if (is_float($currentVal)) {
                            $isClosed = ($currentVal === (float)$checkVal);
                        } else if (is_string($currentVal)) {
                            $isClosed = (strtolower(trim($currentVal)) === strtolower(trim($checkVal)));
                        } else {
                            $isClosed = ($currentVal == $checkVal);
                        }
                        
                        if (!$isClosed) {
                            $count++;
                            $name = isset($door['Name']) && $door['Name'] != '' ? $door['Name'] : IPS_GetName($id);
                            $openNames[] = $name;
                        }
                    }
                }
            }
        }

        $this->SetValueIfChanged('OpenWindowsCount', $count);
        
        if ($count == 0) {
            $this->SetValueIfChanged('OpenWindowsList', 'Alle geschlossen');
            $this->SetValueIfChanged('VestaboardStatus', '');
        } else {
            $namesStr = implode(", ", $openNames);
            $this->SetValueIfChanged('OpenWindowsList', $namesStr);
            $this->SetValueIfChanged('VestaboardStatus', $count . ' offen');
        }
    }

    public function GetOpenWindows(): array
    {
        $this->CalculateOpenWindows();
        $count = GetValue($this->GetIDForIdent('OpenWindowsCount'));
        if ($count > 0) {
            $list = GetValue($this->GetIDForIdent('OpenWindowsList'));
            return explode(", ", $list);
        }
        return [];
    }

    public function SetHouseMode(int $mode, bool $isAbsence = false, bool $isSleep = false): void
    {
        $shouldLock = ($isAbsence || $isSleep || $mode == 4); // 4=Heimkino (still fallback to mode ID if needed, but primarily relying on absence/sleep)

        $doorVars = json_decode($this->ReadPropertyString('DoorVariables'), true);
        if (!is_array($doorVars)) return;

        if ($shouldLock) {
            foreach ($doorVars as $door) {
                // Fallback für alte Konfigurationen: true
                $lock = isset($door['LockOnAbsence']) ? $door['LockOnAbsence'] : true;
                if ($lock) {
                    $id = $door['VariableID'];
                    if ($id > 0 && IPS_VariableExists($id)) {
                        if ($this->IsDoorClosed($door)) {
                            RequestAction($id, $this->GetActionValue($door, 'LockValue', 1));
                        } else {
                            $name = isset($door['Name']) && $door['Name'] != '' ? $door['Name'] : IPS_GetName($id);
                            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSecurity: Verriegelung für '$name' übersprungen, da die Tür noch offen steht!");
                        }
                    }
                }
            }
            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSecurity: Verriegelung der konfigurierten Türen (Hausmodus $mode) durchgeführt.");
        } else {
            foreach ($doorVars as $door) {
                // Fallback für alte Konfigurationen: false
                $unlock = isset($door['UnlockOnPresence']) ? $door['UnlockOnPresence'] : false;
                if ($unlock) {
                    $id = $door['VariableID'];
                    if ($id > 0 && IPS_VariableExists($id)) {
                        RequestAction($id, $this->GetActionValue($door, 'UnlockValue', 0));
                    }
                }
            }
            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSecurity: Aufsperren der konfigurierten Türen (Hausmodus $mode) durchgeführt.");
        }
        // Alarm Check
        if ($isAbsence) {
            $this->CalculateOpenWindows();
            if ($this->GetValue('OpenWindowsCount') > 0) {
                $this->SetValueIfChanged('AlarmWindowsOpenDuringAbsence', true);
                IPS_LogMessage('SmartHomeSecurity', "Alarm: Bei Abwesenheit sind noch Fenster/Türen offen!");
            }
        }
    }

    private function UpdateTimers(): void
    {
        if ($this->ReadPropertyBoolean('AutoLockActive')) {
            $this->SetTimerInterval('TimerAutoLock', $this->GetMillisecondsToTime($this->ReadPropertyString('AutoLockTime')));
        } else {
            $this->SetTimerInterval('TimerAutoLock', 0);
        }

        if ($this->ReadPropertyBoolean('AutoUnlockActive')) {
            $this->SetTimerInterval('TimerAutoUnlock', $this->GetMillisecondsToTime($this->ReadPropertyString('AutoUnlockTime')));
        } else {
            $this->SetTimerInterval('TimerAutoUnlock', 0);
        }
    }

    private function GetMillisecondsToTime(string $timeStr): int
    {
        $time = json_decode($timeStr, true);
        if (!is_array($time)) return 0;
        
        $now = time();
        $targetTime = mktime($time['hour'], $time['minute'], $time['second'], (int)date('m'), (int)date('d'), (int)date('Y'));
        
        if ($targetTime <= $now) {
            $targetTime += 86400; // Nächster Tag
        }
        
        return ($targetTime - $now) * 1000;
    }

    private function IsDoorClosed(array $door): bool
    {
        if (!isset($door['SensorVariableID']) || $door['SensorVariableID'] <= 0) return true;
        $id = $door['SensorVariableID'];
        if (!IPS_VariableExists($id)) return true;
        
        $currentVal = GetValue($id);
        $checkVal = isset($door['ClosedValue']) ? $door['ClosedValue'] : 'false';
        
        if (is_bool($currentVal)) {
            $targetBool = ($checkVal === 'true' || $checkVal === '1' || strtolower($checkVal) === 'wahr');
            return ($currentVal === $targetBool);
        } else if (is_int($currentVal)) {
            return ($currentVal === (int)$checkVal);
        } else if (is_float($currentVal)) {
            return ($currentVal === (float)$checkVal);
        } else if (is_string($currentVal)) {
            return (strtolower(trim($currentVal)) === strtolower(trim($checkVal)));
        }
        return ($currentVal == $checkVal);
    }

    private function SetValueIfChanged(string $Ident, $Value): void
    {
        $id = $this->GetIDForIdent($Ident);
        if (GetValue($id) !== $Value) {
            $this->SetValue($Ident, $Value);
        }
    }

    private function GetActionValue(array $door, string $key, $default)
    {
        $val = isset($door[$key]) ? $door[$key] : $default;
        if ($val === 'true' || $val === 'True') return true;
        if ($val === 'false' || $val === 'False') return false;
        if (is_numeric($val)) {
            if (strpos((string)$val, '.') !== false) return (float)$val;
            return (int)$val;
        }
        return $val;
    }

    public function TimerAutoLock(): void
    {
        $doorVars = json_decode($this->ReadPropertyString('DoorVariables'), true);
        if (is_array($doorVars)) {
            foreach ($doorVars as $door) {
                $id = $door['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    if ($this->IsDoorClosed($door)) {
                        RequestAction($id, $this->GetActionValue($door, 'LockValue', 1)); // Verriegeln
                    } else {
                        $name = isset($door['Name']) && $door['Name'] != '' ? $door['Name'] : IPS_GetName($id);
                        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSecurity: Auto-Lock für '$name' übersprungen, da die Tür noch offen steht!");
                    }
                }
            }
        }
        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSecurity: Automatisches Verriegeln der Türen durchgeführt.");
        
        $this->UpdateTimers();
    }

    public function TimerAutoUnlock(): void
    {
        $this->UpdateTimers();

        $onlyWhenPresent = $this->ReadPropertyBoolean('AutoUnlockOnlyWhenPresent');
        $isAbsent = $this->ReadAttributeBoolean('IsAbsent');
        
        if ($onlyWhenPresent && $isAbsent) {
            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSecurity: Automatisches Aufsperren übersprungen (Abwesenheit aktiv).");
            return;
        }

        $doorVars = json_decode($this->ReadPropertyString('DoorVariables'), true);
        if (is_array($doorVars)) {
            foreach ($doorVars as $door) {
                $id = $door['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    RequestAction($id, $this->GetActionValue($door, 'UnlockValue', 0)); // Aufsperren
                }
            }
        }
        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSecurity: Automatisches Aufsperren der Türen durchgeführt.");
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartHomeSecurity: ' . $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "List",
            "name": "DoorVariables",
            "caption": "Türen (Kontakte & Schlösser)",
            "rowCount": 10,
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "Name",
                    "name": "Name",
                    "width": "150px",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Tür-Kontakt (Sensor)",
                    "name": "SensorVariableID",
                    "width": "150px",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Wert f. Geschlossen",
                    "name": "ClosedValue",
                    "width": "150px",
                    "add": "false",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Türschloss (Aktor)",
                    "name": "VariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Wert f. Verriegeln",
                    "name": "LockValue",
                    "width": "100px",
                    "add": "1",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Wert f. Entriegeln",
                    "name": "UnlockValue",
                    "width": "100px",
                    "add": "0",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Verriegeln (Abwesenheit)",
                    "name": "LockOnAbsence",
                    "width": "150px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Aufsperren (Rückkehr)",
                    "name": "UnlockOnPresence",
                    "width": "150px",
                    "add": false,
                    "edit": {
                        "type": "CheckBox"
                    }
                }
            ]
        },
        {
            "type": "List",
            "name": "WindowVariables",
            "caption": "Fenster-Kontakte (Sicherheit)",
            "rowCount": 15,
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "Name (für Meldungen)",
                    "name": "Name",
                    "width": "150px",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Variable",
                    "name": "VariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Wert für Geschlossen",
                    "name": "ClosedValue",
                    "width": "150px",
                    "add": "false",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                }
            ]
        },
        {
            "type": "Label",
            "label": "Automatische Türschloss-Steuerung"
        },
        {
            "type": "CheckBox",
            "name": "AutoLockActive",
            "caption": "Automatisch Verschließen"
        },
        {
            "type": "SelectTime",
            "name": "AutoLockTime",
            "caption": "Uhrzeit zum Verschließen"
        },
        {
            "type": "CheckBox",
            "name": "AutoUnlockActive",
            "caption": "Automatisch Aufsperren"
        },
        {
            "type": "SelectTime",
            "name": "AutoUnlockTime",
            "caption": "Uhrzeit zum Aufsperren"
        },
        {
            "type": "CheckBox",
            "name": "AutoUnlockOnlyWhenPresent",
            "caption": "Aufsperren nur bei Anwesenheit"
        }
    ]
}
EOT;
    }
}


