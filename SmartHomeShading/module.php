<?php

declare(strict_types=1);

require_once __DIR__ . '/../SmartLog/libs/Trait_SmartLog.php';

class SmartHomeShading extends IPSModuleStrict
{
    use SmartLog_Trait;
    public function Create(): void
    {
        parent::Create();
        if (function_exists('IPS_SetVariableCustomPresentation')) {
            foreach(['AlarmWindWarning'] as $ident) {
                $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
                if ($id !== false) @IPS_SetVariableCustomPresentation($id, []);
            }
        }

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
        $this->RegisterAttributeString('CurrentState', '{}'); // Aktueller Beschattungs-Zustand pro Rollladen
        
        // Status Variablen
        $this->RegisterVariableBoolean('AlarmWindWarning', 'Alarm: Sturmschutz aktiv', '', 1);
        IPS_SetIcon($this->GetIDForIdent('AlarmWindWarning'), 'Warning');
        $this->EnableAction('AlarmWindWarning');
        
        $this->RegisterVariableInteger('ActiveShadingCount', 'Schatten aktiv (Anzahl)', '', 2);
        IPS_SetIcon($this->GetIDForIdent('ActiveShadingCount'), 'Count');
        
        $this->RegisterVariableBoolean('StatusIsNight', 'Status: Es ist Nacht', '', 10);
        $this->RegisterVariableBoolean('StatusIsHotAndBright', 'Status: Hitze & Helligkeit erreicht', '', 11);
        $this->RegisterVariableInteger('StatusSunInSectorCount', 'Status: Rollläden in der Sonne (Anzahl)', '', 12);
        $this->RegisterVariableInteger('StatusLastEvaluation', 'Status: Letzte Berechnung', '', 13);
        
        // Timer für Evaluierung (alle 3 Minuten)
        $this->RegisterTimer('ShadingEvaluator', 0, 'SHSH_EvaluateConditions($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
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
        $list_BlindVariables = json_decode($this->ReadPropertyString('BlindVariables'), true);
        if (is_array($list_BlindVariables)) {
            foreach ($list_BlindVariables as $item) {
                $vid = $item['VariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
                $vid = $item['ContactID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        // ---------------------------------

        
        // Timer aktivieren
        $this->SetTimerInterval('ShadingEvaluator', 3 * 60 * 1000); // 3 Minuten


        // Nachrichten für Rollläden und Fensterkontakte registrieren
        $this->UpdateMessageRegistrations();
        
        // Variable Profile für Status
        
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('ActiveShadingCount'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'WindowBlind'
        ]);
        
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('StatusIsNight'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'Moon'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('StatusIsHotAndBright'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'Sun'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('StatusSunInSectorCount'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'Count'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('StatusLastEvaluation'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_DATE_TIME,
            'ICON'        => 'Clock'
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
                $contactID = $blind['ContactID'] ?? 0;
                
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

        $windSpeed = GetValue($windVar);
        $threshold = $this->ReadPropertyFloat('WindThreshold');
        if ($windSpeed >= $threshold) {
            $this->SetValue('AlarmWindWarning', true);
            $this->SLog('WARNING', 'Sturmwarnung aktiv — Rollläden werden eingefahren.', "Windgeschwindigkeit: {$windSpeed} | Schwelle: {$threshold}");
        }
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
                    "caption": "Willkommen bei SmartHome Shading! Lass uns deine Rollläden intelligent machen."
                },
                {
                    "type": "Label",
                    "caption": "1. Globale Sensorik"
                },
                {
                    "type": "Label",
                    "caption": "Hier wählst du die Sensoren für den Sonnenstand aus. Diese benötigt das Modul, um zu wissen, wo die Sonne gerade steht:"
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
                    "type": "Label",
                    "caption": "Ab wie viel Lux soll beschattet werden? Wähle deinen Helligkeitssensor und den Schwellwert:"
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
                    "type": "Label",
                    "caption": "Ab welcher Temperatur wird es dir zu warm im Haus? Beschattung startet nur, wenn es draußen heißer ist als dieser Wert:"
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
                    "type": "Label",
                    "caption": "Sturmschutz: Ab welcher Windgeschwindigkeit sollen die Rollläden zum Schutz hochfahren?"
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
                    "type": "Label",
                    "caption": "Damit die Rollläden abends automatisch schließen und morgens öffnen, wähle hier die Astro-Variablen:"
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
                }
            ]
        },
        {
            "type": "Label",
            "caption": "2. Rollläden & Fenster (0=Auf, 1=Zu)"
        },
        {
            "type": "Label",
            "caption": "Hier legst du deine Rollläden an. Gib an, bei welchem Sonnenstand (Azimut Von/Bis) das Fenster Sonne abbekommt. Trage außerdem die Positionen für 'Auf', 'Zu', 'Beschatten' und 'Lüften' (wenn die Tür offen ist) ein."
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
                    "caption": "Auf-Pos",
                    "name": "ValueOpen",
                    "width": "80px",
                    "add": "0",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Zu-Pos",
                    "name": "ValueClose",
                    "width": "80px",
                    "add": "1",
                    "edit": {
                        "type": "ValidationTextBox"
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
                    "caption": "Tür/Fenster Offen Position",
                    "name": "ValueVentilate",
                    "width": "200px",
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


