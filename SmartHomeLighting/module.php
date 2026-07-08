<?php

declare(strict_types=1);

class SmartHomeLighting extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('GeminiAPIKey', '');
        $this->RegisterPropertyString('GeminiModel', 'gemini-3.5-flash');
        $this->RegisterPropertyInteger('SunsetVariableID', 0);
        $this->RegisterPropertyInteger('ArchiveControlID', 0);
        $this->RegisterPropertyString('LightVariables', '[]');
        $this->RegisterPropertyString('DimmerVariables', '[]');

        $this->RegisterAttributeString('LightSchedule', '[]');

        $this->RegisterVariableString('LightScheduleStatus', 'ℹ️ Aktueller KI-Schaltplan', '', 1);
        $this->RegisterVariableBoolean('GeminiError', '⚠️ Fehler aufgetreten', '', 2);
        
        $this->RegisterVariableInteger('ActiveLightsCount', '💡 Aktive Lampen (Zähler)', '', 3);
        $this->RegisterVariableString('ActiveLightsList', '📝 Aktive Lampen (Namen)', '', 4);
        $this->RegisterVariableString('VestaboardStatus', 'Kurz-Status (Vestaboard)', '', 5);

        $this->RegisterTimer('LightExecutionTimer', 0, 'SHL_CheckAndExecuteLightSchedule($_IPS[\'TARGET\']);');
        $this->RegisterTimer('GeminiRetryTimer', 0, 'SHL_GenerateAiSchedule($_IPS[\'TARGET\'], true);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->MaintainVariable('LightScheduleStatus', 'Aktueller KI-Schaltplan', 3, '', 1, true);
        $this->MaintainVariable('GeminiError', 'Fehler aufgetreten', 0, '', 2, true);
        $this->MaintainVariable('ActiveLightsCount', 'Aktive Lampen (Zähler)', 1, '', 3, true);
        $this->MaintainVariable('ActiveLightsList', 'Aktive Lampen (Namen)', 3, '', 4, true);
        $this->MaintainVariable('VestaboardStatus', 'Kurz-Status (Vestaboard)', 3, '', 5, true);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('LightScheduleStatus'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Clock'
        ]);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('GeminiError'), [
            'PRESENTATION'   => VARIABLE_PRESENTATION_SWITCH,
            'ICON'           => 'Warning',
            'GLOW_COLOR'     => 16711680, // Rot
            'GLOW_INTENSITY' => 50
        ]);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('ActiveLightsCount'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Bulb',
            'SUFFIX'       => ' an'
        ]);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('ActiveLightsList'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Information'
        ]);

        $apiKey = $this->ReadPropertyString('GeminiAPIKey');
        if (empty($apiKey)) {
            $this->SetStatus(201);
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
                        $isActive = (strtolower(trim($currentVal)) === 'true' || trim($currentVal) === '1');
                    }
                    
                    if ($isActive) {
                        $count++;
                        $name = isset($light['Name']) && $light['Name'] != '' ? $light['Name'] : IPS_GetName($id);
                        $activeNames[] = $name;
                    }
                }
            }
        }
        $this->SetValue('ActiveLightsCount', $count);
        
        if ($count == 0) {
            $this->SetValue('ActiveLightsList', 'Alle aus');
            $this->SetValue('VestaboardStatus', '');
        } else {
            $namesStr = implode(", ", $activeNames);
            $this->SetValue('ActiveLightsList', $namesStr);
            $this->SetValue('VestaboardStatus', $count . ' an');
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

    public function SetHouseMode(int $mode): void
    {
        // 0=Anwesenheit, 1=Abwesenheit, 2=Urlaub, 3=Party, 4=Heimkino, 5=Schlafen, 6=Putzen
        $isAbsence = ($mode == 1 || $mode == 2);
        
        $eid = $this->MaintainDailyEvent();
        
        if ($isAbsence) {
            $this->GenerateAiSchedule();
            IPS_SetEventActive($eid, true);
            $this->SetTimerInterval('LightExecutionTimer', 60000);
            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeLighting: Präsenzsimulation gestartet.");
            $this->TurnOffAllSimulatedLights(); // Zuerst alles aus
        } else {
            // Wenn Präsenzsimulation lief, schalten wir sie ab
            $wasActive = IPS_GetEvent($eid)['EventActive'];
            
            IPS_SetEventActive($eid, false);
            $this->SetTimerInterval('LightExecutionTimer', 0);
            $this->SetTimerInterval('GeminiRetryTimer', 0);
            $this->WriteAttributeString('LightSchedule', '[]');
            $this->SetValue('LightScheduleStatus', 'Abwesenheit inaktiv - Kein Plan generiert');
            $this->SetValue('GeminiError', false);
            
            if ($mode == 5) { // Schlafen
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

        $apiKey = $this->ReadPropertyString('GeminiAPIKey');
        $sunsetVarId = $this->ReadPropertyInteger('SunsetVariableID');
        $archiveId = $this->ReadPropertyInteger('ArchiveControlID');

        $this->SetValue('GeminiError', false);
        $this->SetValue('LightScheduleStatus', 'Starte KI-Generierung... Bitte warten.');

        if (empty($apiKey) || $sunsetVarId == 0 || $archiveId == 0) {
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
            $name = isset($light['Name']) && $light['Name'] != '' ? $light['Name'] : "Schalter ".$id;
            if ($id > 0) {
                if (!AC_GetLoggingStatus($archiveId, $id)) continue;
                $values = AC_GetLoggedValues($archiveId, $id, $startTime, $endTime, 50);
                $compactLog = [];
                foreach ($values as $v) {
                    $compactLog[] = ["time" => date('Y-m-d H:i', $v['TimeStamp']), "val" => $v['Value']];
                }
                $historyDataSwitches[$id] = [
                    "name" => $name,
                    "log" => $compactLog
                ];
            }
        }
        
        foreach ($dimmerVars as $light) {
            $id = $light['VariableID'];
            $name = isset($light['Name']) && $light['Name'] != '' ? $light['Name'] : "Dimmer ".$id;
            if ($id > 0) {
                if (!AC_GetLoggingStatus($archiveId, $id)) continue;
                $values = AC_GetLoggedValues($archiveId, $id, $startTime, $endTime, 50);
                $compactLog = [];
                foreach ($values as $v) {
                    $compactLog[] = ["time" => date('Y-m-d H:i', $v['TimeStamp']), "val" => $v['Value']];
                }
                $historyDataDimmers[$id] = [
                    "name" => $name,
                    "log" => $compactLog
                ];
            }
        }

        $prompt = "Du bist eine Smart Home KI. Heute ist der " . date('Y-m-d') . ". Der Sonnenuntergang ist um " . $sunsetTimeStr . " Uhr.\n";
        $prompt .= "Hier sind die Schaltdaten der Lichter der letzten 14 Tage inkl. Name/Raum als JSON:\n";
        if (count($historyDataSwitches) > 0) {
            $prompt .= "Geräte vom Typ SCHALTER (Werte: true/false):\n" . json_encode($historyDataSwitches) . "\n";
        }
        if (count($historyDataDimmers) > 0) {
            $prompt .= "Geräte vom Typ DIMMER (Werte: 0-100):\n" . json_encode($historyDataDimmers) . "\n";
        }
        $prompt .= "Generiere einen realistischen Schaltplan für den heutigen Abend, der echte Anwesenheit simuliert und sich an den historischen Daten orientiert. Nutze die Raumnamen, um einen logischen Ablauf (z.B. Wohnzimmer vor Schlafzimmer) zu erstellen. ";
        $prompt .= "Antworte AUSSCHLIESSLICH im folgenden JSON Format (ohne Markdown, ohne Erklärungen), verwende für 'device' zwingend die übermittelte numerische ID:\n";
        $prompt .= "[ {\"time\":\"HH:MM\", \"device\": 12345, \"state\": true/false/dimvalue} ]";

        $model = $this->ReadPropertyString('GeminiModel');
        if (empty($model)) $model = 'gemini-3.5-flash';

        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $model . ":generateContent?key=" . $apiKey;
        $payload = [
            "contents" => [["parts" => [["text" => $prompt]]]],
            "generationConfig" => ["response_mime_type" => "application/json"]
        ];

        $payloadJson = json_encode($payload);

        // Asynchroner Aufruf über IPS_RunScriptText, um den IP-Symcon Thread nicht zu blockieren
        $script = '<?php
            $ch = curl_init("' . $url . '");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ' . var_export($payloadJson, true) . ');
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                SHL_ProcessGeminiResponse(' . $this->InstanceID . ', json_encode(["error" => "cURL Fehler: " . $error]));
            } else {
                SHL_ProcessGeminiResponse(' . $this->InstanceID . ', $response);
            }
        ';
        IPS_RunScriptText($script);
    }

    public function ProcessGeminiResponse(string $response): void
    {
        if (!$response) {
            $this->HandleGeminiError("Keine Antwort erhalten.");
            return;
        }

        $json = json_decode($response, true);
        if (isset($json["error"]) && is_string($json["error"]) && strpos($json["error"], "cURL") !== false) {
            $this->HandleGeminiError($json["error"]);
            return;
        }

        if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
            $scheduleText = $json['candidates'][0]['content']['parts'][0]['text'];
            $scheduleArray = json_decode($scheduleText, true);
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
                if (is_array($allVars)) {
                    foreach ($allVars as $l) {
                        if (isset($l['Name']) && $l['Name'] != "") {
                            $lightNames[$l['VariableID']] = $l['Name'];
                        }
                    }
                }

                $formattedSchedule = "Geplante Schaltvorgänge für heute:\n";
                foreach ($scheduleArray as $action) {
                    $state = $action['state'] ? "AN" : "AUS";
                    if (is_numeric($action['state']) && $action['state'] > 1) {
                        $state = "Wert: " . $action['state'];
                    }
                    $devName = isset($lightNames[$action['device']]) ? $lightNames[$action['device']] : "Gerät " . $action['device'];
                    $formattedSchedule .= "- " . $action['time'] . " Uhr: " . $devName . " -> " . $state . "\n";
                }
                $this->SetValue('LightScheduleStatus', $formattedSchedule);
            } else {
                $this->HandleGeminiError("Ungültiges JSON empfangen.");
            }
        } else if (isset($json['error'])) {
            $this->HandleGeminiError("Gemini API Error: " . json_encode($json['error']));
        } else {
            $this->HandleGeminiError("Unerwartete Antwortstruktur.");
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
                    $state = $action['state'] ? "AN" : "AUS";
                    if (is_numeric($action['state']) && $action['state'] > 1) {
                        $state = "Wert: " . $action['state'];
                    }
                    $devName = isset($lightNames[$action['device']]) ? $lightNames[$action['device']] : "Gerät " . $action['device'];
                    $formattedSchedule .= "- " . $action['time'] . " Uhr: " . $devName . " -> " . $state . "\n";
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
}
