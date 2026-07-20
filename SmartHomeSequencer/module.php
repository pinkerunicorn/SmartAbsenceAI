<?php

declare(strict_types=1);

require_once __DIR__ . '/../SmartLog/libs/Trait_SmartLog.php';

class SmartHomeSequencer extends IPSModuleStrict
{
    use SmartLog_Trait;

    private const ACTION_SCRIPT = 0;
    private const ACTION_DEVICE = 1;
    private const ACTION_WOL = 2;
    private const ACTION_DELAY = 3;

    public function Create(): void
    {
        parent::Create();

        // Konfiguration
        $this->RegisterPropertyString('Sequences', '[]');
        $this->RegisterPropertyString('DeactivationSequences', '[]');

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
        $this->ProcessSequenceList('Sequences', 'Eintritt');
    }

    public function RunDeactivationSequence(): void
    {
        $this->ProcessSequenceList('DeactivationSequences', 'Austritt');
    }

    private function ProcessSequenceList(string $property, string $logName): void
    {
        $this->SLog('INFO', 'Sequenz manuell ausgelöst.', "Sequenz: $logName");
        
        $sequencesJson = $this->ReadPropertyString($property);
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

        $this->SLog('INFO', 'Sequenz gestartet.', "Aktionen: " . count($sequences));

        foreach ($sequences as $seq) {
            $active = isset($seq['Active']) ? $seq['Active'] : true;
            if (!$active) {
                continue;
            }
            
            $delay = isset($seq['Delay']) ? (int)$seq['Delay'] : 0;
            
            $item = [
                'ActionType'=> $seq['ActionType'] ?? 0,
                'TargetID'=> $seq['TargetID'] ?? 0,
                'Value'=> $seq['Value'] ?? '',
                'ExecuteTime'=> $now + $delay
            ];

            if ($delay <= 0) {
                $this->ExecuteAction($item);
            } else {
                $queue[] = $item;
                $itemsAdded = true;
                $this->SLog('INFO', 'Aktion verzögert zur Warteschlange hinzugefügt.', "Ziel-ID: " . $item['TargetID'] . " | Verzögerung: $delay s");
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
        $actionType = (int)$item['ActionType'];
        $valStr = (string)$item['Value'];

        try {
            switch ($actionType) {
                case 0: // Skript / Ablaufplan ausführen
                    if ($targetID <= 0 || !IPS_ObjectExists($targetID)) {
                        $this->SLog('ERROR', 'Ausführung fehlgeschlagen.', "Grund: Ziel-ID $targetID existiert nicht");
                        return;
                    }
                    if (!IPS_ScriptExists($targetID)) {
                        $this->SLog('ERROR', 'Ausführung fehlgeschlagen.', "Grund: Ziel-ID $targetID ist kein Skript");
                        return;
                    }
                    $this->SLog('INFO', 'Skript/Ablaufplan ausgeführt.', "Skript-ID: $targetID");
                    @IPS_RunScript($targetID);
                    break;
                case 1: // Gerät/Variable schalten (RequestAction)
                    if ($targetID <= 0 || !IPS_ObjectExists($targetID)) {
                        $this->SLog('ERROR', 'Ausführung fehlgeschlagen.', "Grund: Ziel-ID $targetID existiert nicht");
                        return;
                    }
                    if (!IPS_VariableExists($targetID)) {
                        $this->SLog('ERROR', 'Ausführung fehlgeschlagen.', "Grund: Ziel-ID $targetID ist keine Status-Variable");
                        return;
                    }
                    $this->SLog('INFO', 'Variable wird geschaltet.', "Ziel-ID: $targetID | Wert: $valStr");
                    
                    // Datentyp bestimmen für korrekten Cast
                    $var = IPS_GetVariable($targetID);
                    $val = $valStr;
                    if ($var['VariableType'] == 0) { // Boolean
                        $lower = strtolower(trim($valStr));
                        $val = in_array($lower, ['true', '1', 'on', 'an', 'yes', 'ja']);
                        $this->SLog('INFO', 'Wandle Wert um.', "Von: $valStr | Zu: " . ($val ? 'TRUE' : 'FALSE'));
                    } elseif ($var['VariableType'] == 1) { // Integer
                        $val = (int)$valStr;
                    } elseif ($var['VariableType'] == 2) { // Float
                        // Erlaube auch Komma als Dezimaltrenner (z.B. "0,2")
                        $valStr = str_replace(',', '.', $valStr);
                        $val = (float)$valStr;
                    }
                    
                    if (!@RequestAction($targetID, $val)) {
                        $this->SLog('ERROR', 'RequestAction fehlgeschlagen.', "Ziel-ID: $targetID | Wert: $valStr");
                    } else {
                        $this->SLog('INFO', 'Aktion erfolgreich ausgeführt.', "Ziel-ID: $targetID | Wert: " . var_export($val, true));
                    }
                    break;
                case 2: // Wake On LAN
                    if ($targetID > 0 && function_exists('WOL_Send')) {
                        $this->SLog('INFO', 'WOL gesendet.', "Instanz-ID: $targetID");
                        @WOL_Send($targetID);
                    } else {
                        $mac = trim($valStr);
                        if (preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac)) {
                            $this->SLog('INFO', 'Natives WOL gesendet.', "MAC-Adresse: $mac");
                            $this->SendMagicPacket($mac);
                        } else {
                            $this->SLog('ERROR', 'WOL Fehler: Ungültige Eingabe.', "Eingabe: $valStr");
                        }
                    }
                    break;
                default:
                    $this->SLog('ERROR', 'Unbekannter Aktionstyp: ' . $actionType);
                    break;
            }
        } catch (Exception $e) {
            $this->SLog('ERROR', 'Fehler bei der Ausführung (Ziel ' . $targetID . '): ' . $e->getMessage());
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
            if (!@socket_sendto($socket, $msg, strlen($msg), 0, $ip, $port)) {
                $this->SLog('WARNING', 'socket_sendto fehlgeschlagen', "IP: $ip");
            }
            @socket_close($socket);
        } else {
            $this->SLog('ERROR', 'WOL Fehler.', "Grund: Konnte UDP Socket nicht erstellen");
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->SLog('INFO', $Message);
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartHomeSequencer: '. $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "ExpansionPanel",
            "caption": "⚙ Makro-Baustein: Definiert eine Liste von Aktionen, die vom Controller oder manuell ausgelöst werden können.",
            "items": []
        },
        {
            "type": "List",
            "name": "Sequences",
            "caption": "Eintritts-Ablauf (beim Betreten des Modus)",
            "rowCount": 10,
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "Aktiv",
                    "name": "Active",
                    "width": "60px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Aktion",
                    "name": "ActionType",
                    "width": "150px",
                    "add": 1,
                    "edit": {
                        "type": "Select",
                        "options": [
                            {
                                "caption": "Gerät/Variable schalten",
                                "value": 1
                            },
                            {
                                "caption": "Skript / Ablaufplan ausführen",
                                "value": 0
                            },
                            {
                                "caption": "Wake on LAN (WOL)",
                                "value": 2
                            }
                        ]
                    }
                },
                {
                    "caption": "Ziel Instanz / Skript",
                    "name": "TargetID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectObject"
                    }
                },
                {
                    "caption": "Wert (Nur für Schalten)",
                    "name": "Value",
                    "width": "150px",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Verzögerung (Sek)",
                    "name": "Delay",
                    "width": "150px",
                    "add": 0,
                    "edit": {
                        "type": "NumberSpinner",
                        "minimum": 0,
                        "maximum": 3600
                    }
                }
            ]
        },
        {
            "type": "List",
            "name": "DeactivationSequences",
            "caption": "Austritts-Ablauf (beim Verlassen des Modus)",
            "rowCount": 10,
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "Aktiv",
                    "name": "Active",
                    "width": "60px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Aktion",
                    "name": "ActionType",
                    "width": "150px",
                    "add": 1,
                    "edit": {
                        "type": "Select",
                        "options": [
                            {
                                "caption": "Gerät/Variable schalten",
                                "value": 1
                            },
                            {
                                "caption": "Skript / Ablaufplan ausführen",
                                "value": 0
                            },
                            {
                                "caption": "Wake on LAN (WOL)",
                                "value": 2
                            }
                        ]
                    }
                },
                {
                    "caption": "Ziel Instanz / Skript",
                    "name": "TargetID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectObject"
                    }
                },
                {
                    "caption": "Wert (Nur für Schalten)",
                    "name": "Value",
                    "width": "150px",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Verzögerung (Sek)",
                    "name": "Delay",
                    "width": "150px",
                    "add": 0,
                    "edit": {
                        "type": "NumberSpinner",
                        "minimum": 0,
                        "maximum": 3600
                    }
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Button",
            "caption": "Eintritts-Ablauf testen",
            "onClick": "SHSQ_RunSequence($id);",
            "icon": "Play"
        },
        {
            "type": "Button",
            "caption": "Austritts-Ablauf testen",
            "onClick": "SHSQ_RunDeactivationSequence($id);",
            "icon": "Stop"
        }
    ]
}
EOT;
    }
}


