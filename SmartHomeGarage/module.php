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
        $this->RegisterPropertyInteger('AlarmDelayMinutes', 60);
        $this->RegisterPropertyBoolean('CloseOnAbsence', true);

        // Attribute for tracking the last direction to guess the next move
        $this->RegisterAttributeInteger('LastDirection', 2); // 2=Fährt Auf, 3=Fährt Zu

        // Timer for Relay impulse and Alarm
        $this->RegisterTimer('RelayOffTimer', 0, 'SHG_TurnOffRelay($_IPS[\'TARGET\']);');
        $this->RegisterTimer('OpenAlarmTimer', 0, 'SHG_TriggerOpenAlarm($_IPS[\'TARGET\']);');

        // Variables
        $this->RegisterVariableInteger('DoorState', '🚪 Torstatus', '', 1);
        $this->RegisterVariableBoolean('DoorControl', 'Tor Steuerung', '~Switch', 2);
        $this->RegisterVariableBoolean('AlarmOpenTooLong', 'Alarm: Tor zu lange offen', '~Alert', 3);
        
        $this->EnableAction('DoorControl');
        $this->EnableAction('AlarmOpenTooLong'); // Allow acknowledging
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // --- Auto-generated References ---
        $ref_MotorVariableID = $this->ReadPropertyInteger('MotorVariableID');
        if ($ref_MotorVariableID > 1 && @IPS_ObjectExists($ref_MotorVariableID)) {
            $this->RegisterReference($ref_MotorVariableID);
        }
        $ref_SensorClosedID = $this->ReadPropertyInteger('SensorClosedID');
        if ($ref_SensorClosedID > 1 && @IPS_ObjectExists($ref_SensorClosedID)) {
            $this->RegisterReference($ref_SensorClosedID);
        }
        $ref_SensorOpenID = $this->ReadPropertyInteger('SensorOpenID');
        if ($ref_SensorOpenID > 1 && @IPS_ObjectExists($ref_SensorOpenID)) {
            $this->RegisterReference($ref_SensorOpenID);
        }
        // ---------------------------------



        if (!IPS_VariableProfileExists('SmartAbsence.DoorState')) {
            IPS_CreateVariableProfile('SmartAbsence.DoorState', 1);
            IPS_SetVariableProfileAssociation('SmartAbsence.DoorState', 0, 'Zu', 'LockClosed', -1);
            IPS_SetVariableProfileAssociation('SmartAbsence.DoorState', 1, 'Auf', 'LockOpen', -1);
            IPS_SetVariableProfileAssociation('SmartAbsence.DoorState', 2, 'Fährt Auf...', 'ArrowUp', -1);
            IPS_SetVariableProfileAssociation('SmartAbsence.DoorState', 3, 'Fährt Zu...', 'ArrowDown', -1);
            IPS_SetVariableProfileAssociation('SmartAbsence.DoorState', 4, 'Teiloffen / Gestoppt', 'Warning', 0xFF8000);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('DoorState'), 'SmartAbsence.DoorState');

        // Register messages for sensors
        $sensorClosed = $this->ReadPropertyInteger('SensorClosedID');
        if ($sensorClosed > 0 && IPS_VariableExists($sensorClosed)) {
            $this->RegisterMessage($sensorClosed, VM_UPDATE);
        }
        $sensorOpen = $this->ReadPropertyInteger('SensorOpenID');
        if ($sensorOpen > 0 && IPS_VariableExists($sensorOpen)) {
            $this->RegisterMessage($sensorOpen, VM_UPDATE);
        }

        // Create links for the sensors so they are visible under the instance
        $this->MaintainLink('LinkSensorClosed', 'Sensor Zu', $sensorClosed, 3);
        $this->MaintainLink('LinkSensorOpen', 'Sensor Auf', $sensorOpen, 4);

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
        } elseif ($Ident === 'AlarmOpenTooLong') {
            $this->SetValue('AlarmOpenTooLong', false);
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
                            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeGarage: Taster $SenderID hat Tor-Aktion ausgelöst!");
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
            $this->SetTimerInterval('RelayOffTimer', 1000); // Trigger release after 1s
        } else {
            IPS_LogMessage('SmartVillaKunterbunt', 'SmartHomeGarage: Fehler - Kein Motor-Aktor konfiguriert.');
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

    public function TurnOffRelay(): void
    {
        $this->SetTimerInterval('RelayOffTimer', 0); // Disable timer
        $motorId = $this->ReadPropertyInteger('MotorVariableID');
        if ($motorId > 0 && IPS_VariableExists($motorId)) {
            @RequestAction($motorId, false);
        }
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
            // Wenn der letzte Zustand "Zu"(0) oder "Auf"(1) war, 
            // wissen wir, dass es jetzt per Hand bewegt wurde oder der Impuls losgeht.
            // Ist es aber z.B. schon auf "Fährt Auf"(2), belassen wir es dabei.
            if ($currentState == 0) {
                // Es hat "Zu"verlassen -> Es fährt wahrscheinlich auf.
                $newState = 2; 
            } elseif ($currentState == 1) {
                // Es hat "Auf"verlassen -> Es fährt wahrscheinlich zu.
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
            
            // Alarm Logic
            if ($state === 1 || $state === 4) { // 1 = Auf, 4 = Teiloffen
                $delayMinutes = $this->ReadPropertyInteger('AlarmDelayMinutes');
                if ($delayMinutes > 0 && $this->GetTimerInterval('OpenAlarmTimer') == 0 && !$this->GetValue('AlarmOpenTooLong')) {
                    $this->SetTimerInterval('OpenAlarmTimer', $delayMinutes * 60000);
                }
            } else {
                // If closing or closed, cancel timer
                $this->SetTimerInterval('OpenAlarmTimer', 0);
                if ($state === 0 && $this->GetValue('AlarmOpenTooLong')) {
                    $this->SetValue('AlarmOpenTooLong', false);
                }
            }
        }
    }
    
    public function TriggerOpenAlarm(): void
    {
        $this->SetTimerInterval('OpenAlarmTimer', 0);
        $this->SetValueIfChanged('AlarmOpenTooLong', true);
        IPS_LogMessage('SmartHomeGarage', 'Garagentor steht zu lange offen -> Alarm ausgelöst.');
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
            $targetBool = ($expected === 'true'|| $expected === '1'|| strtolower((string)$expected) === 'wahr');
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

    private function MaintainLink(string $ident, string $name, int $targetID, int $position): void
    {
        $linkID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($targetID === 0) {
            if ($linkID !== false) {
                IPS_DeleteLink($linkID);
            }
            return;
        }
        if ($linkID === false) {
            $linkID = IPS_CreateLink();
            IPS_SetParent($linkID, $this->InstanceID);
            IPS_SetIdent($linkID, $ident);
            IPS_SetName($linkID, $name);
            IPS_SetPosition($linkID, $position);
        }
        IPS_SetLinkTargetID($linkID, $targetID);
    }
    
    public function SetHouseMode(int $mode, bool $isAbsence = false, bool $isSleep = false): void
    {
        $isAbsence = ($isAbsence || $mode == 1 || $mode == 2);
        
        if ($isAbsence) {
            if ($this->ReadPropertyBoolean('CloseOnAbsence')) {
                // Nur schließen, wenn Tor aktuell nicht schon zu ist
                $state = GetValue($this->GetIDForIdent('DoorState'));
                if ($state != 0 && $state != 3) { // 0=Zu, 3=Fährt Zu
                    IPS_LogMessage('SmartHomeGarage', 'Haus-Modus ist Abwesenheit. Schließe Garagentor automatisch.');
                    $this->TriggerDoor();
                } else {
                    IPS_LogMessage('SmartHomeGarage', 'Haus-Modus ist Abwesenheit. Garagentor ist bereits geschlossen.');
                }
            }
        }
    }

    private function SetValueIfChanged(string $Ident, $Value): void
    {
        $id = $this->GetIDForIdent($Ident);
        if (GetValue($id) !== $Value) {
            SetValue($id, $Value);
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartHomeGarage: '. $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "ExpansionPanel",
            "caption": "⚙ ",
            "items": [
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "CheckBox",
                            "name": "CloseOnAbsence",
                            "caption": "Bei Abwesenheit automatisch schließen"
                        }
                    ]
                },
                {
                    "type": "Label",
                    "label": "Tor Konfiguration"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectVariable",
                            "name": "MotorVariableID",
                            "caption": "Motor Relais (Impuls)"
                        }
                    ]
                },
                {
                    "type": "Label",
                    "label": "Sensoren (Endschalter)"
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectVariable",
                    "name": "SensorClosedID",
                    "caption": "Sensor: Zu-Position"
                },
                {
                    "type": "ValidationTextBox",
                    "name": "SensorClosedValue",
                    "caption": "Auslöse-Wert (z.B. true, CLOSED)"
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectVariable",
                    "name": "SensorOpenID",
                    "caption": "Sensor: Auf-Position"
                },
                {
                    "type": "ValidationTextBox",
                    "name": "SensorOpenValue",
                    "caption": "Auslöse-Wert (z.B. true, CLOSED)"
                }
            ]
        },
        {
            "type": "Label",
            "label": "Taster (Auslöser)"
        },
        {
            "type": "NumberSpinner",
            "name": "AlarmDelayMinutes",
            "caption": "Alarm: Tor zu lange offen (Minuten, 0 = aus)",
            "suffix": "min"
        },
        {
            "type": "Label",
            "label": "Taster (Auslöser)"
        },
        {
            "type": "List",
            "name": "ButtonVariables",
            "caption": "Wand- & Funktaster",
            "rowCount": 5,
            "add": true,
            "delete": true,
            "columns": [
                {
                    "caption": "Variable (Sensor)",
                    "name": "VariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Auslöse-Wert",
                    "name": "TriggerValue",
                    "width": "150px",
                    "add": "CLOSED",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                }
            ]
        },
        {
            "type": "Label",
            "label": "Homematic LEDs"
        },
        {
            "type": "List",
            "name": "LEDInstances",
            "caption": "Status LEDs",
            "rowCount": 5,
            "add": true,
            "delete": true,
            "columns": [
                {
                    "caption": "Homematic Instanz ID",
                    "name": "InstanceID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectInstance"
                    }
                }
            ]
        }
    ]
}
EOT;
    }
}


