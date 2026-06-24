<?php

class SmartAbsenceAI extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyString('GeminiAPIKey', '');
        $this->RegisterPropertyInteger('LocationControlID', 0);
        $this->RegisterPropertyInteger('ArchiveControlID', 0);
        $this->RegisterPropertyFloat('HeatingTargetTemperature', 17.0);
        
        $this->RegisterPropertyString('HeatingVariables', '[]');
        $this->RegisterPropertyString('DoorVariables', '[]');
        $this->RegisterPropertyString('SecurityVariables', '[]');
        $this->RegisterPropertyString('LightVariables', '[]');

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

        // Timers
        // Timer für die tägliche Neugenerierung des KI-Plans (z.B. mittags)
        $this->RegisterTimer('DailyScheduleTimer', 0, 'SAI_GenerateAiSchedule($_IPS[\'TARGET\']);');
        
        // Minütlicher Timer zur Ausführung des generierten KI-Schaltplans
        $this->RegisterTimer('LightExecutionTimer', 0, 'SAI_CheckAndExecuteLightSchedule($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Variablen bei Bedarf neu anlegen (falls sie manuell gelöscht wurden)
        $this->MaintainVariable('LightScheduleStatus', 'Aktueller KI-Schaltplan', 3, '', 2, true);
        $this->MaintainVariable('GeminiError', 'Fehler aufgetreten', 0, '~Alert', 3, true);

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

        $this->SetStatus(102); // OK
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
                        $msg = "Abwesenheit abgelehnt! Folgende Fenster/Türen sind nicht im Soll-Zustand: " . implode(", ", $openItems);
                        $this->LogMessage($msg, KL_WARNING);
                        throw new Exception($msg);
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

            // 4. Timer für morgen aktivieren (24h Rhythmus, oder berechnet auf eine feste Uhrzeit wie 12:00 Uhr)
            // Einfachheitshalber: Ausführung in 24 Stunden (86400000 ms)
            $this->SetTimerInterval('DailyScheduleTimer', 86400000);
            
            // Ausführungs-Timer aktivieren (jede Minute prüfen)
            $this->SetTimerInterval('LightExecutionTimer', 60000);

            $this->SendDebug("Absence", "Abwesenheitsmodus AKTIVIERT", 0);
            $this->LogMessage("Abwesenheitsmodus AKTIVIERT - Türen werden verschlossen, Heizung abgesenkt, KI-Plan generiert.", KL_NOTIFY);
        } else {
            // Rückkehr
            // 1. Heizungen wieder auf Auto-Modus
            $this->SetHeating(0, false);

            // 2. Lichter ausschalten und Timer stoppen
            $this->SetTimerInterval('DailyScheduleTimer', 0);
            $this->SetTimerInterval('LightExecutionTimer', 0);
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

    public function GenerateAiSchedule()
    {
        $apiKey = $this->ReadPropertyString('GeminiAPIKey');
        $locationId = $this->ReadPropertyInteger('LocationControlID');
        $archiveId = $this->ReadPropertyInteger('ArchiveControlID');

        // Initial Fehler-Variable zurücksetzen
        $this->SetValue('GeminiError', false);
        $this->SetValue('LightScheduleStatus', 'Starte KI-Generierung... Bitte warten (Anfrage an Gemini läuft).');

        if (empty($apiKey) || $locationId == 0 || $archiveId == 0) {
            $this->SendDebug("GenerateAiSchedule", "Fehlende Konfiguration für KI-Generierung.", 0);
            $this->LogMessage("KI-Generierung fehlgeschlagen: Konfiguration unvollständig (API-Key, Location oder Archive Control fehlen).", KL_ERROR);
            $this->SetValue('GeminiError', true);
            return;
        }

        // 1. Daten aus Location Control holen (Dämmerung, Sunset)
        // (Angenommen: Location Control Instanz hat Variablen für Sunset etc.)
        // Einfacher Workaround für echtes Symcon: date_sunrise/date_sunset in PHP nutzen,
        // oder die Werte aus den Variablen des Location Control auslesen.
        $sunsetTimeStr = "18:00"; // Fallback
        $children = IPS_GetChildrenIDs($locationId);
        foreach ($children as $child) {
            $obj = IPS_GetObject($child);
            if ($obj['ObjectIdent'] == 'Sunset') {
                $sunsetTimeStr = date('H:i', GetValue($child));
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
            $this->LogMessage("cURL Fehler bei Verbindung zu Gemini: " . $curlError, KL_ERROR);
            $this->SetValue('GeminiError', true);
            $this->SetValue('LightScheduleStatus', 'Fehler: Verbindung zu Gemini fehlgeschlagen (Timeout?).');
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
                    $this->LogMessage("Fehler beim Parsen der Gemini-Antwort (ungültiges JSON): " . $scheduleText, KL_ERROR);
                    $this->SetValue('GeminiError', true);
                    $this->SetValue('LightScheduleStatus', 'Fehler: Ungültige Antwort von Gemini.');
                }
            } else if (isset($json['error'])) {
                $errorMsg = json_encode($json['error']);
                $this->SendDebug("Gemini API Error", $errorMsg, 0);
                $this->LogMessage("Gemini API meldete einen Fehler: " . $errorMsg, KL_ERROR);
                $this->SetValue('GeminiError', true);
                $this->SetValue('LightScheduleStatus', 'API-Fehler: ' . $errorMsg);
            } else {
                $this->LogMessage("Unerwartete Antwortstruktur von Gemini.", KL_WARNING);
                $this->SetValue('GeminiError', true);
                $this->SetValue('LightScheduleStatus', 'Fehler: Unerwartete Antwortstruktur.');
            }
        } else {
            $this->LogMessage("Keine Antwort von Gemini erhalten.", KL_ERROR);
            $this->SetValue('GeminiError', true);
            $this->SetValue('LightScheduleStatus', 'Fehler: Keine Antwort erhalten.');
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
}
