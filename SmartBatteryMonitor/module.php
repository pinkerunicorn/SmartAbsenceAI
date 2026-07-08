<?php

declare(strict_types=1);

class SmartBatteryMonitor extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        
        $this->RegisterPropertyInteger('ThresholdPercent', 15);
        $this->RegisterPropertyString('CheckTime', '{"hour":18,"minute":0,"second":0}');
        
        $this->RegisterTimer('DailyCheckTimer', 0, 'SBM_CheckBatteries($_IPS[\'TARGET\']);');
        
        $this->RegisterVariableBoolean('AlarmActive', 'Batterie Alarm', '~Alert', 1);
        $this->RegisterVariableInteger('LowBatteryCount', 'Leere Batterien', '', 2);
        $this->RegisterVariableString('MonitoredBatteries', 'Überwachte Batterien (Liste)', '~TextBox', 3);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        
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

        $threshold = $this->ReadPropertyInteger('ThresholdPercent');
        
        $allVariables = IPS_GetVariableList();
        $lowBatteries = [];
        $allBatteriesLog = [];
        
        foreach ($allVariables as $varID) {
            $var = IPS_GetVariable($varID);
            $profile = $var['VariableCustomProfile'] != '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];
            $ident = IPS_GetObject($varID)['ObjectIdent'];
            
            $isLow = false;
            $isBattery = false;
            
            // Boolean Profiles or explicit LOW_BAT Ident
            if ($profile === '~Battery' || $profile === '~Battery.Reversed' || strpos(strtolower($ident), 'low_bat') !== false || strpos(strtolower($ident), 'lowbat') !== false) {
                $isBattery = true;
                // If it doesn't have a specific profile but has the LOW_BAT ident, treat it as normal Battery where true = empty
                $val = GetValue($varID);
                if (is_bool($val)) {
                    if ($profile === '~Battery.Reversed') {
                        // false means empty
                        if ($val === false) $isLow = true;
                    } else {
                        // true means empty
                        if ($val === true) $isLow = true;
                    }
                } elseif (is_int($val)) {
                    if ($val === 1) $isLow = true; // Homematic sometimes uses int for boolean
                }
            } 
            // Percentage Profile
            elseif ($profile === '~Battery.100') {
                $isBattery = true;
                $val = GetValue($varID);
                if (is_int($val) || is_float($val)) {
                    if ($val <= $threshold) $isLow = true;
                }
            }
            
            if ($isBattery) {
                $varObj = IPS_GetObject($varID);
                $parentID = $varObj['ParentID'];
                $parentName = 'Unbekannt';
                if ($parentID > 0 && IPS_ObjectExists($parentID)) {
                    $parentName = IPS_GetObject($parentID)['ObjectName'];
                }
                
                // Viele Variablen heißen "Batterie schwach" oder "Low Bat" - das verwirrt in der Liste.
                $varName = $varObj['ObjectName'];
                if (stripos($varName, 'batterie schwach') !== false || stripos($varName, 'low bat') !== false || stripos($varName, 'lowbat') !== false) {
                    $varName = 'Batterie-Status';
                }
                
                $statusText = $isLow ? 'LEER' : 'OK';
                $allBatteriesLog[] = "[$statusText] $parentName ($varName)";
            }
            
            if ($isLow) {
                $lowBatteries[] = $varID;
            }
        }
        
        $this->SetValue('MonitoredBatteries', "Gesamtanzahl: " . count($allBatteriesLog) . "\n\n" . implode("\n", $allBatteriesLog));
        
        // Update the counts and alarm
        $count = count($lowBatteries);
        $this->SetValue('AlarmActive', $count > 0);
        $this->SetValue('LowBatteryCount', $count);
        
        // Sync Links
        $this->SyncLinks($lowBatteries);
        
        IPS_LogMessage('SmartVillaKunterbunt', "SmartBatteryMonitor: Überprüfung abgeschlossen. $count leere Batterien gefunden.");
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
                if (!in_array($targetID, $lowBatteries)) {
                    IPS_DeleteLink($id);
                } else {
                    $linkTargets[] = $targetID; // Keep track of existing
                }
            }
        }
        
        // Add new links for low batteries that don't have a link yet
        foreach ($lowBatteries as $varID) {
            if (!in_array($varID, $linkTargets)) {
                $linkID = IPS_CreateLink();
                $varObj = IPS_GetObject($varID);
                $parentID = $varObj['ParentID'];
                $parentName = 'Unbekannt';
                if ($parentID > 0 && IPS_ObjectExists($parentID)) {
                    $parentName = IPS_GetObject($parentID)['ObjectName'];
                }
                
                IPS_SetParent($linkID, $this->InstanceID);
                IPS_SetName($linkID, $parentName . " (" . $varObj['ObjectName'] . ")");
                IPS_SetLinkTargetID($linkID, $varID);
                IPS_SetPosition($linkID, 10);
            }
        }
    }
}
