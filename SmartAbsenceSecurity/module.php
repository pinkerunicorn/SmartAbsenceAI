<?php

class SmartAbsenceSecurity extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('DoorVariables', '[]');
        $this->RegisterPropertyString('WindowVariables', '[]');
        $this->RegisterPropertyBoolean('LockOnAbsence', true);
        $this->RegisterPropertyBoolean('UnlockOnPresence', false);

        // Variablen für den WebFront-Status
        $this->RegisterVariableInteger('OpenWindowsCount', 'Offene Fenster / Türen (Zähler)', '', 1);
        $this->RegisterVariableString('OpenWindowsList', 'Offene Fenster / Türen (Namen)', '', 2);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

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

        $this->SetStatus(102);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE) {
            $this->CalculateOpenWindows();
        }
    }

    private function CalculateOpenWindows()
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

    public function SetAbsence(bool $status)
    {
        $doorVars = json_decode($this->ReadPropertyString('DoorVariables'), true);
        if (!is_array($doorVars)) return;

        if ($status) {
            if ($this->ReadPropertyBoolean('LockOnAbsence')) {
                // Türen verriegeln (Tedee: 0 = Zusperren)
                foreach ($doorVars as $door) {
                    $id = $door['VariableID'];
                    if ($id > 0 && IPS_VariableExists($id)) {
                        RequestAction($id, 0);
                    }
                }
                $this->LogMessage("SmartAbsenceSecurity: Türen wurden verriegelt.", KL_NOTIFY);
            }
        } else {
            if ($this->ReadPropertyBoolean('UnlockOnPresence')) {
                // Türen aufsperren (Tedee: 1 = Aufsperren)
                foreach ($doorVars as $door) {
                    $id = $door['VariableID'];
                    if ($id > 0 && IPS_VariableExists($id)) {
                        RequestAction($id, 1);
                    }
                }
                $this->LogMessage("SmartAbsenceSecurity: Türen wurden aufgesperrt.", KL_NOTIFY);
            }
        }
    }
}
