<?php

declare(strict_types=1);

class SmartAbsenceSecurity extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DoorVariables', '[]');
        $this->RegisterPropertyString('WindowVariables', '[]');

        $this->RegisterPropertyBool('AutoLockActive', false);
        $this->RegisterPropertyString('AutoLockTime', '{"hour":22,"minute":0,"second":0}');
        $this->RegisterPropertyBool('AutoUnlockActive', false);
        $this->RegisterPropertyString('AutoUnlockTime', '{"hour":7,"minute":0,"second":0}');
        $this->RegisterPropertyBool('AutoUnlockOnlyWhenPresent', true);

        $this->RegisterAttributeBool('IsAbsent', false);

        $this->RegisterTimer('TimerAutoLock', 0, 'SAS_TimerAutoLock($_IPS[\'TARGET\']);');
        $this->RegisterTimer('TimerAutoUnlock', 0, 'SAS_TimerAutoUnlock($_IPS[\'TARGET\']);');

        // Variablen für den WebFront-Status
        $this->RegisterVariableInteger('OpenWindowsCount', 'Offene Fenster / Türen (Zähler)', '', 1);
        $this->RegisterVariableString('OpenWindowsList', 'Offene Fenster / Türen (Namen)', '', 2);
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

        $windowVars = json_decode($this->ReadPropertyString('WindowVariables'), true);
        if (is_array($windowVars)) {
            foreach ($windowVars as $win) {
                $id = $win['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    $this->RegisterMessage($id, VM_UPDATE);
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
        $this->SetValue('OpenWindowsCount', $count);
        
        if ($count == 0) {
            $this->SetValue('OpenWindowsList', 'Alle geschlossen');
        } else {
            $this->SetValue('OpenWindowsList', implode(", ", $openNames));
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

    public function SetAbsence(bool $status): void
    {
        $this->WriteAttributeBool('IsAbsent', $status);

        $doorVars = json_decode($this->ReadPropertyString('DoorVariables'), true);
        if (!is_array($doorVars)) return;

        if ($status) {
            foreach ($doorVars as $door) {
                // Fallback für alte Konfigurationen: true
                $lock = isset($door['LockOnAbsence']) ? $door['LockOnAbsence'] : true;
                if ($lock) {
                    $id = $door['VariableID'];
                    if ($id > 0 && IPS_VariableExists($id)) {
                        RequestAction($id, 0);
                    }
                }
            }
            $this->LogMessage("SmartAbsenceSecurity: Verriegelung der konfigurierten Türen durchgeführt.", KL_NOTIFY);
        } else {
            foreach ($doorVars as $door) {
                // Fallback für alte Konfigurationen: false
                $unlock = isset($door['UnlockOnPresence']) ? $door['UnlockOnPresence'] : false;
                if ($unlock) {
                    $id = $door['VariableID'];
                    if ($id > 0 && IPS_VariableExists($id)) {
                        RequestAction($id, 1);
                    }
                }
            }
            $this->LogMessage("SmartAbsenceSecurity: Aufsperren der konfigurierten Türen durchgeführt.", KL_NOTIFY);
        }
    }

    private function UpdateTimers(): void
    {
        if ($this->ReadPropertyBool('AutoLockActive')) {
            $this->SetTimerInterval('TimerAutoLock', $this->GetMillisecondsToTime($this->ReadPropertyString('AutoLockTime')));
        } else {
            $this->SetTimerInterval('TimerAutoLock', 0);
        }

        if ($this->ReadPropertyBool('AutoUnlockActive')) {
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

    public function TimerAutoLock(): void
    {
        $doorVars = json_decode($this->ReadPropertyString('DoorVariables'), true);
        if (is_array($doorVars)) {
            foreach ($doorVars as $door) {
                $id = $door['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    RequestAction($id, 0); // 0 = Verriegeln
                }
            }
        }
        $this->LogMessage("SmartAbsenceSecurity: Automatisches Verriegeln der Türen durchgeführt.", KL_NOTIFY);
        
        $this->UpdateTimers();
    }

    public function TimerAutoUnlock(): void
    {
        $this->UpdateTimers();

        $onlyWhenPresent = $this->ReadPropertyBool('AutoUnlockOnlyWhenPresent');
        $isAbsent = $this->ReadAttributeBool('IsAbsent');
        
        if ($onlyWhenPresent && $isAbsent) {
            $this->LogMessage("SmartAbsenceSecurity: Automatisches Aufsperren übersprungen (Abwesenheit aktiv).", KL_NOTIFY);
            return;
        }

        $doorVars = json_decode($this->ReadPropertyString('DoorVariables'), true);
        if (is_array($doorVars)) {
            foreach ($doorVars as $door) {
                $id = $door['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    RequestAction($id, 1); // 1 = Aufsperren
                }
            }
        }
        $this->LogMessage("SmartAbsenceSecurity: Automatisches Aufsperren der Türen durchgeführt.", KL_NOTIFY);
    }
}
