<?php

declare(strict_types=1);

require_once __DIR__ . '/../SmartLog/libs/Trait_SmartLog.php';

class SmartHomeControl extends IPSModuleStrict
{
    use SmartLog_Trait;

    public function Create(): void
    {
        parent::Create();

        // Instance links
        $this->RegisterPropertyInteger('HeatingInstance', 0);
        $this->RegisterPropertyBoolean('EnableHeating', true);

        $this->RegisterPropertyInteger('SecurityInstance', 0);
        $this->RegisterPropertyBoolean('EnableSecurity', true);

        $this->RegisterPropertyInteger('LightingInstance', 0);
        $this->RegisterPropertyBoolean('EnableLighting', true);
        
        $this->RegisterPropertyInteger('ActiveLightingInstance', 0);
        $this->RegisterPropertyBoolean('EnableActiveLighting', true);
        
        $this->RegisterPropertyInteger('ShadingInstance', 0);
        $this->RegisterPropertyBoolean('EnableShading', true);
        
        $this->RegisterPropertyInteger('LawnInstance', 0);
        $this->RegisterPropertyBoolean('EnableLawn', true);
        
        $this->RegisterPropertyString('GarageInstances', '[]');
        $this->RegisterPropertyBoolean('EnableGarage', true);
        
        $defaultModes = [
            ['ModeID'=> 0, 'ModeName'=> 'Anwesenheit', 'ICON'=> 'House', 'Color'=> -1, 'IsAbsence'=> false, 'IsSleep'=> false, 'SequencerInstance'=> 0, 'NotifyHeating'=> true, 'NotifyLighting'=> true, 'NotifyActiveLighting'=> true, 'NotifySecurity'=> true, 'NotifyShading'=> true, 'NotifyLawn'=> true, 'NotifyGarage'=> true],
            ['ModeID'=> 1, 'ModeName'=> 'Abwesenheit', 'ICON'=> 'Motion', 'Color'=> -1, 'IsAbsence'=> true, 'IsSleep'=> false, 'SequencerInstance'=> 0, 'NotifyHeating'=> true, 'NotifyLighting'=> true, 'NotifyActiveLighting'=> true, 'NotifySecurity'=> true, 'NotifyShading'=> true, 'NotifyLawn'=> true, 'NotifyGarage'=> true],
            ['ModeID'=> 2, 'ModeName'=> 'Urlaub', 'ICON'=> 'Suitcase', 'Color'=> -1, 'IsAbsence'=> true, 'IsSleep'=> false, 'SequencerInstance'=> 0, 'NotifyHeating'=> true, 'NotifyLighting'=> true, 'NotifyActiveLighting'=> true, 'NotifySecurity'=> true, 'NotifyShading'=> true, 'NotifyLawn'=> true, 'NotifyGarage'=> true],
            ['ModeID'=> 5, 'ModeName'=> 'Schlafen', 'ICON'=> 'Moon', 'Color'=> -1, 'IsAbsence'=> false, 'IsSleep'=> true, 'SequencerInstance'=> 0, 'NotifyHeating'=> true, 'NotifyLighting'=> true, 'NotifyActiveLighting'=> true, 'NotifySecurity'=> true, 'NotifyShading'=> true, 'NotifyLawn'=> false, 'NotifyGarage'=> true]
        ];
        $this->RegisterPropertyString('HouseModes', json_encode($defaultModes));
        
        $this->RegisterPropertyString('CalendarURL', '');
        
        $this->RegisterVariableInteger('HouseMode', '🏠 Haus Modus', '', 2);
        IPS_SetIcon($this->GetIDForIdent('HouseMode'), 'Gear');
        $this->EnableAction('HouseMode');
        
        // Google Home / Alexa Interface Variable (Boolean)
        $this->RegisterVariableBoolean('PresenceStatus', 'Anwesenheit (Google Home)', '', 1);
        IPS_SetIcon($this->GetIDForIdent('PresenceStatus'), 'Information');
        $this->EnableAction('PresenceStatus');
        
        // Timer für Kalender-Check
        $this->RegisterTimer('CalendarCheck', 0, 'SHC_CheckCalendar($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $ref_HeatingInstance = $this->ReadPropertyInteger('HeatingInstance');
        if ($ref_HeatingInstance > 1 && @IPS_ObjectExists($ref_HeatingInstance)) {
            $this->RegisterReference($ref_HeatingInstance);
        }
        $ref_SecurityInstance = $this->ReadPropertyInteger('SecurityInstance');
        if ($ref_SecurityInstance > 1 && @IPS_ObjectExists($ref_SecurityInstance)) {
            $this->RegisterReference($ref_SecurityInstance);
        }
        $ref_LightingInstance = $this->ReadPropertyInteger('LightingInstance');
        if ($ref_LightingInstance > 1 && @IPS_ObjectExists($ref_LightingInstance)) {
            $this->RegisterReference($ref_LightingInstance);
        }
        $ref_ActiveLightingInstance = $this->ReadPropertyInteger('ActiveLightingInstance');
        if ($ref_ActiveLightingInstance > 1 && @IPS_ObjectExists($ref_ActiveLightingInstance)) {
            $this->RegisterReference($ref_ActiveLightingInstance);
        }
        $ref_ShadingInstance = $this->ReadPropertyInteger('ShadingInstance');
        if ($ref_ShadingInstance > 1 && @IPS_ObjectExists($ref_ShadingInstance)) {
            $this->RegisterReference($ref_ShadingInstance);
        }
        $ref_LawnInstance = $this->ReadPropertyInteger('LawnInstance');
        if ($ref_LawnInstance > 1 && @IPS_ObjectExists($ref_LawnInstance)) {
            $this->RegisterReference($ref_LawnInstance);
        }
        $list_HouseModes = json_decode($this->ReadPropertyString('HouseModes'), true);
        if (is_array($list_HouseModes)) {
            foreach ($list_HouseModes as $item) {
                $vid = $item['SequencerInstance'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        $list_GarageInstances = json_decode($this->ReadPropertyString('GarageInstances'), true);
        if (is_array($list_GarageInstances)) {
            foreach ($list_GarageInstances as $item) {
                $vid = $item['InstanceID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        // ---------------------------------



        $modesJson = $this->ReadPropertyString('HouseModes');
        $modes = json_decode($modesJson, true);
        if (!is_array($modes)) {
            $modes = [];
        }
        $associations = [];
        if (!IPS_VariableProfileExists('SmartAbsence.HouseMode.'. $this->InstanceID)) {
            IPS_CreateVariableProfile('SmartAbsence.HouseMode.'. $this->InstanceID, 1);
        }
        foreach ($modes as $mode) {
            IPS_SetVariableProfileAssociation('SmartAbsence.HouseMode.'. $this->InstanceID, $mode['ModeID'], $mode['ModeName'], $mode['Icon'], $mode['Color']);
        }
        
        IPS_SetVariableCustomProfile($this->GetIDForIdent('HouseMode'), 'SmartAbsence.HouseMode.'. $this->InstanceID);
        
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('PresenceStatus'), [
            'PRESENTATION'  => VARIABLE_PRESENTATION_SWITCH
        ]);
        
        $this->MaintainVariable('AbsenceStatus', '', 0, '', 0, false);

        // Timer starten (alle 30 Minuten)
        $this->SetTimerInterval('CalendarCheck', 30 * 60 * 1000);

        $this->SetStatus(102);
    }

    public function RequestAction(string $Ident, $Value): void
    {
        if ($Ident == 'HouseMode') {
            $this->SetHouseMode($Value);
        }
        
        // Google Home Toggle
        if ($Ident == 'PresenceStatus') {
            $mode = $Value ? 0 : 1; // true = Anwesenheit, false = Abwesenheit
            $this->SetHouseMode($mode);
        }
    }

    public function SetHouseMode(int $newMode, int $vacationEndTime = 0): void
    {
        $oldMode = $this->GetValue('HouseMode');
        
        if ($oldMode != $newMode) {
            $this->TriggerDeactivationSequence($oldMode);
        }
        
        $this->SetValue('HouseMode', $newMode);
        $this->SetValue('PresenceStatus', ($newMode != 1 && $newMode != 2));
        
        $this->ApplyHouseModeState($newMode, $vacationEndTime);
    }
    
    private function TriggerDeactivationSequence(int $mode): void
    {
        $modesJson = $this->ReadPropertyString('HouseModes');
        $modes = json_decode($modesJson, true);
        if (is_array($modes)) {
            foreach ($modes as $m) {
                if ($m['ModeID'] == $mode) {
                    $seqInst = $m['SequencerInstance'] ?? 0;
                    if ($seqInst > 0 && IPS_InstanceExists($seqInst) && function_exists('SHSQ_RunDeactivationSequence')) {
                        SHSQ_RunDeactivationSequence($seqInst);
                        $this->SLog('INFO', "Austritts-Sequenz für Modus '" . $m['ModeName'] . "' ausgeführt.");
                    }
                    break;
                }
            }
        }
    }

    private function ApplyHouseModeState(int $mode, int $vacationEndTime = 0): void
    {
        $heatingInst = $this->ReadPropertyInteger('HeatingInstance');
        $secInst = $this->ReadPropertyInteger('SecurityInstance');
        $lightInst = $this->ReadPropertyInteger('LightingInstance');
        $activeLightInst = $this->ReadPropertyInteger('ActiveLightingInstance');
        $shadeInst = $this->ReadPropertyInteger('ShadingInstance');
        $lawnInst = $this->ReadPropertyInteger('LawnInstance');
        $garageInstsJson = $this->ReadPropertyString('GarageInstances');

        $modesJson = $this->ReadPropertyString('HouseModes');
        $modes = json_decode($modesJson, true);
        
        $currentModeConfig = null;
        if (is_array($modes)) {
            foreach ($modes as $m) {
                if ($m['ModeID'] == $mode) {
                    $currentModeConfig = $m;
                    break;
                }
            }
        }
        
        $modeName = $currentModeConfig ? $currentModeConfig['ModeName'] : "Unbekannt ($mode)";
        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeControl: Haus-Modus gewechselt auf ". $modeName);

        // Standard-Matrix (falls nichts konfiguriert ist, alles ausführen)
        $notifyHeating = $currentModeConfig ? ($currentModeConfig['NotifyHeating'] ?? true) : true;
        $notifySecurity = $currentModeConfig ? ($currentModeConfig['NotifySecurity'] ?? true) : true;
        $notifyLighting = $currentModeConfig ? ($currentModeConfig['NotifyLighting'] ?? true) : true;
        $notifyActiveLighting = $currentModeConfig ? ($currentModeConfig['NotifyActiveLighting'] ?? true) : true;
        $notifyShading = $currentModeConfig ? ($currentModeConfig['NotifyShading'] ?? true) : true;
        $notifyLawn = $currentModeConfig ? ($currentModeConfig['NotifyLawn'] ?? true) : true;
        $notifyGarage = $currentModeConfig ? ($currentModeConfig['NotifyGarage'] ?? true) : true;
        $sequencerInst = $currentModeConfig ? ($currentModeConfig['SequencerInstance'] ?? 0) : 0;
        
        $isAbsence = $currentModeConfig ? ($currentModeConfig['IsAbsence'] ?? ($mode == 1 || $mode == 2)) : ($mode == 1 || $mode == 2);
        $isSleep = $currentModeConfig ? ($currentModeConfig['IsSleep'] ?? ($mode == 5)) : ($mode == 5);

        $this->SLog('INFO', "Modus gewechselt auf: " . $modeName);

        if ($notifyHeating && $this->ReadPropertyBoolean('EnableHeating') && $heatingInst > 0 && IPS_InstanceExists($heatingInst) && function_exists('SHH_SetHouseMode')) {
            SHH_SetHouseMode($heatingInst, $mode, $isAbsence, $isSleep, $vacationEndTime);
        }

        if ($notifySecurity && $this->ReadPropertyBoolean('EnableSecurity') && $secInst > 0 && IPS_InstanceExists($secInst) && function_exists('SHS_SetHouseMode')) {
            SHS_SetHouseMode($secInst, $mode, $isAbsence, $isSleep);
        }

        if ($notifyLighting && $this->ReadPropertyBoolean('EnableLighting') && $lightInst > 0 && IPS_InstanceExists($lightInst) && function_exists('SHL_SetHouseMode')) {
            SHL_SetHouseMode($lightInst, $mode, $isAbsence, $isSleep);
        }
        
        if ($notifyActiveLighting && $this->ReadPropertyBoolean('EnableActiveLighting') && $activeLightInst > 0 && IPS_InstanceExists($activeLightInst) && function_exists('SAL_SetHouseMode')) {
            SAL_SetHouseMode($activeLightInst, $mode, $isAbsence, $isSleep);
        }
        
        if ($notifyShading && $this->ReadPropertyBoolean('EnableShading') && $shadeInst > 0 && IPS_InstanceExists($shadeInst) && function_exists('SHSH_SetHouseMode')) {
            SHSH_SetHouseMode($shadeInst, $mode, $isAbsence, $isSleep);
        }
        
        if ($notifyLawn && $this->ReadPropertyBoolean('EnableLawn') && $lawnInst > 0 && IPS_InstanceExists($lawnInst) && function_exists('SLAI_SetHouseMode')) {
            SLAI_SetHouseMode($lawnInst, $mode, $isAbsence, $isSleep);
        }
        
        if ($notifyGarage && $this->ReadPropertyBoolean('EnableGarage')) {
            $garageInsts = json_decode($garageInstsJson, true);
            if (is_array($garageInsts)) {
                foreach ($garageInsts as $garage) {
                    if (isset($garage['InstanceID'])) {
                        $gId = $garage['InstanceID'];
                        if ($gId > 0 && IPS_InstanceExists($gId) && function_exists('SHG_SetHouseMode')) {
                            SHG_SetHouseMode($gId, $mode, $isAbsence, $isSleep);
                        }
                    }
                }
            }
        }
        
        if ($sequencerInst > 0 && IPS_InstanceExists($sequencerInst) && function_exists('SHSQ_RunSequence')) {
            SHSQ_RunSequence($sequencerInst);
            $this->SLog('INFO', 'Eintritts-Sequenz ausgeführt.');
        }

    }
    
    public function CheckCalendar(): void
    {
        $url = $this->ReadPropertyString('CalendarURL');
        if (empty($url)) {
            $this->SLog('DEBUG', 'CheckCalendar: Keine iCal-URL hinterlegt.');
            return;
        }
        
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $icalData = @file_get_contents($url, false, $ctx);
        if ($icalData === false) {
            $error = error_get_last();
            $this->SLog('ERROR', 'CheckCalendar: Konnte Kalenderdaten nicht abrufen.', $error['message'] ?? 'Unbekannter Fehler');
            return;
        }
        
        // Sehr simpler iCal Parser für VEVENT
        $events = [];
        $lines = explode("\n", $icalData);
        $currentEvent = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === 'BEGIN:VEVENT') {
                $currentEvent = [];
            } elseif ($line === 'END:VEVENT') {
                if ($currentEvent !== null) {
                    $events[] = $currentEvent;
                    $currentEvent = null;
                }
            } elseif ($currentEvent !== null) {
                if (strpos($line, 'SUMMARY:') === 0) {
                    $currentEvent['SUMMARY'] = substr($line, 8);
                } elseif (strpos($line, 'DTSTART') === 0) {
                    $parts = explode(':', $line);
                    if (count($parts) >= 2) {
                        $currentEvent['DTSTART'] = strtotime($parts[1]);
                    }
                } elseif (strpos($line, 'DTEND') === 0) {
                    $parts = explode(':', $line);
                    if (count($parts) >= 2) {
                        $currentEvent['DTEND'] = strtotime($parts[1]);
                    }
                }
            }
        }
        
        $now = time();
        $vacationFound = false;
        $vacationEndTime = 0;
        
        foreach ($events as $event) {
            if (isset($event['SUMMARY']) && strtoupper(trim($event['SUMMARY'])) === 'URLAUB') {
                if (isset($event['DTSTART']) && isset($event['DTEND'])) {
                    if ($now >= $event['DTSTART'] && $now <= $event['DTEND']) {
                        $vacationFound = true;
                        $vacationEndTime = $event['DTEND'];
                        break;
                    }
                }
            }
        }
        
        $currentMode = GetValue($this->GetIDForIdent('HouseMode'));
        
        if ($vacationFound && $currentMode !== 2) {
            $this->SLog('INFO', 'Kalender: Urlaubstermin aktiv! Wechsle in den Urlaubs-Modus.', 'Ende: ' . date('d.m. H:i', $vacationEndTime));
            $this->SetHouseMode(2, $vacationEndTime);
        } elseif (!$vacationFound && $currentMode === 2) {
            $this->SLog('INFO', 'Kalender: Urlaubstermin beendet! Wechsle zurück auf Anwesenheit.');
            $this->SetHouseMode(0);
        } elseif (!$vacationFound) {
            $this->SLog('DEBUG', 'Kalender geprüft: Aktuell ist kein Urlaub eingetragen.');
        } else {
            $this->SLog('DEBUG', 'Kalender geprüft: Urlaub ist aktiv.', 'Ende: ' . date('d.m. H:i', $vacationEndTime));
        }
    }


    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartHomeControl: '. $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "ExpansionPanel",
            "caption": "⚙ Orchestrierung der SmartHome-Submodule",
            "items": []
        },
        {
            "type": "List",
            "name": "HouseModes",
            "caption": "Haus-Modi (Matrix & Zuweisungen)",
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "ID",
                    "name": "ModeID",
                    "width": "60px",
                    "add": 0,
                    "edit": {
                        "type": "NumberSpinner"
                    }
                },
                {
                    "caption": "Modus Name",
                    "name": "ModeName",
                    "width": "auto",
                    "add": "Neuer Modus",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Icon",
                    "name": "Icon",
                    "width": "150px",
                    "add": "",
                    "edit": {
                        "type": "SelectIcon"
                    }
                },
                {
                    "caption": "Farbe",
                    "name": "Color",
                    "width": "80px",
                    "add": -1,
                    "edit": {
                        "type": "SelectColor"
                    }
                },
                {
                    "caption": "Ist Abwesenheit?",
                    "name": "IsAbsence",
                    "width": "110px",
                    "add": false,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Ist Schlafen?",
                    "name": "IsSleep",
                    "width": "100px",
                    "add": false,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Sequencer Skript",
                    "name": "SequencerInstance",
                    "width": "150px",
                    "add": 0,
                    "edit": {
                        "type": "SelectInstance"
                    }
                },
                {
                    "caption": "Heizung",
                    "name": "NotifyHeating",
                    "width": "70px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Licht",
                    "name": "NotifyLighting",
                    "width": "70px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Licht (Alltag)",
                    "name": "NotifyActiveLighting",
                    "width": "80px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Sicherheit",
                    "name": "NotifySecurity",
                    "width": "70px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Beschattung",
                    "name": "NotifyShading",
                    "width": "80px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Rasen/Bewäs.",
                    "name": "NotifyLawn",
                    "width": "80px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Garage",
                    "name": "NotifyGarage",
                    "width": "70px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectInstance",
                    "name": "HeatingInstance",
                    "caption": "Heating (Thermostat) Instanz"
                },
                {
                    "type": "CheckBox",
                    "name": "EnableHeating",
                    "caption": "Aktiv"
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectInstance",
                    "name": "SecurityInstance",
                    "caption": "Security (Türen/Fenster) Instanz"
                },
                {
                    "type": "CheckBox",
                    "name": "EnableSecurity",
                    "caption": "Aktiv"
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectInstance",
                    "name": "LightingInstance",
                    "caption": "Lighting (Anwesenheits-Sim.)"
                },
                {
                    "type": "CheckBox",
                    "name": "EnableLighting",
                    "caption": "Aktiv"
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectInstance",
                    "name": "ActiveLightingInstance",
                    "caption": "ActiveLighting (Alltags-Steuerung)"
                },
                {
                    "type": "CheckBox",
                    "name": "EnableActiveLighting",
                    "caption": "Aktiv"
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectInstance",
                    "name": "ShadingInstance",
                    "caption": "Shading (Rollläden) Instanz"
                },
                {
                    "type": "CheckBox",
                    "name": "EnableShading",
                    "caption": "Aktiv"
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectInstance",
                    "name": "LawnInstance",
                    "caption": "Rasen / Bewässerung Instanz"
                },
                {
                    "type": "CheckBox",
                    "name": "EnableLawn",
                    "caption": "Aktiv"
                }
            ]
        },
        {
            "type": "List",
            "name": "GarageInstances",
            "caption": "SmartHomeGarage Instanzen",
            "rowCount": 3,
            "add": true,
            "delete": true,
            "columns": [
                {
                    "caption": "Instanz",
                    "name": "InstanceID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectInstance"
                    }
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Hier stellst du 'Garagensteuerung Aktiv' ein."
        },
        {
            "type": "CheckBox",
            "name": "EnableGarage",
            "caption": "Garagensteuerung Aktiv"
        },
        {
            "type": "Label",
            "caption": "Urlaubs-Automatik"
        },
        {
            "type": "ValidationTextBox",
            "name": "CalendarURL",
            "caption": "Google Kalender (iCal) URL (privater Link)"
        }
    ],
    "actions": [
        {
            "type": "Button",
            "caption": "Manueller Kalender Sync",
            "onClick": "SHC_CheckCalendar($id);",
            "icon": "Play"
        }
    ]
}
EOT;
    }
}


