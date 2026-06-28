<?php

declare(strict_types=1);

class SmartAbsenceLighting extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('GeminiAPIKey', '');
        $this->RegisterPropertyString('GeminiModel', 'gemini-3.5-flash');
        $this->RegisterPropertyInteger('SunsetVariableID', 0);
        $this->RegisterPropertyInteger('ArchiveControlID', 0);
        $this->RegisterPropertyString('LightVariables', '[]');

        $this->RegisterAttributeString('LightSchedule', '[]');

        $this->RegisterVariableString('LightScheduleStatus', 'Aktueller KI-Schaltplan', '', 1);
        $this->RegisterVariableBoolean('GeminiError', 'Fehler aufgetreten', '', 2);

        $this->RegisterVariableInteger('ActiveLightsCount', 'Aktive Lampen (Zähler)', '', 3);
        $this->RegisterVariableString('ActiveLightsList', 'Aktive Lampen (Namen)', '', 4);

        $this->RegisterTimer('LightExecutionTimer', 0, 'SAL_CheckAndExecuteLightSchedule($_IPS[\'TARGET\']);');
        $this->RegisterTimer('GeminiRetryTimer', 0, 'SAL_GenerateAiSchedule($_IPS[\'TARGET\'], true);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->MaintainVariable('LightScheduleStatus', 'Aktueller KI-Schaltplan', 3, '', 1, true);
        $this->MaintainVariable('GeminiError', 'Fehler aufgetreten', 0, '', 2, true);
        $this->MaintainVariable('ActiveLightsCount', 'Aktive Lampen (Zähler)', 1, '', 3, true);
        $this->MaintainVariable('ActiveLightsList', 'Aktive Lampen (Namen)', 3, '', 4, true);

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
        $count = 0;
        $activeNames = [];
        if (is_array($lightVars)) {
            foreach ($lightVars as $light) {
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
        } else {
            $this->SetValue('ActiveLightsList', implode(", ", $activeNames));
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

    public function SetAbsence(bool $status): void
    {
        if ($status) {
            $this->GenerateAiSchedule();
            $eid = $this->MaintainDailyEvent();
            IPS_SetEventActive($eid, true);
            $this->SetTimerInterval('LightExecutionTimer', 60000);
            $this->LogMessage("SmartAbsenceLighting: Präsenzsimulation gestartet.", KL_NOTIFY);
        } else {
            $eid = $this->MaintainDailyEvent();
            IPS_SetEventActive($eid, false);
            $this->SetTimerInterval('LightExecutionTimer', 0);
            $this->SetTimerInterval('GeminiRetryTimer', 0);
            $this->WriteAttributeString('LightSchedule', '[]');
            $this->SetValue('LightScheduleStatus', 'Abwesenheit inaktiv - Kein Plan generiert');
            $this->SetValue('GeminiError', false);
            $this->TurnOffAllSimulatedLights();
            $this->LogMessage("SmartAbsenceLighting: Präsenzsimulation gestoppt.", KL_NOTIFY);
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
        if (!is_array($lightVars) || count($lightVars) == 0) return;

        $startTime = time() - (14 * 24 * 60 * 60);
        $endTime = time();
        $historyData = [];

        foreach ($lightVars as $light) {
            $id = $light['VariableID'];
            $name = isset($light['Name']) && $light['Name'] != '' ? $light['Name'] : "Lampe ".$id;
            if ($id > 0) {
                if (!AC_GetLoggingStatus($archiveId, $id)) continue;
                $values = AC_GetLoggedValues($archiveId, $id, $startTime, $endTime, 50);
                $compactLog = [];
                foreach ($values as $v) {
                    $compactLog[] = ["time" => date('Y-m-d H:i', $v['TimeStamp']), "val" => $v['Value']];
                }
                $historyData[$id] = [
                    "name" => $name,
                    "log" => $compactLog
                ];
            }
        }

        $prompt = "Du bist eine Smart Home KI. Heute ist der " . date('Y-m-d') . ". Der Sonnenuntergang ist um " . $sunsetTimeStr . " Uhr.\n";
        $prompt .= "Hier sind die Schaltdaten der Lichter der letzten 14 Tage inkl. Name/Raum als JSON:\n" . json_encode($historyData) . "\n";
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
                SAL_ProcessGeminiResponse(' . $this->InstanceID . ', json_encode(["error" => "cURL Fehler: " . $error]));
            } else {
                SAL_ProcessGeminiResponse(' . $this->InstanceID . ', $response);
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
                $lightNames = [];
                if (is_array($lightVars)) {
                    foreach ($lightVars as $l) {
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
            $lightNames = [];
            if (is_array($lightVars)) {
                foreach ($lightVars as $l) {
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

    private function TurnOffAllSimulatedLights(): void
    {
        $lightVars = json_decode($this->ReadPropertyString('LightVariables'), true);
        if (!is_array($lightVars)) return;

        foreach ($lightVars as $light) {
            $id = $light['VariableID'];
            if ($id > 0 && IPS_VariableExists($id)) {
                $varObj = IPS_GetVariable($id);
                if ($varObj['VariableType'] == 0) {
                    RequestAction($id, false);
                } else {
                    RequestAction($id, 0);
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
            IPS_SetEventScript($eid, "SAL_GenerateAiSchedule(\$_IPS['TARGET']);");
            IPS_SetEventCyclic($eid, 2, 1, 0, 0, 0, 0); 
            IPS_SetEventCyclicTimeFrom($eid, 12, 0, 0);
            IPS_SetEventActive($eid, false);
        }
        return $eid;
    }
}
