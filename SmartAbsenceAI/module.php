<?php

class SmartAbsenceAI extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyString('GeminiAPIKey', '');
        $this->RegisterPropertyInteger('SunsetVariableID', 0);
        $this->RegisterPropertyInteger('ArchiveControlID', 0);
        $this->RegisterPropertyFloat('HeatingTargetTemperature', 17.0);
        
        $this->RegisterPropertyString('HeatingVariables', '[]');
        $this->RegisterPropertyString('DoorVariables', '[]');
        $this->RegisterPropertyString('SecurityVariables', '[]');
        $this->RegisterPropertyString('LightVariables', '[]');

        // Push Notifications
        $this->RegisterPropertyInteger('WebFrontInstance', 0);
        $this->RegisterPropertyBoolean('PushNotifyWindows', true);
        $this->RegisterPropertyBoolean('PushNotifyLights', true);

        // Attributes (Internal state)
        $this->RegisterAttributeString('LightSchedule', '[]');
        $this->RegisterAttributeString('PreviousHeatingStates', '{}');

        // Status Variable (Schalter für Abwesenheit)
        $this->RegisterVariableBoolean('AbsenceStatus', 'Abwesenheitsmodus', '~Switch', 1);
        $this->EnableAction('AbsenceStatus');

        // Status Variable für den KI-Schaltplan
        $this->RegisterVariableString('LightScheduleStatus', 'Aktueller KI-Schaltplan', '', 2);

        // Status Variable für Fehler
        $this->RegisterVariableBoolean('GeminiError', 'Fehler aufgetreten', '~Alert', 3);

        // Variable für die Anzahl offener Fenster/Türen
        $this->RegisterVariableInteger('OpenSecurityItemsCount', 'Offene Fenster / Türen (Zähler)', '', 4);
        $this->RegisterVariableString('OpenSecurityItemsList', 'Offene Fenster / Türen (Namen)', '', 5);

        // Variable für aktive Lampen
        $this->RegisterVariableInteger('ActiveLightsCount', 'Aktive Lampen (Zähler)', '', 6);
        $this->RegisterVariableString('ActiveLightsList', 'Aktive Lampen (Namen)', '', 7);

        // Timers
        // Minütlicher Timer zur Ausführung des generierten KI-Schaltplans
        $this->RegisterTimer('LightExecutionTimer', 0, 'SAI_CheckAndExecuteLightSchedule($_IPS[\'TARGET\']);');
        
        // Timer für automatische Wiederholungen bei API-Fehlern
        $this->RegisterTimer('GeminiRetryTimer', 0, 'SAI_GenerateAiSchedule($_IPS[\'TARGET\'], true);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Alten Intervall-Timer löschen (falls noch vorhanden, da auf natives Event umgestellt wurde)
        $oldTimer = @$this->GetIDForIdent('DailyScheduleTimer');
        if ($oldTimer !== false && IPS_EventExists($oldTimer)) {
            IPS_DeleteEvent($oldTimer);
        }

        // Variablen bei Bedarf neu anlegen (falls sie manuell gelöscht wurden)
        $this->MaintainVariable('LightScheduleStatus', 'Aktueller KI-Schaltplan', 3, '', 2, true);
        $this->MaintainVariable('GeminiError', 'Fehler aufgetreten', 0, '~Alert', 3, true);

        $countId = @$this->GetIDForIdent('OpenSecurityItemsCount');
        if ($countId !== false) {
            IPS_SetName($countId, 'Offene Fenster / Türen (Zähler)');
        }

        // Status prüfen
        $apiKey = $this->ReadPropertyString('GeminiAPIKey');
        $archiveId = $this->ReadPropertyInteger('ArchiveControlID');
        
        if (empty($apiKey)) {
            $this->SetStatus(201); // Fehler: Kein API Key
            return;
        }

        if ($archiveId > 0 && IPS_InstanceExists($archiveId)) {
            $lightVars = json_decode($this->ReadPropertyString('LightVariables'), true);
            $unloggedVars = [];
            if (is_array($lightVars)) {
                foreach ($lightVars as $light) {
                    $id = $light['VariableID'];
                    $name = isset($light['Name']) && $light['Name'] != '' ? $light['Name'] : "Lampe ".$id;
                    if ($id > 0 && IPS_VariableExists($id)) {
                        if (!AC_GetLoggingStatus($archiveId, $id)) {
                            $unloggedVars[] = $name . " (" . $id . ")";
                        }
                    }
                }
            }
            
            if (count($unloggedVars) > 0) {
                $this->LogMessage("Archive Control Fehler: Folgende Licht-Variablen werden nicht geloggt: " . implode(", ", $unloggedVars), KL_ERROR);
                $this->SetStatus(202); // Fehler: Nicht alle Lichter geloggt
                return;
            }
        }

        // MessageSink für Security Variablen registrieren
        $secVars = json_decode($this->ReadPropertyString('SecurityVariables'), true);
        if (is_array($secVars)) {
            foreach ($secVars as $sec) {
                $id = $sec['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
            }
        }
        $this->CalculateOpenItems();

        // MessageSink für Light Variablen registrieren
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

        $this->SetStatus(102); // OK
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE) {
            $this->CalculateOpenItems();
            $this->CalculateActiveLights();
        }
    }

    private function CalculateOpenItems()
    {
        $secVars = json_decode($this->ReadPropertyString('SecurityVariables'), true);
        $count = 0;
        $openNames = [];
        if (is_array($secVars)) {
            foreach ($secVars as $sec) {
                $id = $sec['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    $currentVal = GetValue($id);
                    $checkVal = $sec['ClosedValue'];
                    
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
                        $name = isset($sec['Name']) && $sec['Name'] != '' ? $sec['Name'] : IPS_GetName($id);
                        $openNames[] = $name;
                    }
                }
            }
        }
        $this->SetValue('OpenSecurityItemsCount', $count);
        
        if ($count == 0) {
            $this->SetValue('OpenSecurityItemsList', 'Alle geschlossen');
        } else {
            $this->SetValue('OpenSecurityItemsList', implode(", ", $openNames));
        }
    }

    private function CalculateActiveLights()
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

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'AbsenceStatus') {
            if ($Value == true) {
                // Sicherheitsprüfung
                $secVars = json_decode($this->ReadPropertyString('SecurityVariables'), true);
                if (is_array($secVars)) {
                    $openItems = [];
                    foreach ($secVars as $sec) {
                        $id = $sec['VariableID'];
                        if ($id > 0 && IPS_VariableExists($id)) {
                            $currentVal = GetValue($id);
                            $checkVal = $sec['ClosedValue'];
                            
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
                                $name = isset($sec['Name']) && $sec['Name'] != '' ? $sec['Name'] : IPS_GetName($id);
                                $openItems[] = $name;
                            }
                        }
                    }
                    if (count($openItems) > 0) {
                        $msg = "Warnung: Folgende Fenster/Türen sind offen: " . implode(", ", $openItems) . ". Abwesenheit wird trotzdem vollständig aktiviert.";
                        $this->LogMessage($msg, KL_WARNING);
                        
                        $wfc = $this->ReadPropertyInteger('WebFrontInstance');
                        if ($wfc > 0 && IPS_InstanceExists($wfc) && $this->ReadPropertyBoolean('PushNotifyWindows')) {
                            if (function_exists('VISU_PostNotification')) {
                                @VISU_PostNotification($wfc, "Achtung beim Verlassen", "Offen: " . implode(", ", $openItems), "Warning", 0);
                            }
                            if (function_exists('WFC_PushNotification')) {
                                @WFC_PushNotification($wfc, "Achtung beim Verlassen", "Offen: " . implode(", ", $openItems), "", 0);
                            }
                        }
                    }
                }
                
                $activeLights = GetValue($this->GetIDForIdent('ActiveLightsList'));
                if (!empty($activeLights)) {
                    $wfc = $this->ReadPropertyInteger('WebFrontInstance');
                    if ($wfc > 0 && IPS_InstanceExists($wfc) && $this->ReadPropertyBoolean('PushNotifyLights')) {
                        if (function_exists('VISU_PostNotification')) {
                            @VISU_PostNotification($wfc, "Achtung beim Verlassen", "Noch an: " . $activeLights, "Light", 0);
                        }
                        if (function_exists('WFC_PushNotification')) {
                            @WFC_PushNotification($wfc, "Achtung beim Verlassen", "Noch an: " . $activeLights, "", 0);
                        }
                    }
                }
            }

            $this->SetValue($Ident, $Value);
            $this->SetAbsence($Value);
        }
    }

    public function SetAbsence(bool $status)
    {
        if ($status) {
            // 1. Heizungen absenken (Homematic)
            $this->SetHeating($this->ReadPropertyFloat('HeatingTargetTemperature'), true);

            // 2. Türen verriegeln
            $this->LockDoors();

            // 3. KI Schaltplan generieren
            $this->GenerateAiSchedule();

            // 4. Timer (Zyklisches Ereignis) für morgen aktivieren (12:00 Uhr)
            $eid = $this->MaintainDailyEvent();
            IPS_SetEventActive($eid, true);
            
            // Ausführungs-Timer aktivieren (jede Minute prüfen)
            $this->SetTimerInterval('LightExecutionTimer', 60000);

            $this->SendDebug("Absence", "Abwesenheitsmodus AKTIVIERT", 0);
            $this->LogMessage("Abwesenheitsmodus AKTIVIERT - Türen werden verschlossen, Heizung abgesenkt, KI-Plan generiert.", KL_NOTIFY);
        } else {
            // Rückkehr
            // 1. Heizungen wieder auf Auto-Modus
            $this->SetHeating(0, false);

            // 2. Lichter ausschalten und Timer stoppen
            $eid = $this->MaintainDailyEvent();
            IPS_SetEventActive($eid, false);
            $this->SetTimerInterval('LightExecutionTimer', 0);
            $this->SetTimerInterval('GeminiRetryTimer', 0);
            $this->WriteAttributeString('LightSchedule', '[]');
            $this->SetValue('LightScheduleStatus', 'Abwesenheit inaktiv - Kein Plan generiert');
            $this->SetValue('GeminiError', false);
            $this->TurnOffAllSimulatedLights();

            // 3. Türen aufsperren (Tedee: 1)
            $this->UnlockDoors();

            $this->SendDebug("Absence", "Abwesenheitsmodus DEAKTIVIERT", 0);
            $this->LogMessage("Abwesenheitsmodus DEAKTIVIERT - Türen aufgesperrt, Heizung auf Auto, Licht-Plan gestoppt.", KL_NOTIFY);
        }
    }

    private function SetHeating(float $targetTemp, bool $isAbsence)
    {
        $heatingVars = json_decode($this->ReadPropertyString('HeatingVariables'), true);
        if (!is_array($heatingVars)) return;

        if ($isAbsence) {
            $previousStates = [];
            foreach ($heatingVars as $heating) {
                $tempId = $heating['VariableID'];
                if ($tempId > 0 && IPS_VariableExists($tempId)) {
                    // Aktuellen Wert speichern
                    $previousStates[$tempId] = GetValue($tempId);
                    // Temperatur absenken
                    RequestAction($tempId, $targetTemp);
                }
            }
            // Alten Zustand in Attribut speichern
            $this->WriteAttributeString('PreviousHeatingStates', json_encode($previousStates));
        } else {
            // Rückkehr: Alte Werte wiederherstellen
            $previousStatesStr = $this->ReadAttributeString('PreviousHeatingStates');
            $previousStates = json_decode($previousStatesStr, true);
            
            if (is_array($previousStates)) {
                foreach ($heatingVars as $heating) {
                    $tempId = $heating['VariableID'];
                    if ($tempId > 0 && isset($previousStates[$tempId]) && IPS_VariableExists($tempId)) {
                        RequestAction($tempId, $previousStates[$tempId]);
                    }
                }
            }
            // Attribut wieder leeren
            $this->WriteAttributeString('PreviousHeatingStates', '{}');
        }
    }

    private function LockDoors()
    {
        $doorVars = json_decode($this->ReadPropertyString('DoorVariables'), true);
        if (!is_array($doorVars)) return;

        foreach ($doorVars as $door) {
            $id = $door['VariableID'];
            if ($id > 0 && IPS_VariableExists($id)) {
                // Tedee: 0 = Zusperren
                RequestAction($id, 0);
            }
        }
    }

    private function UnlockDoors()
    {
        $doorVars = json_decode($this->ReadPropertyString('DoorVariables'), true);
        if (!is_array($doorVars)) return;

        foreach ($doorVars as $door) {
            $id = $door['VariableID'];
            if ($id > 0 && IPS_VariableExists($id)) {
                // Tedee: 1 = Aufsperren
                RequestAction($id, 1);
            }
        }
    }

    public function GenerateAiSchedule(bool $isRetry = false)
    {
        if (!$isRetry) {
            $this->SetBuffer('GeminiRetryCount', '0');
            $this->SetTimerInterval('GeminiRetryTimer', 0);
        }

        $apiKey = $this->ReadPropertyString('GeminiAPIKey');
        $sunsetVarId = $this->ReadPropertyInteger('SunsetVariableID');
        $archiveId = $this->ReadPropertyInteger('ArchiveControlID');

        // Initial Fehler-Variable zurücksetzen
        $this->SetValue('GeminiError', false);
        $this->SetValue('LightScheduleStatus', 'Starte KI-Generierung... Bitte warten (Anfrage an Gemini läuft).');

        if (empty($apiKey) || $sunsetVarId == 0 || $archiveId == 0) {
            $this->SendDebug("GenerateAiSchedule", "Fehlende Konfiguration für KI-Generierung.", 0);
            $this->LogMessage("KI-Generierung fehlgeschlagen: Konfiguration unvollständig (API-Key, Sonnenuntergangs-Variable oder Archive Control fehlen).", KL_ERROR);
            $this->SetValue('GeminiError', true);
            return;
        }

        // 1. Sonnenuntergang / Dämmerung aus der konfigurierten Variable holen
        $sunsetTimeStr = "18:00"; // Fallback
        if (IPS_VariableExists($sunsetVarId)) {
            $val = GetValue($sunsetVarId);
            if (is_int($val)) {
                $sunsetTimeStr = date('H:i', $val);
            } else {
                $sunsetTimeStr = (string)$val;
            }
        }

        // 2. Archiv-Daten der Lichter holen (letzte 14 Tage)
        $lightVars = json_decode($this->ReadPropertyString('LightVariables'), true);
        if (!is_array($lightVars) || count($lightVars) == 0) return;

        $startTime = time() - (14 * 24 * 60 * 60);
        $endTime = time();
        $historyData = [];

        foreach ($lightVars as $light) {
            $id = $light['VariableID'];
            $name = isset($light['Name']) && $light['Name'] != '' ? $light['Name'] : "Lampe ".$id;
            if ($id > 0) {
                if (!AC_GetLoggingStatus($archiveId, $id)) {
                    $this->LogMessage("Überspringe Licht-Variable '" . $name . "' (" . $id . "), da das Variablen-Logging im Archive Control nicht aktiviert ist!", KL_WARNING);
                    continue;
                }

                // Nutze AC_GetLoggedValues für die letzten 14 Tage
                // Um die Datenmenge klein zu halten, berechnen wir evtl. nur die Durchschnitte
                // oder senden eine kompakte Liste von Schaltzeitpunkten.
                $values = AC_GetLoggedValues($archiveId, $id, $startTime, $endTime, 50); // Limit auf 50 Werte pro Lampe für Prompt-Größe
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

        // 3. Prompt an Gemini senden
        $prompt = "Du bist eine Smart Home KI. Heute ist der " . date('Y-m-d') . ". Der Sonnenuntergang ist um " . $sunsetTimeStr . " Uhr.\n";
        $prompt .= "Hier sind die Schaltdaten der Lichter der letzten 14 Tage inkl. Name/Raum als JSON:\n" . json_encode($historyData) . "\n";
        $prompt .= "Generiere einen realistischen Schaltplan für den heutigen Abend, der echte Anwesenheit simuliert und sich an den historischen Daten orientiert. Nutze die Raumnamen, um einen logischen Ablauf (z.B. Wohnzimmer vor Schlafzimmer) zu erstellen. ";
        $prompt .= "Antworte AUSSCHLIESSLICH im folgenden JSON Format (ohne Markdown, ohne Erklärungen), verwende für 'device' zwingend die übermittelte numerische ID:\n";
        $prompt .= "[ {\"time\":\"HH:MM\", \"device\": 12345, \"state\": true/false/dimvalue} ]";

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=" . $apiKey;
        $payload = [
            "contents" => [
                ["parts" => [["text" => $prompt]]]
            ],
            "generationConfig" => [
                "response_mime_type" => "application/json"
            ]
        ];

        $payloadJson = json_encode($payload);
        
        $this->SendDebug("Gemini Request", $payloadJson, 0);
        $this->LogMessage("Sende Request an Gemini API: " . $prompt, KL_NOTIFY);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Timeout auf 60 Sekunden erhöhen
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->HandleGeminiError("cURL Fehler bei Verbindung zu Gemini: " . $curlError);
            return;
        }

        $this->SendDebug("Gemini Response Raw", $response, 0);
        $this->LogMessage("Empfangene Antwort von Gemini: " . $response, KL_NOTIFY);

        if ($response) {
            $json = json_decode($response, true);
            if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                $scheduleText = $json['candidates'][0]['content']['parts'][0]['text'];
                $scheduleArray = json_decode($scheduleText, true);
                
                if (is_array($scheduleArray)) {
                    $this->WriteAttributeString('LightSchedule', json_encode($scheduleArray));
                    
                    // Erfolgreich -> Fehler/Retry-Zähler zurücksetzen
                    $this->SetBuffer('GeminiRetryCount', '0');
                    $this->SetTimerInterval('GeminiRetryTimer', 0);
                    $this->SetValue('GeminiError', false);

                    // Map für die Namen erstellen
                    $lightNames = [];
                    foreach ($lightVars as $l) {
                        if (isset($l['Name']) && $l['Name'] != "") {
                            $lightNames[$l['VariableID']] = $l['Name'];
                        }
                    }

                    // Lesbare Formatierung für die Statusvariable
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

                    $this->SendDebug("Gemini Response", "Schedule generiert: " . count($scheduleArray) . " Aktionen", 0);
                    $this->LogMessage("Erfolgreich " . count($scheduleArray) . " Aktionen in den KI-Schaltplan geladen.", KL_NOTIFY);
                } else {
                    $this->SendDebug("Gemini Error", "Ungültiges JSON empfangen: " . $scheduleText, 0);
                    $this->HandleGeminiError("Fehler beim Parsen der Gemini-Antwort (ungültiges JSON): " . $scheduleText);
                }
            } else if (isset($json['error'])) {
                $errorMsg = json_encode($json['error']);
                $this->SendDebug("Gemini API Error", $errorMsg, 0);
                $this->HandleGeminiError("Gemini API meldete einen Fehler: " . $errorMsg);
            } else {
                $this->HandleGeminiError("Unerwartete Antwortstruktur von Gemini.");
            }
        } else {
            $this->HandleGeminiError("Keine Antwort von Gemini erhalten.");
        }
    }

    private function HandleGeminiError($errorMsg)
    {
        $retryCount = (int)$this->GetBuffer('GeminiRetryCount');
        if ($retryCount < 5) {
            $retryCount++;
            $this->SetBuffer('GeminiRetryCount', (string)$retryCount);
            $this->SetTimerInterval('GeminiRetryTimer', 5 * 60 * 1000); // 5 Minuten
            $this->LogMessage($errorMsg . " - Neuer Versuch $retryCount/5 in 5 Minuten.", KL_WARNING);
            $this->SetValue('LightScheduleStatus', "Fehler aufgetreten. Starte Versuch $retryCount/5 in 5 Minuten...");
        } else {
            $this->SetTimerInterval('GeminiRetryTimer', 0);
            $this->LogMessage($errorMsg . " - Alle 5 Wiederholungsversuche fehlgeschlagen.", KL_ERROR);
            $this->SetValue('GeminiError', true);
            $this->SetValue('LightScheduleStatus', 'Fehler: API nicht erreichbar (Max Retries erreicht).');
        }
    }

    public function CheckAndExecuteLightSchedule()
    {
        $scheduleStr = $this->ReadAttributeString('LightSchedule');
        $schedule = json_decode($scheduleStr, true);
        if (!is_array($schedule) || count($schedule) == 0) return;

        $currentTime = date('H:i');
        $remainingSchedule = [];
        $executedSomething = false;

        foreach ($schedule as $action) {
            if ($action['time'] == $currentTime) {
                // Ausführen
                $this->ExecuteLightAction($action['device'], $action['state']);
                $executedSomething = true;
                $this->SendDebug("LightAction", "Schalte Gerät " . $action['device'] . " auf " . (string)$action['state'], 0);
                $this->LogMessage("KI Lichtsteuerung: Schalte Gerät " . $action['device'] . " auf Wert " . (string)$action['state'], KL_NOTIFY);
            } else {
                // Behalten für spätere Ausführung (wenn Zeit noch in der Zukunft liegt)
                // Da wir minütlich prüfen, schmeißen wir alte Zeiten direkt raus.
                if ($action['time'] > $currentTime) {
                    $remainingSchedule[] = $action;
                }
            }
        }

        if ($executedSomething) {
            $this->WriteAttributeString('LightSchedule', json_encode($remainingSchedule));
            
            // Map für die Namen erstellen
            $lightVars = json_decode($this->ReadPropertyString('LightVariables'), true);
            $lightNames = [];
            if (is_array($lightVars)) {
                foreach ($lightVars as $l) {
                    if (isset($l['Name']) && $l['Name'] != "") {
                        $lightNames[$l['VariableID']] = $l['Name'];
                    }
                }
            }

            // Statusvariable aktualisieren (abgearbeitete Punkte entfernen)
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

    private function ExecuteLightAction($deviceId, $value)
    {
        if (IPS_VariableExists($deviceId)) {
            RequestAction($deviceId, $value);
        }
    }

    private function TurnOffAllSimulatedLights()
    {
        $lightVars = json_decode($this->ReadPropertyString('LightVariables'), true);
        if (!is_array($lightVars)) return;

        foreach ($lightVars as $light) {
            $id = $light['VariableID'];
            if ($id > 0 && IPS_VariableExists($id)) {
                // Versuch, die Lichter auszuschalten. Dimmer auf 0, Schalter auf false.
                $varObj = IPS_GetVariable($id);
                if ($varObj['VariableType'] == 0) { // Boolean
                    RequestAction($id, false);
                } else { // Integer/Float
                    RequestAction($id, 0);
                }
            }
        }
    }

    private function MaintainDailyEvent()
    {
        $eid = @IPS_GetObjectIDByIdent('DailyScheduleEvent', $this->InstanceID);
        if ($eid === false) {
            $eid = IPS_CreateEvent(1); // 1 = Zyklisches Ereignis
            IPS_SetParent($eid, $this->InstanceID);
            IPS_SetIdent($eid, 'DailyScheduleEvent');
            IPS_SetName($eid, 'Täglicher KI Plan (12:00 Uhr)');
            IPS_SetEventScript($eid, "SAI_GenerateAiSchedule(\$_IPS['TARGET']);");
            // DateType = 2 (Täglich), Every = 1 (Jeden Tag), TimeType = 0 (Einmalig)
            IPS_SetEventCyclic($eid, 2, 1, 0, 0, 0, 0); 
            IPS_SetEventCyclicTimeFrom($eid, 12, 0, 0); // 12:00:00 Uhr
            IPS_SetEventActive($eid, false);
        }
        return $eid;
    }
}
