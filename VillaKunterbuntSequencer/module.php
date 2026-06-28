<?php

declare(strict_types=1);

class VillaKunterbuntSequencer extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Konfiguration
        $this->RegisterPropertyString('Sequences', '[]');

        // Warteschlange für verzögerte Aktionen
        $this->RegisterAttributeString('Queue', '[]');
        
        // Timer für die Ausführung der Warteschlange
        $this->RegisterTimer('QueueTimer', 0, 'VKSQ_ProcessQueue($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        
        $this->ProcessQueue();
    }

    public function SetHouseMode(int $mode): void
    {
        $sequencesJson = $this->ReadPropertyString('Sequences');
        $sequences = json_decode($sequencesJson, true);

        if (!is_array($sequences)) {
            return;
        }

        $queueJson = $this->ReadAttributeString('Queue');
        $queue = json_decode($queueJson, true);
        if (!is_array($queue)) {
            $queue = [];
        }

        $now = time();
        $itemsAdded = false;

        foreach ($sequences as $seq) {
            if (isset($seq['HouseMode']) && $seq['HouseMode'] == $mode) {
                $delay = isset($seq['Delay']) ? (int)$seq['Delay'] : 0;
                
                $item = [
                    'ActionType' => $seq['ActionType'] ?? 0,
                    'TargetID' => $seq['TargetID'] ?? 0,
                    'Value' => $seq['Value'] ?? '',
                    'ExecuteTime' => $now + $delay
                ];

                if ($delay <= 0) {
                    $this->ExecuteAction($item);
                } else {
                    $queue[] = $item;
                    $itemsAdded = true;
                    $this->LogMessage("Aktion für Ziel " . $item['TargetID'] . " zur Warteschlange hinzugefügt (Verzögerung: " . $delay . "s).", KL_NOTIFY);
                }
            }
        }

        if ($itemsAdded) {
            $this->WriteAttributeString('Queue', json_encode($queue));
            $this->SetTimerInterval('QueueTimer', 1000); // Check every second
        }
    }

    public function ProcessQueue(): void
    {
        $queueJson = $this->ReadAttributeString('Queue');
        $queue = json_decode($queueJson, true);
        if (!is_array($queue) || count($queue) === 0) {
            $this->SetTimerInterval('QueueTimer', 0);
            return;
        }

        $now = time();
        $remainingQueue = [];
        $executed = false;

        foreach ($queue as $item) {
            if ($now >= $item['ExecuteTime']) {
                $this->ExecuteAction($item);
                $executed = true;
            } else {
                $remainingQueue[] = $item;
            }
        }

        if ($executed) {
            $this->WriteAttributeString('Queue', json_encode($remainingQueue));
        }

        if (count($remainingQueue) === 0) {
            $this->SetTimerInterval('QueueTimer', 0);
        } else {
            $this->SetTimerInterval('QueueTimer', 1000);
        }
    }

    private function ExecuteAction(array $item): void
    {
        $targetID = (int)$item['TargetID'];
        if ($targetID <= 0 || !IPS_ObjectExists($targetID)) {
            $this->LogMessage("Ausführung fehlgeschlagen: Ziel-ID " . $targetID . " existiert nicht.", KL_ERROR);
            return;
        }

        $actionType = (int)$item['ActionType'];
        $valStr = (string)$item['Value'];

        try {
            switch ($actionType) {
                case 0: // Skript / Ablaufplan ausführen
                    $this->LogMessage("Führe Skript/Ablaufplan aus: " . $targetID, KL_NOTIFY);
                    IPS_RunScript($targetID);
                    break;
                case 1: // Gerät/Variable schalten (RequestAction)
                    $this->LogMessage("Schalte Variable " . $targetID . " auf Wert: " . $valStr, KL_NOTIFY);
                    
                    // Datentyp bestimmen für korrekten Cast
                    $var = IPS_GetVariable($targetID);
                    $val = $valStr;
                    if ($var['VariableType'] == 0) { // Boolean
                        $val = (strtolower($valStr) === 'true' || $valStr === '1');
                    } elseif ($var['VariableType'] == 1) { // Integer
                        $val = (int)$valStr;
                    } elseif ($var['VariableType'] == 2) { // Float
                        $val = (float)$valStr;
                    }
                    
                    RequestAction($targetID, $val);
                    break;
                case 2: // Wake On LAN
                    $this->LogMessage("Sende WOL an Instanz: " . $targetID, KL_NOTIFY);
                    if (function_exists('WOL_Send')) {
                        @WOL_Send($targetID);
                    } else {
                        $this->LogMessage("WOL_Send Funktion ist nicht verfügbar.", KL_ERROR);
                    }
                    break;
                default:
                    $this->LogMessage("Unbekannter Aktionstyp: " . $actionType, KL_ERROR);
                    break;
            }
        } catch (Exception $e) {
            $this->LogMessage("Fehler bei der Ausführung (Ziel " . $targetID . "): " . $e->getMessage(), KL_ERROR);
        }
    }
}
