<?php

declare(strict_types=1);

class SmartHomeSequencer extends IPSModuleStrict
{
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
        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: Manuelle Auslösung der $logName-Sequenz.");
        
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

        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: Sequenz gestartet. Verarbeite " . count($sequences) . " Aktionen.");

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
                IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: Aktion für Ziel " . $item['TargetID'] . " zur Warteschlange hinzugefügt (Verzögerung: " . $delay . "s).");
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
                        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: Ausführung fehlgeschlagen. Ziel-ID " . $targetID . " existiert nicht.");
                        return;
                    }
                    if (!IPS_ScriptExists($targetID)) {
                        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: Fehler - Ziel " . $targetID . " ist kein ausführbares Skript!");
                        return;
                    }
                    IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: Führe Skript/Ablaufplan aus: " . $targetID);
                    @IPS_RunScript($targetID);
                    break;
                case 1: // Gerät/Variable schalten (RequestAction)
                    if ($targetID <= 0 || !IPS_ObjectExists($targetID)) {
                        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: Ausführung fehlgeschlagen. Ziel-ID " . $targetID . " existiert nicht.");
                        return;
                    }
                    if (!IPS_VariableExists($targetID)) {
                        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: Fehler - Ziel " . $targetID . " ist keine Status-Variable!");
                        return;
                    }
                    IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: Schalte Variable " . $targetID . " auf Wert: " . $valStr);
                    
                    // Datentyp bestimmen für korrekten Cast
                    $var = IPS_GetVariable($targetID);
                    $val = $valStr;
                    if ($var['VariableType'] == 0) { // Boolean
                        $lower = strtolower(trim($valStr));
                        $val = in_array($lower, ['true', '1', 'on', 'an', 'yes', 'ja']);
                        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: Wandle String '$valStr' in Boolean um -> " . ($val ? "TRUE" : "FALSE"));
                    } elseif ($var['VariableType'] == 1) { // Integer
                        $val = (int)$valStr;
                    } elseif ($var['VariableType'] == 2) { // Float
                        // Erlaube auch Komma als Dezimaltrenner (z.B. "0,2")
                        $valStr = str_replace(',', '.', $valStr);
                        $val = (float)$valStr;
                    }
                    
                    if (!@RequestAction($targetID, $val)) {
                        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: RequestAction fehlgeschlagen! Hat die Variable " . $targetID . " überhaupt ein Aktionsskript zugewiesen oder gehört sie zu einer Instanz, die Schalten erlaubt?");
                    }
                    break;
                case 2: // Wake On LAN
                    if ($targetID > 0 && function_exists('WOL_Send')) {
                        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: Sende WOL an Instanz: " . $targetID);
                        @WOL_Send($targetID);
                    } else {
                        $mac = trim($valStr);
                        if (preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac)) {
                            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: Sende natives WOL an MAC-Adresse: " . $mac);
                            $this->SendMagicPacket($mac);
                        } else {
                            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: WOL Fehler - Weder eine WOL-Instanz (Ziel) noch eine gültige MAC-Adresse (im Feld Wert) angegeben. Eingabe war: " . $valStr);
                        }
                    }
                    break;
                default:
                    IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: Unbekannter Aktionstyp: " . $actionType);
                    break;
            }
        } catch (Exception $e) {
            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: Fehler bei der Ausführung (Ziel " . $targetID . "): " . $e->getMessage());
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
            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeSequencer: WOL Fehler - Konnte UDP Socket für Magic Packet nicht erstellen.");
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartHomeSequencer: ' . $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "caption": "Makro-Baustein: Definiert eine Liste von Aktionen, die vom Controller oder manuell ausgelöst werden können."
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
            "onClick": "SHSQ_RunSequence($id);"
        },
        {
            "type": "Button",
            "caption": "Austritts-Ablauf testen",
            "onClick": "SHSQ_RunDeactivationSequence($id);"
        }
    ]
}
EOT;
    }
}


