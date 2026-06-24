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
        $this->RegisterPropertyString('LightVariables', '[]');

        // Attributes (Internal state)
        $this->RegisterAttributeString('LightSchedule', '[]');
        $this->RegisterAttributeString('PreviousHeatingStates', '{}');

        // Status Variable (Schalter für Abwesenheit)
        $this->RegisterVariableBoolean('AbsenceStatus', 'Abwesenheitsmodus', '~Switch', 1);
        $this->EnableAction('AbsenceStatus');

        // Status Variable für den KI-Schaltplan
        $this->RegisterVariableString('LightScheduleStatus', 'Aktueller KI-Schaltplan', '~TextBox', 2);

        // Timers
        // Timer für die tägliche Neugenerierung des KI-Plans (z.B. mittags)
        $this->RegisterTimer('DailyScheduleTimer', 0, 'SAI_GenerateAiSchedule($_IPS[\'TARGET\']);');
        
        // Minütlicher Timer zur Ausführung des generierten KI-Schaltplans
        $this->RegisterTimer('LightExecutionTimer', 0, 'SAI_CheckAndExecuteLightSchedule($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Status prüfen
        $apiKey = $this->ReadPropertyString('GeminiAPIKey');
        if (empty($apiKey)) {
            $this->SetStatus(201); // Fehler: Kein API Key
        } else {
            $this->SetStatus(102); // OK
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'AbsenceStatus') {
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

        if (empty($apiKey) || $locationId == 0 || $archiveId == 0) {
            $this->SendDebug("GenerateAiSchedule", "Fehlende Konfiguration für KI-Generierung.", 0);
            $this->LogMessage("KI-Generierung fehlgeschlagen: Konfiguration unvollständig (API-Key, Location oder Archive Control fehlen).", KL_ERROR);
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
            if ($id > 0) {
                // Nutze AC_GetLoggedValues für die letzten 14 Tage
                // Um die Datenmenge klein zu halten, berechnen wir evtl. nur die Durchschnitte
                // oder senden eine kompakte Liste von Schaltzeitpunkten.
                $values = AC_GetLoggedValues($archiveId, $id, $startTime, $endTime, 50); // Limit auf 50 Werte pro Lampe für Prompt-Größe
                $compactLog = [];
                foreach ($values as $v) {
                    $compactLog[] = ["time" => date('Y-m-d H:i', $v['TimeStamp']), "val" => $v['Value']];
                }
                $historyData[$id] = $compactLog;
            }
        }

        // 3. Prompt an Gemini senden
        $prompt = "Du bist eine Smart Home KI. Heute ist der " . date('Y-m-d') . ". Der Sonnenuntergang ist um " . $sunsetTimeStr . " Uhr.\n";
        $prompt .= "Hier sind die Schaltdaten der Lichter der letzten 14 Tage als JSON:\n" . json_encode($historyData) . "\n";
        $prompt .= "Generiere einen realistischen Schaltplan für den heutigen Abend, der echte Anwesenheit simuliert und sich an den historischen Daten orientiert. ";
        $prompt .= "Antworte AUSSCHLIESSLICH im folgenden JSON Format (ohne Markdown, ohne Erklärungen):\n";
        $prompt .= "[ {\"time\":\"HH:MM\", \"device\": 12345, \"state\": true/false/dimvalue} ]";

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;
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
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->LogMessage("cURL Fehler bei Verbindung zu Gemini: " . $curlError, KL_ERROR);
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
                    
                    // Lesbare Formatierung für die Statusvariable
                    $formattedSchedule = "Geplante Schaltvorgänge für heute:\n";
                    foreach ($scheduleArray as $action) {
                        $state = $action['state'] ? "AN" : "AUS";
                        if (is_numeric($action['state']) && $action['state'] > 1) {
                            $state = "Wert: " . $action['state'];
                        }
                        $formattedSchedule .= "- " . $action['time'] . " Uhr: Gerät " . $action['device'] . " -> " . $state . "\n";
                    }
                    $this->SetValue('LightScheduleStatus', $formattedSchedule);

                    $this->SendDebug("Gemini Response", "Schedule generiert: " . count($scheduleArray) . " Aktionen", 0);
                    $this->LogMessage("Erfolgreich " . count($scheduleArray) . " Aktionen in den KI-Schaltplan geladen.", KL_NOTIFY);
                } else {
                    $this->SendDebug("Gemini Error", "Ungültiges JSON empfangen: " . $scheduleText, 0);
                    $this->LogMessage("Fehler beim Parsen der Gemini-Antwort (ungültiges JSON): " . $scheduleText, KL_ERROR);
                }
            } else if (isset($json['error'])) {
                $errorMsg = json_encode($json['error']);
                $this->SendDebug("Gemini API Error", $errorMsg, 0);
                $this->LogMessage("Gemini API meldete einen Fehler: " . $errorMsg, KL_ERROR);
            } else {
                $this->LogMessage("Unerwartete Antwortstruktur von Gemini.", KL_WARNING);
            }
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
                    $formattedSchedule .= "- " . $action['time'] . " Uhr: Gerät " . $action['device'] . " -> " . $state . "\n";
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
