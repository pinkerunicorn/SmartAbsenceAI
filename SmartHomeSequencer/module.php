<?php

declare(strict_types=1);

class SmartHomeSequencer extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Konfiguration
        $this->RegisterPropertyString('Sequences', '[]');

        // Warteschlange für verzögerte Aktionen
        $this->RegisterAttributeString('Queue', '[]');
        
        // Timer für die Ausführung der Warteschlange
        $this->RegisterTimer('QueueTimer', 0, 'SHSQ_ProcessQueue($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->ProcessQueue();
    }

    public function RunSequence(): void
    {
        IPS_LogMessage('SmartVillaKunterbunt', "Manuelle Auslösung der Sequenz vom Controller oder Test-Button.");
        
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

        IPS_LogMessage('SmartVillaKunterbunt', "Sequenz gestartet. Verarbeite " . count($sequences) . " Aktionen.");

        foreach ($sequences as $seq) {
            $active = isset($seq['Active']) ? $seq['Active'] : true;
            if (!$active) {
                continue;
            }
            
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
                IPS_LogMessage('SmartVillaKunterbunt', "Aktion für Ziel " . $item['TargetID'] . " zur Warteschlange hinzugefügt (Verzögerung: " . $delay . "s).");
            }
        }

        if ($itemsAdded) {
            $this->WriteAttributeString('Queue', json_encode($queue));
            $this->SetTimerInterval('QueueTimer', 1000); // Check every second
        }
    }

    // Für Abwärtskompatibilität, falls der Button noch den alten Namen nutzt
    public function TestSequence(): void
    {
        $this->RunSequence();
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
        $actionType = (int)$item['ActionType'];
        $valStr = (string)$item['Value'];

        try {
            switch ($actionType) {
                case 0: // Skript / Ablaufplan ausführen
                    if ($targetID <= 0 || !IPS_ObjectExists($targetID)) {
                        IPS_LogMessage('SmartVillaKunterbunt', "Ausführung fehlgeschlagen: Ziel-ID " . $targetID . " existiert nicht.");
                        return;
                    }
                    if (!IPS_ScriptExists($targetID)) {
                        IPS_LogMessage('SmartVillaKunterbunt', "Fehler: Ziel " . $targetID . " ist kein ausführbares Skript!");
                        return;
                    }
                    IPS_LogMessage('SmartVillaKunterbunt', "Führe Skript/Ablaufplan aus: " . $targetID);
                    @IPS_RunScript($targetID);
                    break;
                case 1: // Gerät/Variable schalten (RequestAction)
                    if ($targetID <= 0 || !IPS_ObjectExists($targetID)) {
                        IPS_LogMessage('SmartVillaKunterbunt', "Ausführung fehlgeschlagen: Ziel-ID " . $targetID . " existiert nicht.");
                        return;
                    }
                    if (!IPS_VariableExists($targetID)) {
                        IPS_LogMessage('SmartVillaKunterbunt', "Fehler: Ziel " . $targetID . " ist keine Status-Variable!");
                        return;
                    }
                    IPS_LogMessage('SmartVillaKunterbunt', "Schalte Variable " . $targetID . " auf Wert: " . $valStr);
                    
                    // Datentyp bestimmen für korrekten Cast
                    $var = IPS_GetVariable($targetID);
                    $val = $valStr;
                    if ($var['VariableType'] == 0) { // Boolean
                        $lower = strtolower(trim($valStr));
                        $val = in_array($lower, ['true', '1', 'on', 'an', 'yes', 'ja']);
                        IPS_LogMessage('SmartVillaKunterbunt', "Wandle String '$valStr' in Boolean um -> " . ($val ? "TRUE" : "FALSE"));
                    } elseif ($var['VariableType'] == 1) { // Integer
                        $val = (int)$valStr;
                    } elseif ($var['VariableType'] == 2) { // Float
                        // Erlaube auch Komma als Dezimaltrenner (z.B. "0,2")
                        $valStr = str_replace(',', '.', $valStr);
                        $val = (float)$valStr;
                    }
                    
                    if (!@RequestAction($targetID, $val)) {
                        IPS_LogMessage('SmartVillaKunterbunt', "RequestAction fehlgeschlagen! Hat die Variable " . $targetID . " überhaupt ein Aktionsskript zugewiesen oder gehört sie zu einer Instanz, die Schalten erlaubt?");
                    }
                    break;
                case 2: // Wake On LAN
                    if ($targetID > 0 && function_exists('WOL_Send')) {
                        IPS_LogMessage('SmartVillaKunterbunt', "Sende WOL an Instanz: " . $targetID);
                        @WOL_Send($targetID);
                    } else {
                        $mac = trim($valStr);
                        if (preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac)) {
                            IPS_LogMessage('SmartVillaKunterbunt', "Sende natives WOL an MAC-Adresse: " . $mac);
                            $this->SendMagicPacket($mac);
                        } else {
                            IPS_LogMessage('SmartVillaKunterbunt', "WOL Fehler: Weder eine WOL-Instanz (Ziel) noch eine gültige MAC-Adresse (im Feld Wert) angegeben. Eingabe war: " . $valStr);
                        }
                    }
                    break;
                default:
                    IPS_LogMessage('SmartVillaKunterbunt', "Unbekannter Aktionstyp: " . $actionType);
                    break;
            }
        } catch (Exception $e) {
            IPS_LogMessage('SmartVillaKunterbunt', "Fehler bei der Ausführung (Ziel " . $targetID . "): " . $e->getMessage());
        }
    }

    private function SendMagicPacket(string $mac, string $ip = "255.255.255.255", int $port = 9): void
    {
        $addr_byte = explode(':', str_replace('-', ':', $mac));
        $hw_addr = '';
        for ($a = 0; $a < 6; $a++) {
            $hw_addr .= chr(hexdec($addr_byte[$a]));
        }
        $msg = str_repeat(chr(255), 6) . str_repeat($hw_addr, 16);
        
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket) {
            @socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
            @socket_sendto($socket, $msg, strlen($msg), 0, $ip, $port);
            @socket_close($socket);
        } else {
            IPS_LogMessage('SmartVillaKunterbunt', "WOL Fehler: Konnte UDP Socket für Magic Packet nicht erstellen.");
        }
    }
}
