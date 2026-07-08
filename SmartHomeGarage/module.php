<?php

declare(strict_types=1);

class SmartHomeGarage extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger('MotorVariableID', 0);
        $this->RegisterPropertyInteger('SensorClosedID', 0);
        $this->RegisterPropertyString('SensorClosedValue', 'true');
        $this->RegisterPropertyInteger('SensorOpenID', 0);
        $this->RegisterPropertyString('SensorOpenValue', 'true');
        
        $this->RegisterPropertyString('ButtonVariables', '[]');
        $this->RegisterPropertyString('LEDInstances', '[]');

        // Attribute for tracking the last direction to guess the next move
        $this->RegisterAttributeInteger('LastDirection', 2); // 2=Fährt Auf, 3=Fährt Zu

        // Profiles
        if (!IPS_VariableProfileExists('SHG.DoorState')) {
            IPS_CreateVariableProfile('SHG.DoorState', 1); // Integer
            IPS_SetVariableProfileAssociation('SHG.DoorState', 0, 'Zu', 'LockClosed', -1);
            IPS_SetVariableProfileAssociation('SHG.DoorState', 1, 'Auf', 'LockOpen', -1);
            IPS_SetVariableProfileAssociation('SHG.DoorState', 2, 'Fährt Auf...', 'ArrowUp', -1);
            IPS_SetVariableProfileAssociation('SHG.DoorState', 3, 'Fährt Zu...', 'ArrowDown', -1);
            IPS_SetVariableProfileAssociation('SHG.DoorState', 4, 'Teiloffen / Gestoppt', 'Warning', 0xFF8000);
        }

        // Variables
        $this->RegisterVariableInteger('DoorState', 'Tor Status', 'SHG.DoorState', 1);
        $this->RegisterVariableBoolean('DoorControl', 'Tor Steuerung', '~Switch', 2);
        
        $this->EnableAction('DoorControl');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Register messages for sensors
        $sensorClosed = $this->ReadPropertyInteger('SensorClosedID');
        if ($sensorClosed > 0 && IPS_VariableExists($sensorClosed)) {
            $this->RegisterMessage($sensorClosed, VM_UPDATE);
        }
        $sensorOpen = $this->ReadPropertyInteger('SensorOpenID');
        if ($sensorOpen > 0 && IPS_VariableExists($sensorOpen)) {
            $this->RegisterMessage($sensorOpen, VM_UPDATE);
        }

        // Register messages for buttons
        $buttons = json_decode($this->ReadPropertyString('ButtonVariables'), true);
        if (is_array($buttons)) {
            foreach ($buttons as $btn) {
                $id = $btn['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
            }
        }

        // Initialize status
        $this->CheckSensors();
    }

    public function RequestAction(string $Ident, $Value): void
    {
        if ($Ident === 'DoorControl') {
            $this->TriggerDoor();
            // Reset control button instantly so it acts like a push button
            $this->SetValue('DoorControl', false);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message == VM_UPDATE) {
            $sensorClosed = $this->ReadPropertyInteger('SensorClosedID');
            $sensorOpen = $this->ReadPropertyInteger('SensorOpenID');
            
            if ($SenderID == $sensorClosed || $SenderID == $sensorOpen) {
                $this->CheckSensors();
                return;
            }

            // Check if it's a button
            $buttons = json_decode($this->ReadPropertyString('ButtonVariables'), true);
            if (is_array($buttons)) {
                foreach ($buttons as $btn) {
                    if ($SenderID == $btn['VariableID']) {
                        $currentVal = GetValue($SenderID);
                        if ($this->ValuesMatch($currentVal, $btn['TriggerValue'])) {
                            IPS_LogMessage('SmartHomeGarage', "Taster $SenderID hat Tor-Aktion ausgelöst!");
                            $this->TriggerDoor();
                        }
                    }
                }
            }
        }
    }

    private function TriggerDoor(): void
    {
        $motorId = $this->ReadPropertyInteger('MotorVariableID');
        if ($motorId > 0 && IPS_VariableExists($motorId)) {
            @RequestAction($motorId, true);
        } else {
            IPS_LogMessage('SmartHomeGarage', 'Fehler: Kein Motor-Aktor konfiguriert.');
        }

        // Calculate expected state
        $currentState = $this->GetValue('DoorState');
        $nextState = 4; // Default to Gestoppt

        if ($currentState == 0) {
            $nextState = 2; // Fährt Auf
        } elseif ($currentState == 1) {
            $nextState = 3; // Fährt Zu
        } elseif ($currentState == 2 || $currentState == 3) {
            $nextState = 4; // Gestoppt
        } elseif ($currentState == 4) {
            // Wenn Teiloffen und getriggert wird, raten wir anhand der letzten Fahrtrichtung
            $lastDir = $this->ReadAttributeInteger('LastDirection');
            $nextState = ($lastDir == 2) ? 3 : 2; 
        }

        if ($nextState == 2 || $nextState == 3) {
            $this->WriteAttributeInteger('LastDirection', $nextState);
        }

        $this->SetDoorState($nextState);
    }

    private function CheckSensors(): void
    {
        $sensorClosed = $this->ReadPropertyInteger('SensorClosedID');
        $sensorOpen = $this->ReadPropertyInteger('SensorOpenID');

        $isClosed = false;
        $isOpen = false;

        if ($sensorClosed > 0 && IPS_VariableExists($sensorClosed)) {
            $isClosed = $this->ValuesMatch(GetValue($sensorClosed), $this->ReadPropertyString('SensorClosedValue'));
        }
        if ($sensorOpen > 0 && IPS_VariableExists($sensorOpen)) {
            $isOpen = $this->ValuesMatch(GetValue($sensorOpen), $this->ReadPropertyString('SensorOpenValue'));
        }

        $currentState = $this->GetValue('DoorState');
        $newState = $currentState;

        if ($isClosed) {
            $newState = 0; // Zu
        } elseif ($isOpen) {
            $newState = 1; // Auf
        } else {
            // Weder Zu noch Auf. 
            // Wenn der letzte Zustand "Zu" (0) oder "Auf" (1) war, 
            // wissen wir, dass es jetzt per Hand bewegt wurde oder der Impuls losgeht.
            // Ist es aber z.B. schon auf "Fährt Auf" (2), belassen wir es dabei.
            if ($currentState == 0) {
                // Es hat "Zu" verlassen -> Es fährt wahrscheinlich auf.
                $newState = 2; 
            } elseif ($currentState == 1) {
                // Es hat "Auf" verlassen -> Es fährt wahrscheinlich zu.
                $newState = 3;
            }
        }

        if ($newState !== $currentState) {
            $this->SetDoorState($newState);
        }
    }

    private function SetDoorState(int $state): void
    {
        if ($this->GetValue('DoorState') !== $state) {
            $this->SetValue('DoorState', $state);
            $this->UpdateLEDs($state);
        }
    }

    private function UpdateLEDs(int $state): void
    {
        $leds = json_decode($this->ReadPropertyString('LEDInstances'), true);
        if (!is_array($leds) || count($leds) == 0) return;

        // Homematic COMBINED_PARAMETER Strings
        $string = '';
        if ($state == 0) {
            // Zu -> Aus
            $string = 'L=100,DV=31,DU=2,RTV=0,RTU=0,C=0,CB=0,RTTOV=0,RTTOU=3';
        } elseif ($state == 1) {
            // Auf -> Weiß, Pulsierend
            $string = 'L=100,DV=31,DU=2,RTV=0,RTU=0,C=7,CB=9,RTTOV=0,RTTOU=3';
        } elseif ($state == 2) {
            // Fährt Auf -> Gelb, Blitzen
            $string = 'L=100,DV=31,DU=2,RTV=0,RTU=0,C=6,CB=6,RTTOV=0,RTTOU=3';
        } elseif ($state == 3) {
            // Fährt Zu -> Rot, Blitzen
            $string = 'L=100,DV=31,DU=2,RTV=0,RTU=0,C=4,CB=6,RTTOV=0,RTTOU=3';
        } elseif ($state == 4) {
            // Gestoppt / Teiloffen -> Blau, Dauerlicht
            $string = 'L=100,DV=31,DU=2,RTV=0,RTU=0,C=1,CB=1,RTTOV=0,RTTOU=3';
        }

        if ($string === '') return;

        foreach ($leds as $led) {
            $instId = $led['InstanceID'];
            if ($instId > 0 && IPS_InstanceExists($instId)) {
                @HM_WriteValueString($instId, 'COMBINED_PARAMETER', $string);
            }
        }
    }

    private function ValuesMatch($actual, $expected): bool
    {
        if ((string)$expected === '') {
            return true; // Empty string means trigger on ANY update
        }
        if (is_bool($actual)) {
            $targetBool = ($expected === 'true' || $expected === '1' || strtolower((string)$expected) === 'wahr');
            return ($actual === $targetBool);
        } elseif (is_int($actual)) {
            return ($actual === (int)$expected);
        } elseif (is_float($actual)) {
            return ($actual === (float)$expected);
        } elseif (is_string($actual)) {
            return (strtolower(trim($actual)) === strtolower(trim((string)$expected)));
        }
        return ($actual == $expected);
    }
}
