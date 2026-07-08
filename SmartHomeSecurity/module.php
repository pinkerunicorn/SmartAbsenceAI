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

        $this->RegisterAttributeBoolean('IsAbsent', false);

        $this->RegisterTimer('TimerAutoLock', 0, 'SHS_TimerAutoLock($_IPS[\'TARGET\']);');
        $this->RegisterTimer('TimerAutoUnlock', 0, 'SHS_TimerAutoUnlock($_IPS[\'TARGET\']);');

        // Variablen für den WebFront-Status
        $this->RegisterVariableInteger('OpenWindowsCount', '🚪 Offene Fenster / Türen (Zähler)', '', 1);
        $this->RegisterVariableString('OpenWindowsList', '📝 Offene Fenster / Türen (Namen)', '', 2);
        $this->RegisterVariableString('VestaboardStatus', 'Kurz-Status (Vestaboard)', '', 3);
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

        $this->SetValue('OpenWindowsCount', $count);
        
        if ($count == 0) {
            $this->SetValue('OpenWindowsList', 'Alle geschlossen');
            $this->SetValue('VestaboardStatus', '');
        } else {
            $namesStr = implode(", ", $openNames);
            $this->SetValue('OpenWindowsList', $namesStr);
            if ($count == 1) {
                $this->SetValue('VestaboardStatus', '1 offen: ' . $openNames[0]);
            } else {
                $this->SetValue('VestaboardStatus', $count . ' offen: ' . $namesStr);
            }
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

    public function SetHouseMode(int $mode): void
    {
        // 0=Anwesenheit, 1=Abwesenheit, 2=Urlaub, 3=Party, 4=Heimkino, 5=Schlafen, 6=Putzen
        $shouldLock = ($mode == 1 || $mode == 2 || $mode == 4 || $mode == 5);
        $this->WriteAttributeBoolean('IsAbsent', ($mode == 1 || $mode == 2)); // Nur für interne Logik belassen, falls verwendet

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
}
