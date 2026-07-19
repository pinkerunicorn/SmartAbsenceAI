<?php

declare(strict_types=1);

class SmartHomeLighting extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Gemini API-Key und Modell werden zentral über SmartGeminiIO konfiguriert.
        $this->RegisterPropertyInteger('SunsetVariableID', 0);
        $this->RegisterPropertyInteger('ArchiveControlID', 0);
        $this->RegisterPropertyString('LightVariables', '[]');
        $this->RegisterPropertyString('DimmerVariables', '[]');

        $this->RegisterAttributeString('LightSchedule', '[]');

        $this->RegisterVariableString('LightScheduleStatus', 'ℹ Aktueller KI-Schaltplan', '', 1);
        IPS_SetIcon($this->GetIDForIdent('LightScheduleStatus'), 'Information');
        $this->RegisterVariableBoolean('GeminiError', 'Fehler aufgetreten', '', 2);
        IPS_SetIcon($this->GetIDForIdent('GeminiError'), 'Warning');
        
        $this->RegisterVariableInteger('ActiveLightsCount', '💡 Aktive Lampen (Zähler)', '', 3);
        IPS_SetIcon($this->GetIDForIdent('ActiveLightsCount'), 'Bulb');
        $this->RegisterVariableString('ActiveLightsList', '📝 Aktive Lampen (Namen)', '', 4);
        IPS_SetIcon($this->GetIDForIdent('ActiveLightsList'), 'Bulb');
        $this->RegisterVariableBoolean('AlarmLightsOnDuringAbsence', 'Alarm: Licht brennt bei Abwesenheit', '', 5);
        IPS_SetIcon($this->GetIDForIdent('AlarmLightsOnDuringAbsence'), 'Warning');
        $this->EnableAction('AlarmLightsOnDuringAbsence');
        
        $this->RegisterVariableString('VestaboardMessage', 'Vestaboard Nachricht', '', 6);
        IPS_SetIcon($this->GetIDForIdent('VestaboardMessage'), 'Information');

        $this->RegisterTimer('LightExecutionTimer', 0, 'SHL_CheckAndExecuteLightSchedule($_IPS[\'TARGET\']);');
        $this->RegisterTimer('GeminiRetryTimer', 0, 'SHL_GenerateAiSchedule($_IPS[\'TARGET\'], true);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $ref_SunsetVariableID = $this->ReadPropertyInteger('SunsetVariableID');
        if ($ref_SunsetVariableID > 1 && @IPS_ObjectExists($ref_SunsetVariableID)) {
            $this->RegisterReference($ref_SunsetVariableID);
        }
        $ref_ArchiveControlID = $this->ReadPropertyInteger('ArchiveControlID');
        if ($ref_ArchiveControlID > 1 && @IPS_ObjectExists($ref_ArchiveControlID)) {
            $this->RegisterReference($ref_ArchiveControlID);
        }
        $list_LightVariables = json_decode($this->ReadPropertyString('LightVariables'), true);
        if (is_array($list_LightVariables)) {
            foreach ($list_LightVariables as $item) {
                $vid = $item['VariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        $list_DimmerVariables = json_decode($this->ReadPropertyString('DimmerVariables'), true);
        if (is_array($list_DimmerVariables)) {
            foreach ($list_DimmerVariables as $item) {
                $vid = $item['VariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        // ---------------------------------


        $this->MaintainVariable('LightScheduleStatus', 'Aktueller KI-Schaltplan', 3, '', 1, true);
        $this->MaintainVariable('GeminiError', 'Fehler aufgetreten', 0, '', 2, true);
        $this->MaintainVariable('ActiveLightsCount', 'Aktive Lampen (Zähler)', 1, '', 3, true);
        $this->MaintainVariable('ActiveLightsList', 'Aktive Lampen (Namen)', 3, '', 4, true);
        $this->MaintainVariable('VestaboardMessage', 'Vestaboard Nachricht', 3, '', 5, true);

        IPS_SetIcon($this->GetIDForIdent('LightScheduleStatus'), 'Clock');
        IPS_SetIcon($this->GetIDForIdent('GeminiError'), 'Warning');
        IPS_SetIcon($this->GetIDForIdent('ActiveLightsCount'), 'Bulb');
        IPS_SetIcon($this->GetIDForIdent('ActiveLightsList'), 'Bulb');
        IPS_SetIcon($this->GetIDForIdent('VestaboardMessage'), 'Information');
        IPS_SetIcon($this->GetIDForIdent('AlarmLightsOnDuringAbsence'), 'Warning');

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('GeminiError'), [
            'PRESENTATION'  => VARIABLE_PRESENTATION_SWITCH,
            'GLOW_COLOR'    => 16711680, // Rot
            'GLOW_INTENSITY'=> 50
        ]);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('ActiveLightsCount'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'      => ' an'
        ]);
        
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('AlarmLightsOnDuringAbsence'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_SWITCH
        ]);

        $geminiInstances = IPS_GetInstanceListByModuleID('{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}');
        if (empty($geminiInstances)) {
            $this->SetStatus(201); // Inactive — SmartGeminiIO fehlt
            return;
        }

        $lightVars = json_decode($this->ReadPropertyString('LightVariables'), true);
        if (is_array($lightVars)) {
            foreach ($lightVars as $light) {
                $id = $light['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
            }
        }
        $dimmerVars = json_decode($this->ReadPropertyString('DimmerVariables'), true);
        if (is_array($dimmerVars)) {
            foreach ($dimmerVars as $light) {
                $id = $light['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
            }
        }
        $this->CalculateActiveLights();
        $this->SetStatus(102);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message == VM_UPDATE) {
            $this->CalculateActiveLights();
        }
    }

    private function CalculateActiveLights(): void
    {
        $lightVars = json_decode($this->ReadPropertyString('LightVariables'), true);
        if (!is_array($lightVars)) $lightVars = [];
        $dimmerVars = json_decode($this->ReadPropertyString('DimmerVariables'), true);
        if (!is_array($dimmerVars)) $dimmerVars = [];
        
        $allVars = array_merge($lightVars, $dimmerVars);
        
        $count = 0;
        $activeNames = [];
        if (is_array($allVars)) {
            foreach ($allVars as $light) {
                $id = $light['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    $currentVal = GetValue($id);
                    $isActive = false;
                    
                    if (is_bool($currentVal)) {
                        $isActive = $currentVal;
                    } else if (is_int($currentVal) || is_float($currentVal)) {
                        $isActive = ($currentVal > 0);
                    } else if (is_string($currentVal)) {
                        $isActive = (strtolower(trim($currentVal)) === 'true'|| trim($currentVal) === '1');
                    }
                    
                    if ($isActive) {
                        $count++;
                        $name = isset($light['Name']) && $light['Name'] != ''? $light['Name'] : IPS_GetName($id);
                        $activeNames[] = $name;
                    }
                }
            }
        }

        $this->SetValueIfChanged('ActiveLightsCount', $count);
        
        if ($count == 0) {
            $this->SetValueIfChanged('ActiveLightsList', 'Alle aus');
            $this->SetValueIfChanged('VestaboardMessage', '');
        } else {
            $namesStr = implode(", ", $activeNames);
            $this->SetValueIfChanged('ActiveLightsList', $namesStr);
            $suffix = ($count == 1) ? ' Lampe an' : ' Lampen an';
            $this->SetValueIfChanged('VestaboardMessage', $count . $suffix);
        }
    }

    public function GetActiveLights(): array
    {
        $this->CalculateActiveLights();
        $count = GetValue($this->GetIDForIdent('ActiveLightsCount'));
        if ($count > 0) {
            $list = GetValue($this->GetIDForIdent('ActiveLightsList'));
            return explode(", ", $list);
        }
        return [];
    }

    public function SetHouseMode(int $mode, bool $isAbsence = false, bool $isSleep = false): void
    {
        $isAbsence = ($isAbsence || $mode == 1 || $mode == 2);
        $isSleep = ($isSleep || $mode == 5);
        
        $eid = $this->MaintainDailyEvent();
        
        if ($isAbsence) {
            $this->GenerateAiSchedule();
            IPS_SetEventActive($eid, true);
            $this->SetTimerInterval('LightExecutionTimer', 60000);
            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeLighting: Präsenzsimulation gestartet.");
            $this->TurnOffAllSimulatedLights(); // Zuerst alles aus
            
            // Check if any lights are STILL on (meaning they were manually turned on and forgotten)
            $this->CalculateActiveLights();
            if ($this->GetValue('ActiveLightsCount') > 0) {
                $this->SetValueIfChanged('AlarmLightsOnDuringAbsence', true);
                IPS_LogMessage('SmartHomeLighting', "Alarm: Bei Abwesenheit ist noch Licht an!");
            }
        } else {
            // Wenn Präsenzsimulation lief, schalten wir sie ab
            $wasActive = IPS_GetEvent($eid)['EventActive'];
            
            IPS_SetEventActive($eid, false);
            $this->SetTimerInterval('LightExecutionTimer', 0);
            $this->SetTimerInterval('GeminiRetryTimer', 0);
            $this->WriteAttributeString('LightSchedule', '[]');
            $this->SetValueIfChanged('LightScheduleStatus', 'Abwesenheit inaktiv - Kein Plan generiert');
            $this->SetValueIfChanged('GeminiError', false);
            
            if ($isSleep) { // Schlafen
                $this->TurnOffAllSimulatedLights();
                IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeLighting: Schlafen aktiv - Alle Lichter aus.");
            } else {
                // Bei Rückkehr (0, 3, 4) machen wir die simulierten Lichter aus, 
                // aber nur wenn die Simulation davor lief.
                if ($wasActive) {
                    $this->TurnOffAllSimulatedLights(true);
                    IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeLighting: Präsenzsimulation gestoppt und Lichter aus.");
                }
            }
        }
    }

    public function GenerateAiSchedule(bool $isRetry = false): void
    {
        if (!$isRetry) {
            $this->SetBuffer('GeminiRetryCount', '0');
            $this->SetTimerInterval('GeminiRetryTimer', 0);
        }

        $geminiInstances = IPS_GetInstanceListByModuleID('{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}');
        if (empty($geminiInstances)) {
            $this->SetValue('GeminiError', true);
            return;
        }
        $geminiId = $geminiInstances[0];
        $sunsetVarId = $this->ReadPropertyInteger('SunsetVariableID');
        $archiveId = $this->ReadPropertyInteger('ArchiveControlID');

        $this->SetValue('GeminiError', false);
        $this->SetValue('LightScheduleStatus', 'Starte KI-Generierung... Bitte warten.');

        if ($sunsetVarId == 0 || $archiveId == 0) {
            $this->SetValue('GeminiError', true);
            return;
        }

        $sunsetTimeStr = "18:00";
        if (IPS_VariableExists($sunsetVarId)) {
            $val = GetValue($sunsetVarId);
            if (is_int($val)) {
                $sunsetTimeStr = date('H:i', $val);
            } else {
                $sunsetTimeStr = (string)$val;
            }
        }

        $lightVars = json_decode($this->ReadPropertyString('LightVariables'), true);
        if (!is_array($lightVars)) $lightVars = [];
        $dimmerVars = json_decode($this->ReadPropertyString('DimmerVariables'), true);
        if (!is_array($dimmerVars)) $dimmerVars = [];
        
        if (count($lightVars) == 0 && count($dimmerVars) == 0) return;

        $startTime = time() - (14 * 24 * 60 * 60);
        $endTime = time();
        $historyDataSwitches = [];
        $historyDataDimmers = [];

        foreach ($lightVars as $light) {
            $id = $light['VariableID'];
            $name = isset($light['Name']) && $light['Name'] != ''? $light['Name'] : "Schalter ".$id;
            if ($id > 0) {
                if (!AC_GetLoggingStatus($archiveId, $id)) continue;
                $values = AC_GetLoggedValues($archiveId, $id, $startTime, $endTime, 50);
                $compactLog = [];
                foreach ($values as $v) {
                    $compactLog[] = ["time"=> date('Y-m-d H:i', $v['TimeStamp']), "val"=> $v['Value']];
                }
                $historyDataSwitches[$id] = [
                    "name"=> $name,
                    "log"=> $compactLog
                ];
            }
        }
        
        foreach ($dimmerVars as $light) {
            $id = $light['VariableID'];
            $name = isset($light['Name']) && $light['Name'] != ''? $light['Name'] : "Dimmer ".$id;
            if ($id > 0) {
                if (!AC_GetLoggingStatus($archiveId, $id)) continue;
                $values = AC_GetLoggedValues($archiveId, $id, $startTime, $endTime, 50);
                $compactLog = [];
                foreach ($values as $v) {
                    $compactLog[] = ["time"=> date('Y-m-d H:i', $v['TimeStamp']), "val"=> $v['Value']];
                }
                $historyDataDimmers[$id] = [
                    "name"=> $name,
                    "log"=> $compactLog
                ];
            }
        }

        $prompt = "Du bist eine Smart Home KI. Heute ist der ". date('Y-m-d') . ". Der Sonnenuntergang ist um ". $sunsetTimeStr . "Uhr.\n";
        $prompt .= "Hier sind die Schaltdaten der Lichter der letzten 14 Tage inkl. Name/Raum als JSON:\n";
        if (count($historyDataSwitches) > 0) {
            $prompt .= "Geräte vom Typ SCHALTER (Werte: true/false):\n". json_encode($historyDataSwitches) . "\n";
        }
        if (count($historyDataDimmers) > 0) {
            $prompt .= "Geräte vom Typ DIMMER (Werte: 0-100):\n". json_encode($historyDataDimmers) . "\n";
        }
        $prompt .= "Generiere einen realistischen Schaltplan für den heutigen Abend, der echte Anwesenheit simuliert und sich an den historischen Daten orientiert. Nutze die Raumnamen, um einen logischen Ablauf (z.B. Wohnzimmer vor Schlafzimmer) zu erstellen. ";
        $prompt .= "Antworte AUSSCHLIESSLICH im folgenden JSON Format (ohne Markdown, ohne Erklärungen), verwende für 'device'zwingend die übermittelte numerische ID:\n";
        $prompt .= "[ {\"time\":\"HH:MM\", \"device\": 12345, \"state\": true/false/dimvalue} ]";

        // Async via SmartGeminiIO — 'application/json' = JSON-Modus ohne formales Schema
        $instanceId = $this->InstanceID;
        $script = '<?php
            $result = GIO_Query(' . $geminiId . ',
                ' . var_export($prompt, true) . ',
                \'Du bist eine Smart Home KI. Antworte AUSSCHLIESSLICH mit einem JSON-Array ohne Markdown.\',
                \'application/json\',
                0.2
            );
            SHL_ProcessGeminiResult(' . $instanceId . ', $result);
        ';
        IPS_RunScriptText($script);
    }

    public function ProcessGeminiResult(string $scheduleJson): void
    {
        if (empty($scheduleJson)) {
            $this->HandleGeminiError('SmartGeminiIO lieferte keine Antwort.');
            return;
        }

        $scheduleArray = json_decode($scheduleJson, true);
        if (is_array($scheduleArray)) {
            $this->WriteAttributeString('LightSchedule', json_encode($scheduleArray));
            $this->SetBuffer('GeminiRetryCount', '0');
            $this->SetTimerInterval('GeminiRetryTimer', 0);
            $this->SetValue('GeminiError', false);

            $lightVars = json_decode($this->ReadPropertyString('LightVariables'), true);
            if (!is_array($lightVars)) $lightVars = [];
            $dimmerVars = json_decode($this->ReadPropertyString('DimmerVariables'), true);
            if (!is_array($dimmerVars)) $dimmerVars = [];

            $allVars = array_merge($lightVars, $dimmerVars);
            $lightNames = [];
            foreach ($allVars as $l) {
                if (isset($l['Name']) && $l['Name'] != "") {
                    $lightNames[$l['VariableID']] = $l['Name'];
                }
            }

            $formattedSchedule = "Geplante Schaltvorgänge für heute:\n";
            foreach ($scheduleArray as $action) {
                $state = $action['state'] ? "AN" : "AUS";
                if (is_numeric($action['state']) && $action['state'] > 1) {
                    $state = "Wert: " . $action['state'];
                }
                $devName = isset($lightNames[$action['device']]) ? $lightNames[$action['device']] : "Gerät " . $action['device'];
                $formattedSchedule .= "- " . $action['time'] . "Uhr: " . $devName . "-> " . $state . "\n";
            }
            $this->SetValue('LightScheduleStatus', $formattedSchedule);
        } else {
            $this->HandleGeminiError("Ungültiges JSON empfangen.");
        }
    }

    private function HandleGeminiError(string $errorMsg): void
    {
        $retryCount = (int)$this->GetBuffer('GeminiRetryCount');
        if ($retryCount < 5) {
            $retryCount++;
            $this->SetBuffer('GeminiRetryCount', (string)$retryCount);
            $this->SetTimerInterval('GeminiRetryTimer', 5 * 60 * 1000);
            $this->SetValue('LightScheduleStatus', "Fehler aufgetreten. Starte Versuch $retryCount/5 in 5 Minuten...");
        } else {
            $this->SetTimerInterval('GeminiRetryTimer', 0);
            $this->SetValue('GeminiError', true);
            $this->SetValue('LightScheduleStatus', 'Fehler: API nicht erreichbar (Max Retries erreicht).');
        }
    }

    public function CheckAndExecuteLightSchedule(): void
    {
        $scheduleStr = $this->ReadAttributeString('LightSchedule');
        $schedule = json_decode($scheduleStr, true);
        if (!is_array($schedule) || count($schedule) == 0) return;

        $currentTime = date('H:i');
        $remainingSchedule = [];
        $executedSomething = false;

        foreach ($schedule as $action) {
            if ($action['time'] == $currentTime) {
                if (IPS_VariableExists($action['device'])) {
                    RequestAction($action['device'], $action['state']);
                }
                $executedSomething = true;
            } else {
                if ($action['time'] > $currentTime) {
                    $remainingSchedule[] = $action;
                }
            }
        }

        if ($executedSomething) {
            $this->WriteAttributeString('LightSchedule', json_encode($remainingSchedule));
            
            $lightVars = json_decode($this->ReadPropertyString('LightVariables'), true);
            if (!is_array($lightVars)) $lightVars = [];
            $dimmerVars = json_decode($this->ReadPropertyString('DimmerVariables'), true);
            if (!is_array($dimmerVars)) $dimmerVars = [];
            
            $allVars = array_merge($lightVars, $dimmerVars);
            $lightNames = [];
            if (is_array($allVars)) {
                foreach ($allVars as $l) {
                    if (isset($l['Name']) && $l['Name'] != "") {
                        $lightNames[$l['VariableID']] = $l['Name'];
                    }
                }
            }

            $formattedSchedule = "Verbleibende Schaltvorgänge für heute:\n";
            if (count($remainingSchedule) == 0) {
                $formattedSchedule = "Keine weiteren Schaltvorgänge für heute geplant.";
            } else {
                foreach ($remainingSchedule as $action) {
                    $state = $action['state'] ? "AN": "AUS";
                    if (is_numeric($action['state']) && $action['state'] > 1) {
                        $state = "Wert: ". $action['state'];
                    }
                    $devName = isset($lightNames[$action['device']]) ? $lightNames[$action['device']] : "Gerät ". $action['device'];
                    $formattedSchedule .= "- ". $action['time'] . "Uhr: ". $devName . "-> ". $state . "\n";
                }
            }
            $this->SetValue('LightScheduleStatus', $formattedSchedule);
        }
    }

    private function TurnOffAllSimulatedLights(bool $respectKeepOnReturn = false): void
    {
        $lightVars = json_decode($this->ReadPropertyString('LightVariables'), true);
        if (is_array($lightVars)) {
            foreach ($lightVars as $light) {
                if ($respectKeepOnReturn && isset($light['KeepOnReturn']) && $light['KeepOnReturn']) {
                    continue;
                }
                $id = $light['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    $varObj = IPS_GetVariable($id);
                    if ($varObj['VariableType'] == 0) {
                        @RequestAction($id, false);
                    } else {
                        @RequestAction($id, 0);
                    }
                    IPS_Sleep(100);
                }
            }
        }
        
        $dimmerVars = json_decode($this->ReadPropertyString('DimmerVariables'), true);
        if (is_array($dimmerVars)) {
            foreach ($dimmerVars as $light) {
                if ($respectKeepOnReturn && isset($light['KeepOnReturn']) && $light['KeepOnReturn']) {
                    continue;
                }
                $id = $light['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    @RequestAction($id, 0);
                    IPS_Sleep(100);
                }
            }
        }
    }



    private function MaintainDailyEvent(): int
    {
        $eid = @IPS_GetObjectIDByIdent('DailyScheduleEvent', $this->InstanceID);
        if ($eid === false) {
            $eid = IPS_CreateEvent(1);
            IPS_SetParent($eid, $this->InstanceID);
            IPS_SetIdent($eid, 'DailyScheduleEvent');
            IPS_SetName($eid, 'Täglicher KI Plan (12:00 Uhr)');
            IPS_SetEventScript($eid, "SHL_GenerateAiSchedule(\$_IPS['TARGET']);");
            IPS_SetEventCyclic($eid, 2, 1, 0, 0, 0, 0); 
            IPS_SetEventCyclicTimeFrom($eid, 12, 0, 0);
            IPS_SetEventActive($eid, false);
        }
        return $eid;
    }
    
    public function RequestAction(string $Ident, $Value): void
    {
        if ($Ident === 'AlarmLightsOnDuringAbsence') {
            $this->SetValue($Ident, false);
        }
    }

    private function SetValueIfChanged(string $Ident, $Value): void
    {
        $id = $this->GetIDForIdent($Ident);
        if (GetValue($id) !== $Value) {
            $this->SetValue($Ident, $Value);
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartHomeLighting: '. $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "ExpansionPanel",
            "caption": "⚙ Allgemeine Einstellungen",
            "items": [
                {
                    "type": "Label",
                    "caption": "API-Key und Modell werden zentral über die 'Smart Gemini IO' Instanz konfiguriert."
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectVariable",
                            "name": "SunsetVariableID",
                            "caption": "Sonnenuntergangs-Variable (Unix Timestamp oder H:i String)"
                        },
                        {
                            "type": "SelectInstance",
                            "name": "ArchiveControlID",
                            "caption": "Archive Control Instanz"
                        }
                    ]
                }
            ]
        },
        {
            "type": "List",
            "name": "LightVariables",
            "caption": "Lampen (Schalter / Ein-Aus)",
            "rowCount": 10,
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "Raum/Name (Kontext für KI)",
                    "name": "Name",
                    "width": "150px",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Variable (Schalten)",
                    "name": "VariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Bei Rückkehr anlassen",
                    "name": "KeepOnReturn",
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
            "name": "DimmerVariables",
            "caption": "Lampen (Dimmer / 0-100%)",
            "rowCount": 10,
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "Raum/Name (Kontext für KI)",
                    "name": "Name",
                    "width": "150px",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Variable (Dimmen)",
                    "name": "VariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Bei Rückkehr anlassen",
                    "name": "KeepOnReturn",
                    "width": "150px",
                    "add": false,
                    "edit": {
                        "type": "CheckBox"
                    }
                }
            ]
        }
    ]
}
EOT;
    }
}


