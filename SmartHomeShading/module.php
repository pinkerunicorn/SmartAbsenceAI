<?php

declare(strict_types=1);

class SmartHomeShading extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // 1. Globale Sensorik
        $this->RegisterPropertyInteger('AzimuthVariableID', 0);
        $this->RegisterPropertyInteger('ElevationVariableID', 0);
        $this->RegisterPropertyInteger('BrightnessVariableID', 0);
        $this->RegisterPropertyInteger('BrightnessThreshold', 40000);
        $this->RegisterPropertyInteger('OutdoorTempVariableID', 0);
        $this->RegisterPropertyFloat('TempThreshold', 24.0);
        
        $this->RegisterPropertyInteger('WindVariableID', 0);
        $this->RegisterPropertyFloat('WindThreshold', 50.0);
        
        $this->RegisterPropertyInteger('SunriseVariableID', 0);
        $this->RegisterPropertyInteger('SunsetVariableID', 0);

        // 2. Rollläden Liste
        $this->RegisterPropertyString('BlindVariables', '[]');

        // Interne Attribute für Sperren und Queue
        $this->RegisterAttributeString('ManualLocks', '{}');
        $this->RegisterAttributeString('LastModuleActions', '{}'); // Um eigene Fahrten von manuellen zu unterscheiden
        $this->RegisterAttributeString('CurrentState', '{}'); // Aktueller Beschattungs-Zustand pro Rollladen
        
        // Status Variablen
        $this->RegisterVariableBoolean('AlarmWindWarning', 'Alarm: Sturmschutz aktiv', '', 1);
        IPS_SetIcon($this->GetIDForIdent('AlarmWindWarning'), 'Warning');
        if (function_exists('IPS_SetVariableCustomPresentation')) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('AlarmWindWarning'), ['PRESENTATION' => 1]);
        }
        $this->EnableAction('AlarmWindWarning');
        
        $this->RegisterVariableInteger('ActiveShadingCount', 'Schatten aktiv (Anzahl)', '', 2);
        IPS_SetIcon($this->GetIDForIdent('ActiveShadingCount'), 'Count');
        
        // Timer für Evaluierung (alle 3 Minuten)
        $this->RegisterTimer('ShadingEvaluator', 0, 'SHSH_EvaluateConditions($_IPS[\'TARGET\']);');
        // Timer für Daily Reset (um Mitternacht)
        $this->RegisterTimer('DailyReset', 0, 'SHSH_ResetDailyLocks($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // --- Auto-generated References ---
        $ref_AzimuthVariableID = $this->ReadPropertyInteger('AzimuthVariableID');
        if ($ref_AzimuthVariableID > 1 && @IPS_ObjectExists($ref_AzimuthVariableID)) {
            $this->RegisterReference($ref_AzimuthVariableID);
        }
        $ref_ElevationVariableID = $this->ReadPropertyInteger('ElevationVariableID');
        if ($ref_ElevationVariableID > 1 && @IPS_ObjectExists($ref_ElevationVariableID)) {
            $this->RegisterReference($ref_ElevationVariableID);
        }
        $ref_BrightnessVariableID = $this->ReadPropertyInteger('BrightnessVariableID');
        if ($ref_BrightnessVariableID > 1 && @IPS_ObjectExists($ref_BrightnessVariableID)) {
            $this->RegisterReference($ref_BrightnessVariableID);
        }
        $ref_OutdoorTempVariableID = $this->ReadPropertyInteger('OutdoorTempVariableID');
        if ($ref_OutdoorTempVariableID > 1 && @IPS_ObjectExists($ref_OutdoorTempVariableID)) {
            $this->RegisterReference($ref_OutdoorTempVariableID);
        }
        $ref_WindVariableID = $this->ReadPropertyInteger('WindVariableID');
        if ($ref_WindVariableID > 1 && @IPS_ObjectExists($ref_WindVariableID)) {
            $this->RegisterReference($ref_WindVariableID);
        }
        $ref_SunriseVariableID = $this->ReadPropertyInteger('SunriseVariableID');
        if ($ref_SunriseVariableID > 1 && @IPS_ObjectExists($ref_SunriseVariableID)) {
            $this->RegisterReference($ref_SunriseVariableID);
        }
        $ref_SunsetVariableID = $this->ReadPropertyInteger('SunsetVariableID');
        if ($ref_SunsetVariableID > 1 && @IPS_ObjectExists($ref_SunsetVariableID)) {
            $this->RegisterReference($ref_SunsetVariableID);
        }
        // ---------------------------------

        
        // Timer aktivieren
        $this->SetTimerInterval('ShadingEvaluator', 3 * 60 * 1000); // 3 Minuten
        
        $now = time();
        $nextRun = strtotime("tomorrow 00:05:00");
        $diff = $nextRun - $now;
        $this->SetTimerInterval('DailyReset', $diff * 1000);

        // Nachrichten für Rollläden und Fensterkontakte registrieren
        $this->UpdateMessageRegistrations();
        
        // Variable Profile für Status
        
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('ActiveShadingCount'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'WindowBlind'
        ]);
    }
    
    private function UpdateMessageRegistrations(): void
    {
        // Alle alten Registrierungen löschen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        
        $blindsJson = $this->ReadPropertyString('BlindVariables');
        $blinds = json_decode($blindsJson, true);
        if (!is_array($blinds)) return;
        
        foreach ($blinds as $blind) {
            $varID = $blind['VariableID'] ?? 0;
            if ($varID > 0 && IPS_VariableExists($varID)) {
                $this->RegisterMessage($varID, VM_UPDATE);
            }
            
            $contactID = $blind['ContactID'] ?? 0;
            if ($contactID > 0 && IPS_VariableExists($contactID)) {
                $this->RegisterMessage($contactID, VM_UPDATE);
            }
        }
        
        $windVar = $this->ReadPropertyInteger('WindVariableID');
        if ($windVar > 0 && IPS_VariableExists($windVar)) {
            $this->RegisterMessage($windVar, VM_UPDATE);
        }
    }
    
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message == VM_UPDATE) {
            $blindsJson = $this->ReadPropertyString('BlindVariables');
            $blinds = json_decode($blindsJson, true);
            if (!is_array($blinds)) return;
            
            foreach ($blinds as $blind) {
                $varID = $blind['VariableID'] ?? 0;
                $contactID = $blind['ContactID'] ?? 0;
                
                if ($SenderID == $varID) {
                    $this->CheckManualOperation($varID, $Data[0]);
                }
                
                if ($SenderID == $contactID) {
                    // Fensterkontakt hat sich geändert -> Sofort evaluieren
                    $this->EvaluateConditions();
                }
            }
            
            $windVar = $this->ReadPropertyInteger('WindVariableID');
            if ($windVar > 0 && $SenderID == $windVar) {
                $this->CheckWind();
            }
        }
    }
    
    public function RequestAction(string $Ident, $Value): void
    {
        if ($Ident === 'AlarmWindWarning') {
            $this->SetValue($Ident, false);
        }
    }
    
    private function CheckWind(): void
    {
        $windVar = $this->ReadPropertyInteger('WindVariableID');
        if ($windVar <= 0 || !IPS_VariableExists($windVar)) return;
        
        $windSpeed = (float)GetValue($windVar);
        $windThreshold = $this->ReadPropertyFloat('WindThreshold');
        
        if ($windSpeed >= $windThreshold) {
            if (!$this->GetValue('AlarmWindWarning')) {
                $this->SetValue('AlarmWindWarning', true);
                IPS_LogMessage('SmartHomeShading', "Sturmwarnung! Windgeschwindigkeit: $windSpeed km/h. Alle Rollläden werden zum Schutz hochgefahren.");
                
                // Alle Rollläden hochfahren
                $blindsJson = $this->ReadPropertyString('BlindVariables');
                $blinds = json_decode($blindsJson, true);
                if (is_array($blinds)) {
                    $states = json_decode($this->ReadAttributeString('CurrentState'), true);
                    $actions = json_decode($this->ReadAttributeString('LastModuleActions'), true);
                    foreach ($blinds as $blind) {
                        $varID = $blind['VariableID'] ?? 0;
                        if ($varID > 0 && IPS_VariableExists($varID)) {
                            RequestAction($varID, 1.0); // 1.0 = Komplett auf (Sicherheits-Position)
                            $actions[$varID] = time();
                            $states[$varID] = false; // Beschattung inaktiv
                        }
                    }
                    $this->WriteAttributeString('CurrentState', json_encode($states));
                    $this->WriteAttributeString('LastModuleActions', json_encode($actions));
                    $this->SetValue('ActiveShadingCount', 0);
                }
            }
        } else {
            if ($this->GetValue('AlarmWindWarning')) {
                $this->SetValue('AlarmWindWarning', false);
                IPS_LogMessage('SmartHomeShading', "Sturmwarnung aufgehoben.");
                $this->EvaluateConditions();
            }
        }
    }
    
    private function CheckManualOperation(int $varID, $newValue): void
    {
        $lastActions = json_decode($this->ReadAttributeString('LastModuleActions'), true);
        $lastTime = $lastActions[$varID] ?? 0;
        
        // Wenn die letzte Modul-Fahrt weniger als 15 Sekunden her ist, war es das Modul
        if ((time() - $lastTime) < 15) {
            return; 
        }
        
        // Ansonsten war es eine manuelle Bedienung am Taster!
        $locks = json_decode($this->ReadAttributeString('ManualLocks'), true);
        if (!isset($locks[$varID]) || !$locks[$varID]) {
            $locks[$varID] = true;
            $this->WriteAttributeString('ManualLocks', json_encode($locks));
            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeShading: Manuelle Bedienung an Rollladen $varID erkannt. Automatik für heute gesperrt.");
        }
    }
    
    public function ResetDailyLocks(): void
    {
        $this->WriteAttributeString('ManualLocks', '{}');
        $this->WriteAttributeString('CurrentState', '{}');
        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeShading: Tägliche Sperren zurückgesetzt.");
        
        // Timer für nächsten Tag
        $now = time();
        $nextRun = strtotime("tomorrow 00:05:00");
        $diff = $nextRun - $now;
        $this->SetTimerInterval('DailyReset', $diff * 1000);
    }
    
    public function EvaluateConditions(): void
    {
        $blindsJson = $this->ReadPropertyString('BlindVariables');
        $blinds = json_decode($blindsJson, true);
        if (!is_array($blinds) || count($blinds) === 0) return;
        
        if ($this->GetValue('AlarmWindWarning')) {
            // Sturmschutz hat Priorität, keine Beschattung!
            return;
        }
        
        // Werte lesen
        $azimuth = $this->GetFloatVal('AzimuthVariableID');
        $brightness = $this->GetFloatVal('BrightnessVariableID');
        $brightnessThreshold = $this->ReadPropertyInteger('BrightnessThreshold');
        $temp = $this->GetFloatVal('OutdoorTempVariableID');
        $tempThreshold = $this->ReadPropertyFloat('TempThreshold');
        
        $locks = json_decode($this->ReadAttributeString('ManualLocks'), true);
        $states = json_decode($this->ReadAttributeString('CurrentState'), true);
        
        $isHotAndBright = ($temp >= $tempThreshold && $brightness >= $brightnessThreshold);
        
        $sunriseTime = $this->GetFloatVal('SunriseVariableID');
        $sunsetTime = $this->GetFloatVal('SunsetVariableID');
        $now = time();
        $isNight = false;
        
        // Location-Modul speichert Sonnenauf/untergang als Unix-Timestamp
        if ($sunriseTime > 0 && $sunsetTime > 0) {
            // Wenn es nach Sonnenuntergang oder noch vor Sonnenaufgang ist
            if ($now >= $sunsetTime || $now < $sunriseTime) {
                $isNight = true;
            }
        }
        
        foreach ($blinds as $blind) {
            $id = $blind['VariableID'] ?? 0;
            if ($id <= 0) continue;
            
            // Wenn manuell gesperrt, überspringen
            if (isset($locks[$id]) && $locks[$id] === true) continue;
            
            // Fensterkontakt prüfen
            $contactID = $blind['ContactID'] ?? 0;
            $isOpen = false;
            if ($contactID > 0 && IPS_VariableExists($contactID)) {
                $contactVal = GetValue($contactID);
                if (is_string($contactVal)) {
                    $isOpen = (strtoupper($contactVal) === 'OPEN'|| strtoupper($contactVal) === 'TILTED');
                } elseif (is_bool($contactVal)) {
                    $isOpen = $contactVal;
                } else {
                    $isOpen = ($contactVal > 0);
                }
            }
            
            // Sonnen-Sektor
            $aziFrom = (float)($blind['AzimuthFrom'] ?? 90);
            $aziTo = (float)($blind['AzimuthTo'] ?? 270);
            
            // Ist die Sonne im Sektor? (Auch über 0° hinweg möglich, z.B. 300 bis 40)
            $sunInSector = false;
            if ($aziFrom < $aziTo) {
                $sunInSector = ($azimuth >= $aziFrom && $azimuth <= $aziTo);
            } else {
                $sunInSector = ($azimuth >= $aziFrom || $azimuth <= $aziTo);
            }
            
            $targetState = 'OPEN';
            $targetValueStr = "1"; // Default offen
            
            if ($isNight) {
                $targetState = 'NIGHT';
                $targetValueStr = "0"; // Zu
            } elseif ($sunInSector && $isHotAndBright) {
                $targetState = 'SHADING';
                $targetValueStr = $blind['ValueShade'] ?? "0.1";
            }
            
            // Lüftungs-Position (Schutz vor Aussperren)
            if ($isOpen && $targetState !== 'OPEN') {
                $ventPosStr = $blind['ValueVentilate'] ?? "0.3";
                $ventPos = (float)$ventPosStr;
                $currentTargetPos = (float)$targetValueStr;
                
                // Nur auf Lüftungsposition fahren, wenn der Rollladen ansonsten weiter unten wäre
                if ($currentTargetPos < $ventPos) {
                    $targetState = 'VENTILATE';
                    $targetValueStr = $ventPosStr;
                }
            }
            
            // Nur fahren, wenn sich der Soll-Zustand geändert hat
            $currentState = $states[$id] ?? 'UNKNOWN';
            
            if ($currentState !== $targetState) {
                // Wert in Typ konvertieren und fahren
                $this->ExecuteAction($id, $targetValueStr);
                $states[$id] = $targetState;
                IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeShading: Rollladen $id fährt auf Zustand: $targetState");
            }
        }
        
        $this->WriteAttributeString('CurrentState', json_encode($states));
    }
    
    private function GetFloatVal(string $propName): float
    {
        $varId = $this->ReadPropertyInteger($propName);
        if ($varId > 0 && IPS_VariableExists($varId)) {
            return (float)GetValue($varId);
        }
        return 0.0;
    }

    private function ExecuteAction(int $targetID, string $valStr): void
    {
        if (!IPS_VariableExists($targetID)) return;

        $var = IPS_GetVariable($targetID);
        $val = $valStr;
        
        if ($var['VariableType'] == 0) { // Boolean
            $val = (strtolower($valStr) === 'true'|| $valStr === '1');
        } elseif ($var['VariableType'] == 1) { // Integer
            $val = (int)$valStr;
        } elseif ($var['VariableType'] == 2) { // Float
            $valStr = str_replace(',', '.', $valStr);
            $val = (float)$valStr;
        }
        
        // Timestamp speichern, damit MessageSink es als "Modul-Fahrt"erkennt
        $lastActions = json_decode($this->ReadAttributeString('LastModuleActions'), true);
        $lastActions[$targetID] = time();
        $this->WriteAttributeString('LastModuleActions', json_encode($lastActions));
        
        @RequestAction($targetID, $val);
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartHomeShading: '. $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "ExpansionPanel",
            "caption": "⚙ SmartHome Shading - Intelligente Sonnenstands- & Hitzebeschattung",
            "items": [
                {
                    "type": "Label",
                    "caption": "1. Globale Sensorik"
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectVariable",
                    "name": "AzimuthVariableID",
                    "caption": "Sonnen-Azimut (Location-Modul)"
                },
                {
                    "type": "SelectVariable",
                    "name": "ElevationVariableID",
                    "caption": "Sonnen-Höhe (Elevation)"
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectVariable",
                    "name": "BrightnessVariableID",
                    "caption": "Helligkeits-Sensor (Lux)"
                },
                {
                    "type": "NumberSpinner",
                    "name": "BrightnessThreshold",
                    "caption": "Schwellwert Lux (z.B. 40000)",
                    "minimum": 0,
                    "maximum": 200000
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectVariable",
                    "name": "OutdoorTempVariableID",
                    "caption": "Außentemperatur-Sensor (°C)"
                },
                {
                    "type": "NumberSpinner",
                    "name": "TempThreshold",
                    "caption": "Hitze-Schwellwert (°C, z.B. 24)",
                    "minimum": -20,
                    "maximum": 50,
                    "digits": 1
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectVariable",
                    "name": "WindVariableID",
                    "caption": "Wind-Sensor (km/h)"
                },
                {
                    "type": "NumberSpinner",
                    "name": "WindThreshold",
                    "caption": "Sturm-Schutz ab (km/h)",
                    "minimum": 0,
                    "maximum": 150,
                    "digits": 1
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectVariable",
                    "name": "SunriseVariableID",
                    "caption": "Sonnenaufgang Variable (Astro)"
                },
                {
                    "type": "SelectVariable",
                    "name": "SunsetVariableID",
                    "caption": "Sonnenuntergang Variable (Astro)"
                }
            ]
        },
        {
            "type": "Label",
            "caption": "2. Rollläden & Fenster (Homematic: 0=Zu, 1=Auf)"
        },
        {
            "type": "List",
            "name": "BlindVariables",
            "caption": "Rollläden",
            "rowCount": 15,
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "Rollladen Variable",
                    "name": "VariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Fensterkontakt",
                    "name": "ContactID",
                    "width": "150px",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Sonne Azimut Von (°)",
                    "name": "AzimuthFrom",
                    "width": "150px",
                    "add": 90,
                    "edit": {
                        "type": "NumberSpinner",
                        "minimum": 0,
                        "maximum": 360
                    }
                },
                {
                    "caption": "Sonne Azimut Bis (°)",
                    "name": "AzimuthTo",
                    "width": "150px",
                    "add": 270,
                    "edit": {
                        "type": "NumberSpinner",
                        "minimum": 0,
                        "maximum": 360
                    }
                },
                {
                    "caption": "Schatten-Pos",
                    "name": "ValueShade",
                    "width": "150px",
                    "add": "0.1",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Lüftungs-Pos",
                    "name": "ValueVentilate",
                    "width": "150px",
                    "add": "0.3",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                }
            ]
        }
    ]
}
EOT;
    }
}


