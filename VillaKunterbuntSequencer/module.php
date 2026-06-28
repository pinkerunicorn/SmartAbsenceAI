<?php

declare(strict_types=1);

class VillaKunterbuntSequencer extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Konfiguration
        $this->RegisterPropertyInteger('TriggerVariable', 0);
        $this->RegisterPropertyString('TriggerValue', '');
        $this->RegisterPropertyString('Sequences', '[]');

        // Aktiv-Schalter
        $this->RegisterVariableBoolean('Active', 'Aktiv', '~Switch', 0);
        $this->EnableAction('Active');

        // Warteschlange für verzögerte Aktionen
        $this->RegisterAttributeString('Queue', '[]');
        
        // Timer für die Ausführung der Warteschlange
        $this->RegisterTimer('QueueTimer', 0, 'VKSQ_ProcessQueue($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        
        // Message Sink aufräumen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }
        
        $triggerVar = $this->ReadPropertyInteger('TriggerVariable');
        if ($triggerVar > 0 && IPS_VariableExists($triggerVar)) {
            $this->RegisterMessage($triggerVar, VM_UPDATE);
        }
        
        $this->ProcessQueue();
    }

    public function RequestAction($Ident, $Value): void
    {
        if ($Ident === 'Active') {
            $this->SetValue($Ident, $Value);
            
            if (!$Value) {
                // Warteschlange leeren bei Deaktivierung
                $this->WriteAttributeString('Queue', '[]');
                $this->SetTimerInterval('QueueTimer', 0);
                $this->LogMessage("Sequencer deaktiviert, Warteschlange geleert.", KL_NOTIFY);
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message === VM_UPDATE) {
            $triggerVar = $this->ReadPropertyInteger('TriggerVariable');
            if ($SenderID === $triggerVar) {
                $newValue = (string)$Data[0]; // Konvertiere immer zu String für einfachen Vergleich
                $targetValue = $this->ReadPropertyString('TriggerValue');
                
                if ($newValue === $targetValue) {
                    $this->TriggerSequence();
                }
            }
        }
    }

    public function TestSequence(): void
    {
        $this->LogMessage("Manuelle Test-Auslösung der Sequenz über den Test-Button.", KL_NOTIFY);
        $this->TriggerSequence(true);
    }

    private function TriggerSequence(bool $force = false): void
    {
        if (!$force && !$this->GetValue('Active')) {
            $this->LogMessage("Sequenz-Trigger empfangen, aber Sequencer ist deaktiviert.", KL_NOTIFY);
            return;
        }

        $sequencesJson = $this->ReadPropertyString('Sequences');
        $sequences = json_decode($sequencesJson, true);

        if (!is_array($sequences) || count($sequences) === 0) {
            return;
        }

        $queueJson = $this->ReadAttributeString('Queue');
        $queue = json_decode($queueJson, true);
        if (!is_array($queue)) {
            $queue = [];
        }

        $now = time();
        $itemsAdded = false;

        $this->LogMessage("Sequenz ausgelöst. Verarbeite " . count($sequences) . " Aktionen.", KL_NOTIFY);

        foreach ($sequences as $seq) {
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
                    if (!IPS_ScriptExists($targetID)) {
                        $this->LogMessage("Fehler: Ziel " . $targetID . " ist kein ausführbares Skript!", KL_ERROR);
                        return;
                    }
                    $this->LogMessage("Führe Skript/Ablaufplan aus: " . $targetID, KL_NOTIFY);
                    @IPS_RunScript($targetID);
                    break;
                case 1: // Gerät/Variable schalten (RequestAction)
                    if (!IPS_VariableExists($targetID)) {
                        $this->LogMessage("Fehler: Ziel " . $targetID . " ist keine Status-Variable!", KL_ERROR);
                        return;
                    }
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
                    
                    if (!@RequestAction($targetID, $val)) {
                        $this->LogMessage("RequestAction fehlgeschlagen! Hat die Variable " . $targetID . " überhaupt ein Aktionsskript zugewiesen oder gehört sie zu einer Instanz, die Schalten erlaubt?", KL_ERROR);
                    }
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
