<?php

declare(strict_types=1);

class SmartHomeShading extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('BlindVariables', '[]');
        $this->RegisterPropertyInteger('SunriseVariableID', 0);
        $this->RegisterPropertyInteger('SunsetVariableID', 0);

        $this->RegisterAttributeString('ShadingQueue', '[]');
        $this->RegisterTimer('ShadingTimer', 0, 'SHSH_ProcessQueue($_IPS[\'TARGET\']);');
        $this->RegisterTimer('DailyScheduler', 0, 'SHSH_GenerateDailySchedule($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    public function SetHouseMode(int $mode): void
    {
        $this->LogMessage("SmartHomeShading: Haus-Modus gewechselt auf " . $mode, KL_NOTIFY);

        $isAbsence = ($mode == 1 || $mode == 2);
        
        if ($isAbsence) {
            $this->GenerateDailySchedule();
            // Starte täglichen Timer für 03:00 Uhr
            $now = time();
            $nextRun = strtotime("today 03:00:00");
            if ($now >= $nextRun) {
                $nextRun = strtotime("tomorrow 03:00:00");
            }
            $diff = $nextRun - $now;
            $this->SetTimerInterval('DailyScheduler', $diff * 1000);
            
        } else {
            $this->WriteAttributeString('ShadingQueue', '[]');
            $this->SetTimerInterval('ShadingTimer', 0);
            $this->SetTimerInterval('DailyScheduler', 0);
            
            if ($mode == 5) { // Schlafen
                $this->LogMessage("Modus Schlafen: Fahre alle Rollläden auf Schlaf-Position.", KL_NOTIFY);
                $this->SetAllBlinds('ValueSleep');
            } elseif ($mode == 6) { // Putzen
                $this->LogMessage("Modus Putzen: Fahre alle Rollläden auf.", KL_NOTIFY);
                $this->SetAllBlinds('ValueOpen');
            }
        }
    }

    public function GenerateDailySchedule(): void
    {
        $this->LogMessage("Generiere Beschattungs-Fahrplan für heute...", KL_NOTIFY);
        
        // Timer für morgen 03:00 setzen
        $now = time();
        $nextRun = strtotime("tomorrow 03:00:00");
        $diff = $nextRun - $now;
        $this->SetTimerInterval('DailyScheduler', $diff * 1000);

        $blindsJson = $this->ReadPropertyString('BlindVariables');
        $blinds = json_decode($blindsJson, true);
        if (!is_array($blinds) || count($blinds) === 0) {
            return;
        }

        $sunriseTime = $this->GetTimeFromVariable('SunriseVariableID', "07:00");
        $sunsetTime = $this->GetTimeFromVariable('SunsetVariableID', "19:00");

        $queue = [];

        foreach ($blinds as $blind) {
            $id = $blind['VariableID'] ?? 0;
            if ($id <= 0) continue;

            // Morgens Auffahren (Sonnenaufgang + Zufall -10 bis +20 Min)
            $openOffset = rand(-10 * 60, 20 * 60);
            $openTime = $sunriseTime + $openOffset;
            if ($openTime > time()) {
                $queue[] = [
                    'TargetID' => $id,
                    'Value' => $blind['ValueOpen'] ?? '0',
                    'ExecuteTime' => $openTime,
                    'ActionName' => "Simulation: Auf"
                ];
            }

            // Abends Zufahren (Sonnenuntergang + Zufall -15 bis +30 Min)
            $closeOffset = rand(-15 * 60, 30 * 60);
            $closeTime = $sunsetTime + $closeOffset;
            if ($closeTime > time()) {
                $queue[] = [
                    'TargetID' => $id,
                    'Value' => $blind['ValueClosed'] ?? '1',
                    'ExecuteTime' => $closeTime,
                    'ActionName' => "Simulation: Zu"
                ];
            }
        }

        // Nach Ausführungszeit sortieren
        usort($queue, function($a, $b) {
            return $a['ExecuteTime'] <=> $b['ExecuteTime'];
        });

        $this->WriteAttributeString('ShadingQueue', json_encode($queue));
        
        if (count($queue) > 0) {
            $this->SetTimerInterval('ShadingTimer', 60000); // Jede Minute prüfen
            $this->LogMessage(count($queue) . " Fahrbefehle für heute geplant.", KL_NOTIFY);
        } else {
            $this->SetTimerInterval('ShadingTimer', 0);
        }
    }

    public function ProcessQueue(): void
    {
        $queueJson = $this->ReadAttributeString('ShadingQueue');
        $queue = json_decode($queueJson, true);
        if (!is_array($queue) || count($queue) === 0) {
            $this->SetTimerInterval('ShadingTimer', 0);
            return;
        }

        $now = time();
        $remainingQueue = [];
        $executed = false;

        foreach ($queue as $item) {
            if ($now >= $item['ExecuteTime']) {
                $this->LogMessage($item['ActionName'] . " für " . $item['TargetID'], KL_NOTIFY);
                $this->ExecuteAction((int)$item['TargetID'], (string)$item['Value']);
                $executed = true;
            } else {
                $remainingQueue[] = $item;
            }
        }

        if ($executed) {
            $this->WriteAttributeString('ShadingQueue', json_encode($remainingQueue));
        }

        if (count($remainingQueue) === 0) {
            $this->SetTimerInterval('ShadingTimer', 0);
        }
    }

    private function SetAllBlinds(string $valueKey): void
    {
        $blindsJson = $this->ReadPropertyString('BlindVariables');
        $blinds = json_decode($blindsJson, true);
        if (!is_array($blinds)) return;

        foreach ($blinds as $blind) {
            $id = $blind['VariableID'] ?? 0;
            if ($id > 0) {
                $valStr = $blind[$valueKey] ?? '';
                if ($valStr !== '') {
                    $this->ExecuteAction((int)$id, $valStr);
                }
            }
        }
    }

    private function ExecuteAction(int $targetID, string $valStr): void
    {
        if (!IPS_VariableExists($targetID)) {
            $this->LogMessage("Ziel " . $targetID . " ist keine Variable.", KL_ERROR);
            return;
        }

        $var = IPS_GetVariable($targetID);
        $val = $valStr;
        
        if ($var['VariableType'] == 0) { // Boolean
            $val = (strtolower($valStr) === 'true' || $valStr === '1');
        } elseif ($var['VariableType'] == 1) { // Integer
            $val = (int)$valStr;
        } elseif ($var['VariableType'] == 2) { // Float
            $valStr = str_replace(',', '.', $valStr);
            $val = (float)$valStr;
        }
        
        @RequestAction($targetID, $val);
    }

    private function GetTimeFromVariable(string $propName, string $defaultTime): int
    {
        $varId = $this->ReadPropertyInteger($propName);
        if ($varId > 0 && IPS_VariableExists($varId)) {
            $val = GetValue($varId);
            if (is_int($val)) {
                // Angenommen, es ist ein Unix Timestamp für heute
                return $val;
            } else {
                // String "HH:MM"
                return strtotime("today " . (string)$val);
            }
        }
        return strtotime("today " . $defaultTime);
    }
}
