<?php

declare(strict_types=1);

require_once __DIR__ . '/../SmartLog/libs/Trait_SmartLog.php';

class SmartBatteryMonitor extends IPSModuleStrict
{
    use SmartLog_Trait;
    public function Create(): void
    {
        parent::Create();
        
        $this->RegisterPropertyString('BatteryVariables', '[]');
        $this->RegisterPropertyString('CheckTime', '{"hour":18,"minute":0,"second":0}');
        
        $this->RegisterTimer('DailyCheckTimer', 0, 'SBM_CheckBatteries($_IPS[\'TARGET\']);');
        
        $this->RegisterVariableBoolean('AlarmActive', 'Batterie Alarm', '', 1);
        IPS_SetIcon($this->GetIDForIdent('AlarmActive'), 'Warning');
        $this->RegisterVariableInteger('LowBatteryCount', 'Leere Batterien', '', 2);
        IPS_SetIcon($this->GetIDForIdent('LowBatteryCount'), 'Battery');
        $this->RegisterVariableString('MonitoredBatteries', 'Überwachte Batterien (Liste)', '', 3);
        IPS_SetIcon($this->GetIDForIdent('MonitoredBatteries'), 'Battery');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $list_BatteryVariables = json_decode($this->ReadPropertyString('BatteryVariables'), true);
        if (is_array($list_BatteryVariables)) {
            foreach ($list_BatteryVariables as $item) {
                $vid = $item['VariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        // ---------------------------------
        
        if (@IPS_GetObjectIDByIdent('AlarmActive', $this->InstanceID) !== false) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('AlarmActive'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Warning'
            ]);
        }
        
        if (@IPS_GetObjectIDByIdent('LowBatteryCount', $this->InstanceID) !== false) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('LowBatteryCount'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Battery'
            ]);
        }
        
        if (@IPS_GetObjectIDByIdent('MonitoredBatteries', $this->InstanceID) !== false) {
            IPS_SetIcon($this->GetIDForIdent('MonitoredBatteries'), 'Battery');
        }
        
        $this->SetDailyTimer();
        $this->CheckBatteries();
    }
    
    private function SetDailyTimer(): void
    {
        $timeStr = $this->ReadPropertyString('CheckTime');
        $timeObj = json_decode($timeStr, true);
        if (is_array($timeObj) && isset($timeObj['hour']) && isset($timeObj['minute']) && isset($timeObj['second'])) {
            $now = time();
            $target = mktime($timeObj['hour'], $timeObj['minute'], $timeObj['second'], (int)date('m', $now), (int)date('d', $now), (int)date('Y', $now));
            
            if ($target <= $now) {
                // If the time has already passed today, set it for tomorrow
                $target += 86400; 
            }
            $diff = ($target - $now) * 1000; // in milliseconds
            $this->SetTimerInterval('DailyCheckTimer', $diff);
        }
    }

    public function CheckBatteries(): void
    {
        // When checking finishes, we recalculate the timer to the next day
        $this->SetDailyTimer();

        $batteryListJson = $this->ReadPropertyString('BatteryVariables');
        $batteryList = json_decode($batteryListJson, true);
        if (!is_array($batteryList)) {
            $batteryList = [];
        }
        
        $lowBatteries = [];
        $allBatteriesLog = [];
        
        foreach ($batteryList as $item) {
            $varID = (int)($item['VariableID'] ?? 0);
            if ($varID === 0 || !IPS_VariableExists($varID)) {
                continue;
            }
            
            $var = @IPS_GetVariable($varID);
            if (!is_array($var)) {
                continue; // Skip if variable metadata is inaccessible
            }
            
            $val = @GetValue($varID);
            $type = $item['Type'] ?? 'Auto';
            $threshold = (float)($item['Threshold'] ?? 0);
            $name = !empty($item['Name']) ? $item['Name'] : IPS_GetName($varID);
            
            $isLow = false;
            
            if ($type === 'Auto') {
                $profile = $var['VariableCustomProfile'] != '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];
                $obj = @IPS_GetObject($varID);
                $ident = is_array($obj) ? $obj['ObjectIdent'] : '';
                
                if ($profile === '~Battery' || strpos(strtolower($ident), 'low_bat') !== false || strpos(strtolower($ident), 'lowbat') !== false) {
                    if ($val === true || $val === 1) $isLow = true;
                } elseif ($profile === '~Battery.Reversed') {
                    if ($val === false || $val === 0) $isLow = true;
                } elseif ($profile === '~Battery.100') {
                    if ($val !== false && $val <= $threshold) $isLow = true;
                }
            } elseif ($type === 'BoolTrue') {
                if ($val === true || $val === 1) $isLow = true;
            } elseif ($type === 'BoolFalse') {
                if ($val === false || $val === 0) $isLow = true;
            } elseif ($type === 'Percent' || $type === 'Voltage') {
                if ($val !== false && $val <= $threshold) $isLow = true;
            }
            
            $statusText = $isLow ? 'LEER' : 'OK';
            $realValue = @GetValueFormatted($varID);
            if ($realValue === false) $realValue = 'Fehler';
            
            $allBatteriesLog[] = "[$statusText] $name ($realValue)";
            
            if ($isLow) {
                // Store the custom name alongside the varID for SyncLinks
                $lowBatteries[$varID] = $name;
            }
        }
        
        $this->SetValue('MonitoredBatteries', "Gesamtanzahl: " . count($allBatteriesLog) . "\n\n" . implode("\n", $allBatteriesLog));
        
        $count = count($lowBatteries);
        $this->SetValue('AlarmActive', $count > 0);
        $this->SetValue('LowBatteryCount', $count);
        
        $this->SyncLinks($lowBatteries);
    }
    
    private function SyncLinks(array $lowBatteries): void
    {
        // Fetch all existing links underneath the instance
        $existingLinks = IPS_GetChildrenIDs($this->InstanceID);
        $linkTargets = [];
        
        foreach ($existingLinks as $id) {
            $obj = IPS_GetObject($id);
            if ($obj['ObjectType'] === 6) { // 6 = Link
                $targetID = IPS_GetLink($id)['TargetID'];
                
                // If the link points to a battery that is NO LONGER low, delete it
                if (!array_key_exists($targetID, $lowBatteries)) {
                    IPS_DeleteLink($id);
                } else {
                    $linkTargets[] = $targetID; // Keep track of existing
                    IPS_SetName($id, $lowBatteries[$targetID]); // Update name just in case it was changed in the config
                }
            }
        }
        
        // Add new links for low batteries that don't have a link yet
        foreach ($lowBatteries as $varID => $name) {
            if (!in_array($varID, $linkTargets)) {
                $linkID = IPS_CreateLink();
                IPS_SetParent($linkID, $this->InstanceID);
                IPS_SetName($linkID, $name);
                IPS_SetLinkTargetID($linkID, $varID);
                IPS_SetPosition($linkID, 10);
            }
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->SLog('INFO', $Message);
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartBatteryMonitor: ' . $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "label": "Batterie-Überwachung (SmartBatteryMonitor)"
        },
        {
            "type": "List",
            "name": "BatteryVariables",
            "caption": "Überwachte Batterien",
            "add": true,
            "delete": true,
            "sort": {
                "column": "Name",
                "direction": "ascending"
            },
            "columns": [
                {
                    "caption": "Name",
                    "name": "Name",
                    "width": "250px",
                    "add": "Neue Batterie",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Variable",
                    "name": "VariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Typ",
                    "name": "Type",
                    "width": "200px",
                    "add": "Auto",
                    "edit": {
                        "type": "Select",
                        "options": [
                            {
                                "label": "Automatisch (Profil/Ident)",
                                "value": "Auto"
                            },
                            {
                                "label": "Boolean (True = Leer)",
                                "value": "BoolTrue"
                            },
                            {
                                "label": "Boolean (False = Leer)",
                                "value": "BoolFalse"
                            },
                            {
                                "label": "Prozent",
                                "value": "Percent"
                            },
                            {
                                "label": "Spannung",
                                "value": "Voltage"
                            }
                        ]
                    }
                },
                {
                    "caption": "Schwellwert",
                    "name": "Threshold",
                    "width": "150px",
                    "add": 15,
                    "edit": {
                        "type": "NumberSpinner",
                        "digits": 2
                    }
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Hier stellst du 'Tägliche Ausführungszeit' ein."
        },
        {
            "type": "SelectTime",
            "name": "CheckTime",
            "caption": "Tägliche Ausführungszeit"
        }
    ],
    "actions": [
        {
            "type": "Button",
            "caption": "Jetzt prüfen",
            "onClick": "SBM_CheckBatteries($id);"
        }
    ]
}
EOT;
    }
}


