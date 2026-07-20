<?php

declare(strict_types=1);

require_once __DIR__ . '/../SmartLog/libs/Trait_SmartLog.php';

class SmartActiveLighting extends IPSModuleStrict
{
    use SmartLog_Trait;
    public function Create(): void
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyString('MotionRules', '[]');
        $this->RegisterPropertyString('DoorRules', '[]');
        $this->RegisterPropertyString('TwilightRules', '[]');
        $this->RegisterPropertyString('SceneRules', '[]');
        $this->RegisterPropertyString('ButtonRules', '[]');
        $this->RegisterPropertyString('SyncRules', '[]');
        $this->RegisterPropertyInteger('GlobalLuxSensorID', 0);
        $this->RegisterPropertyInteger('SunsetVariableID', 0);
        $this->RegisterPropertyInteger('SunriseVariableID', 0);

        // Attributes
        $this->RegisterAttributeString('ActiveTimers', '[]');

        // Timer for daily recalculation of sunset/sunrise
        $this->RegisterTimer('DailyTwilightRecalc', 0, 'SAL_CalculateTwilightTimers($_IPS[\'TARGET\']);');

        // Pre-register timers for up to 50 rules per category (mandatory in IPS 7+)
        for ($i = 0; $i < 50; $i++) {
            $this->RegisterTimer("MotionOffTimer_$i", 0, 'SAL_ProcessMotionOff($_IPS[\'TARGET\'], ' . $i . ');');
            $this->RegisterTimer("DoorOffTimer_$i", 0, 'SAL_ProcessDoorOff($_IPS[\'TARGET\'], ' . $i . ');');
            $this->RegisterTimer("TwilightTimer_$i", 0, 'SAL_ProcessTwilightTrigger($_IPS[\'TARGET\'], ' . $i . ');');
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $ref_SunsetVariableID = $this->ReadPropertyInteger('SunsetVariableID');
        if ($ref_SunsetVariableID > 1 && @IPS_ObjectExists($ref_SunsetVariableID)) {
            $this->RegisterReference($ref_SunsetVariableID);
        }
        $ref_SunriseVariableID = $this->ReadPropertyInteger('SunriseVariableID');
        if ($ref_SunriseVariableID > 1 && @IPS_ObjectExists($ref_SunriseVariableID)) {
            $this->RegisterReference($ref_SunriseVariableID);
        }
        $ref_GlobalLuxSensorID = $this->ReadPropertyInteger('GlobalLuxSensorID');
        if ($ref_GlobalLuxSensorID > 1 && @IPS_ObjectExists($ref_GlobalLuxSensorID)) {
            $this->RegisterReference($ref_GlobalLuxSensorID);
        }
        $list_MotionRules = json_decode($this->ReadPropertyString('MotionRules'), true);
        if (is_array($list_MotionRules)) {
            foreach ($list_MotionRules as $item) {
                $vid = $item['MotionVariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
                $vid = $item['TargetLightID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        $list_DoorRules = json_decode($this->ReadPropertyString('DoorRules'), true);
        if (is_array($list_DoorRules)) {
            foreach ($list_DoorRules as $item) {
                $vid = $item['DoorVariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
                $vid = $item['TargetLightID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        $list_TwilightRules = json_decode($this->ReadPropertyString('TwilightRules'), true);
        if (is_array($list_TwilightRules)) {
            foreach ($list_TwilightRules as $item) {
                $vid = $item['TargetLightID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        $list_SceneRules = json_decode($this->ReadPropertyString('SceneRules'), true);
        if (is_array($list_SceneRules)) {
            foreach ($list_SceneRules as $item) {
                $vid = $item['SceneVariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
                $vid = $item['TargetLightID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        $list_ButtonRules = json_decode($this->ReadPropertyString('ButtonRules'), true);
        if (is_array($list_ButtonRules)) {
            foreach ($list_ButtonRules as $item) {
                $vid = $item['ButtonVariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
                $vid = $item['TargetLightID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        $list_SyncRules = json_decode($this->ReadPropertyString('SyncRules'), true);
        if (is_array($list_SyncRules)) {
            foreach ($list_SyncRules as $item) {
                $vid = $item['MasterVariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
                $vid = $item['TargetLightID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        // ---------------------------------


        // Unregister all previous messages to prevent duplicates
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $senderMessages) {
            foreach ($senderMessages as $messageID) {
                $this->UnregisterMessage($senderID, $messageID);
            }
        }

        // Register Motion Sensors
        $motionRules = json_decode($this->ReadPropertyString('MotionRules'), true);
        if (is_array($motionRules)) {
            foreach ($motionRules as $rule) {
                if (isset($rule['MotionVariableID']) && $rule['MotionVariableID'] > 0) {
                    $this->RegisterMessage($rule['MotionVariableID'], VM_UPDATE);
                }
            }
        }

        // Register Door Sensors
        $doorRules = json_decode($this->ReadPropertyString('DoorRules'), true);
        if (is_array($doorRules)) {
            foreach ($doorRules as $rule) {
                if (isset($rule['DoorVariableID']) && $rule['DoorVariableID'] > 0) {
                    $this->RegisterMessage($rule['DoorVariableID'], VM_UPDATE);
                }
            }
        }

        // Register Scene Triggers
        $sceneRules = json_decode($this->ReadPropertyString('SceneRules'), true);
        if (is_array($sceneRules)) {
            foreach ($sceneRules as $rule) {
                if (isset($rule['SceneVariableID']) && $rule['SceneVariableID'] > 0) {
                    $this->RegisterMessage($rule['SceneVariableID'], VM_UPDATE);
                }
            }
        }

        // Register Button Triggers & Maintain Group Variables
        $buttonRules = json_decode($this->ReadPropertyString('ButtonRules'), true);
        $activeGroups = [];
        if (is_array($buttonRules)) {
            foreach ($buttonRules as $rule) {
                if (isset($rule['ButtonVariableID']) && $rule['ButtonVariableID'] > 0) {
                    $this->RegisterMessage($rule['ButtonVariableID'], VM_UPDATE);
                }
                $groupName = trim($rule['GroupName'] ?? '');
                if ($groupName !== '') {
                    $ident = 'Group_' . preg_replace('/[^A-Za-z0-9_]/', '', $groupName);
                    $activeGroups[$ident] = $groupName;
                }
            }
        }

        // Register Sync Triggers
        $syncRules = json_decode($this->ReadPropertyString('SyncRules'), true);
        if (is_array($syncRules)) {
            foreach ($syncRules as $rule) {
                if (isset($rule['MasterVariableID']) && $rule['MasterVariableID'] > 0) {
                    $this->RegisterMessage($rule['MasterVariableID'], VM_UPDATE);
                }
            }
        }

        foreach ($activeGroups as $ident => $name) {
            $this->MaintainVariable($ident, $name, 0, '', 0, true);
            $this->EnableAction($ident);
            IPS_SetVariableCustomPresentation($this->GetIDForIdent($ident), [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
                'ICON' => 'Bulb'
            ]);
        }
        
        // Delete inactive groups
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $obj = IPS_GetObject($childID);
            if (strpos($obj['ObjectIdent'], 'Group_') === 0) {
                if (!isset($activeGroups[$obj['ObjectIdent']])) {
                    $this->MaintainVariable($obj['ObjectIdent'], '', 0, '', 0, false);
                }
            }
        }

        // Calculate Twilight Timers and start midnight recalc timer
        $this->CalculateTwilightTimers();
        
        // Timer runs every night at 00:05 to recalculate twilight events
        $now = time();
        $nextMidnight = strtotime('tomorrow 00:05');
        $this->SetTimerInterval('DailyTwilightRecalc', ($nextMidnight - $now) * 1000);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if (strpos($Ident, 'Group_') === 0) {
            $this->SetValue($Ident, $Value);
            $this->SwitchGroup($Ident, (bool)$Value);
        }
    }

    private function SwitchGroup(string $Ident, bool $Value): void
    {
        $buttonRules = json_decode($this->ReadPropertyString('ButtonRules'), true);
        if (is_array($buttonRules)) {
            $groupIdent = str_replace('Group_', '', $Ident);
            foreach ($buttonRules as $rule) {
                $ruleGroupName = trim($rule['GroupName'] ?? '');
                if ($ruleGroupName !== '') {
                    $ruleIdent = preg_replace('/[^A-Za-z0-9_]/', '', $ruleGroupName);
                    if ($ruleIdent === $groupIdent) {
                        $tid = $rule['TargetLightID'] ?? 0;
                        if ($tid > 0 && IPS_VariableExists($tid)) {
                            $var = IPS_GetVariable($tid);
                            if ($var['VariableType'] == 0) {
                                if (!@RequestAction($tid, $Value)) {
                                    $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $tid | Wert: " . var_export($Value, true));
                                }
                            } else {
                                if (!@RequestAction($tid, $Value ? 100 : 0)) {
                                    $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $tid | Wert: " . var_export($Value ? 100 : 0, true));
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message == VM_UPDATE) {
            $val = $Data[0]; // New value
            $isTrigger = false;
            if (is_bool($val)) {
                $isTrigger = $val;
            } elseif (is_int($val) || is_float($val)) {
                $isTrigger = ($val > 0);
            } elseif (is_string($val)) {
                $lowerVal = strtolower(trim($val));
                $isTrigger = in_array($lowerVal, ['true', 'open', 'on', '1', 'geöffnet']);
            }

            // Check if Sender is a Motion Sensor
            $motionRules = json_decode($this->ReadPropertyString('MotionRules'), true);
            if (is_array($motionRules)) {
                foreach ($motionRules as $index => $rule) {
                    if (isset($rule['MotionVariableID']) && $rule['MotionVariableID'] == $SenderID) {
                        if ($isTrigger) {
                            $this->ProcessMotionTrigger($rule, $index);
                        } else {
                            // Some motion sensors send 'false'when motion stops. We don't turn off immediately, 
                            // we rely on the off-delay timer which was set/reset when motion started.
                            // Or we could start the countdown here. For now, the countdown starts/resets on motion.
                        }
                    }
                }
            }

            // Check if Sender is a Door Sensor
            $doorRules = json_decode($this->ReadPropertyString('DoorRules'), true);
            if (is_array($doorRules)) {
                foreach ($doorRules as $index => $rule) {
                    if (isset($rule['DoorVariableID']) && $rule['DoorVariableID'] == $SenderID) {
                        $this->ProcessDoorTrigger($rule, $index, $isTrigger);
                    }
                }
            }

            // Check if Sender is a Scene Trigger
            $sceneRules = json_decode($this->ReadPropertyString('SceneRules'), true);
            if (is_array($sceneRules)) {
                foreach ($sceneRules as $rule) {
                    if (isset($rule['SceneVariableID']) && $rule['SceneVariableID'] == $SenderID && $isTrigger) {
                        $this->ProcessSceneTrigger($rule);
                    }
                }
            }

            // Check if Sender is a Master in SyncRules
            $syncRules = json_decode($this->ReadPropertyString('SyncRules'), true);
            if (is_array($syncRules)) {
                foreach ($syncRules as $rule) {
                    if (isset($rule['MasterVariableID']) && $rule['MasterVariableID'] == $SenderID) {
                        $targetId = $rule['TargetLightID'] ?? 0;
                        if ($targetId > 0 && IPS_VariableExists($targetId)) {
                            $targetVar = IPS_GetVariable($targetId);
                            $sourceVar = IPS_GetVariable($SenderID);
                            
                            $actionValue = $val;
                            
                            if ($targetVar['VariableType'] == 0 && $sourceVar['VariableType'] != 0) {
                                // Master is Dimmer (int/float), Target is Boolean
                                $actionValue = ($val > 0);
                            } elseif ($targetVar['VariableType'] != 0 && $sourceVar['VariableType'] == 0) {
                                // Master is Boolean, Target is Dimmer
                                $targetRange = $this->GetProfileMinMax($targetId);
                                $actionValue = $val ? $targetRange['max'] : $targetRange['min'];
                            } elseif ($targetVar['VariableType'] != 0 && $sourceVar['VariableType'] != 0) {
                                // Master is Dimmer, Target is Dimmer. Scale the value!
                                $sourceRange = $this->GetProfileMinMax($SenderID);
                                $targetRange = $this->GetProfileMinMax($targetId);
                                
                                $sourcePercentage = ($val - $sourceRange['min']) / max(0.001, $sourceRange['max'] - $sourceRange['min']);
                                if ($sourcePercentage < 0) $sourcePercentage = 0;
                                if ($sourcePercentage > 1) $sourcePercentage = 1;
                                
                                $actionValue = $targetRange['min'] + ($sourcePercentage * ($targetRange['max'] - $targetRange['min']));
                            }
                            
                            // Type cast strictly to match Symcon Variable Type
                            if ($targetVar['VariableType'] == 0) {
                                $actionValue = (bool)$actionValue;
                            } elseif ($targetVar['VariableType'] == 1) {
                                $actionValue = (int)round($actionValue);
                            } elseif ($targetVar['VariableType'] == 2) {
                                $actionValue = (float)$actionValue;
                            }
                            
                            if (!@RequestAction($targetId, $actionValue)) {
                                $this->SendDebug('SyncRules', 'Fehler beim Schalten von TargetID: ' . $targetId, 0);
                            }
                        }
                    }
                }
            }

            // Check if Sender is a Button Trigger (Toggle)
            $buttonRules = json_decode($this->ReadPropertyString('ButtonRules'), true);
            if (is_array($buttonRules)) {
                $targetsToToggle = [];
                $associatedGroups = [];
                foreach ($buttonRules as $rule) {
                    if (isset($rule['ButtonVariableID']) && $rule['ButtonVariableID'] == $SenderID) {
                        
                        $triggerValStr = strtolower(trim((string)($rule['TriggerValue'] ?? 'true')));
                        $currentValStr = strtolower(trim((string)$val));
                        
                        $matched = false;
                        if ($triggerValStr === 'true') {
                            $matched = $isTrigger; // Fallback to generic trigger logic for booleans/numbers
                        }
                        
                        if ($triggerValStr === $currentValStr) {
                            $matched = true;
                        }

                        if ($matched) {
                            $targetId = $rule['TargetLightID'] ?? 0;
                            if ($targetId > 0 && IPS_VariableExists($targetId)) {
                                $targetsToToggle[] = $targetId;
                            }
                            $groupName = trim($rule['GroupName'] ?? '');
                            if ($groupName !== '') {
                                $ident = 'Group_' . preg_replace('/[^A-Za-z0-9_]/', '', $groupName);
                                $associatedGroups[$ident] = true;
                            }
                        }
                    }
                }
                
                // Synchronize all targets mapped to this button
                if (count($targetsToToggle) > 0) {
                    $anyOn = false;
                    foreach ($targetsToToggle as $tid) {
                        $var = IPS_GetVariable($tid);
                        $cv = GetValue($tid);
                        if (($var['VariableType'] == 0 && $cv) || ($var['VariableType'] != 0 && $cv > 0)) {
                            $anyOn = true;
                            break;
                        }
                    }
                    
                    // If any is ON -> turn ALL OFF. If all are OFF -> turn ALL ON.
                    $newState = !$anyOn;
                    foreach ($targetsToToggle as $tid) {
                        $var = IPS_GetVariable($tid);
                        if ($var['VariableType'] == 0) {
                            if (!@RequestAction($tid, $newState)) {
                                $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $tid | Wert: " . var_export($newState, true));
                            }
                        } else {
                            if (!@RequestAction($tid, $newState ? 100 : 0)) {
                                $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $tid | Wert: " . var_export($newState ? 100 : 0, true));
                            }
                        }
                    }
                    
                    // Update group variables
                    foreach (array_keys($associatedGroups) as $ident) {
                        if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false) {
                            $this->SetValue($ident, $newState);
                        }
                    }
                }
            }
        }
    }

    private function ProcessMotionTrigger(array $rule, int $ruleIndex): void
    {
        $targetId = $rule['TargetLightID'] ?? 0;
        if ($targetId <= 0 || !IPS_VariableExists($targetId)) return;

        // Check Lux
        $luxId = $this->ReadPropertyInteger('GlobalLuxSensorID');
        $maxLux = $rule['MaxLux'] ?? 50;
        if ($luxId > 0 && IPS_VariableExists($luxId)) {
            $currentLux = GetValue($luxId);
            if ($currentLux >= $maxLux) {
                return; // Too bright, do not turn on
            }
        }

        // Night Mode?
        $nightMode = $rule['NightMode'] ?? false;
        $targetValue = true; // Default Boolean Switch
        if ($nightMode) {
            $hour = (int)date('H');
            if ($hour >= 23 || $hour < 6) { // Night time
                $targetValue = 10; // 10%
            } else {
                $targetValue = 100; // 100%
            }
        }

        // Turn on
        if (is_bool($targetValue)) {
            if (!@RequestAction($targetId, true)) {
                $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $targetId | Wert: true");
            }
        } else {
            // Check if target is a boolean or integer/float (dimmer)
            $var = IPS_GetVariable($targetId);
            if ($var['VariableType'] == 0) { // Boolean
                if (!@RequestAction($targetId, true)) {
                    $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $targetId | Wert: true");
                }
            } else {
                if (!@RequestAction($targetId, $targetValue)) {
                    $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $targetId | Wert: " . var_export($targetValue, true));
                }
            }
        }

        // Set Off-Delay Timer
        $duration = $rule['DurationSec'] ?? 120;
        $timerName = 'MotionOffTimer_'. $ruleIndex;
        $this->SetTimerInterval($timerName, $duration * 1000);
        
        // Track active timer
        $activeTimers = json_decode($this->ReadAttributeString('ActiveTimers'), true);
        if (!is_array($activeTimers)) $activeTimers = [];
        $activeTimers[$timerName] = $targetId;
        $this->WriteAttributeString('ActiveTimers', json_encode($activeTimers));
    }

    public function ProcessMotionOff(int $ruleIndex): void
    {
        $timerName = 'MotionOffTimer_'. $ruleIndex;
        $this->SetTimerInterval($timerName, 0); // Stop timer

        $motionRules = json_decode($this->ReadPropertyString('MotionRules'), true);
        if (is_array($motionRules) && isset($motionRules[$ruleIndex])) {
            $targetId = $motionRules[$ruleIndex]['TargetLightID'] ?? 0;
            if ($targetId > 0 && IPS_VariableExists($targetId)) {
                $var = IPS_GetVariable($targetId);
                if ($var['VariableType'] == 0) {
                    if (!@RequestAction($targetId, false)) {
                        $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $targetId | Wert: false");
                    }
                } else {
                    if (!@RequestAction($targetId, 0)) {
                        $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $targetId | Wert: 0");
                    }
                }
            }
        }
    }

    private function ProcessDoorTrigger(array $rule, int $ruleIndex, bool $isOpen): void
    {
        $targetId = $rule['TargetLightID'] ?? 0;
        if ($targetId <= 0 || !IPS_VariableExists($targetId)) return;

        $timerName = 'DoorOffTimer_' . $ruleIndex;

        if ($isOpen) {
            // Stop off-timer if it's running
            $this->SetTimerInterval($timerName, 0);

            // Check Lux
            $luxId = $this->ReadPropertyInteger('GlobalLuxSensorID');
            $maxLux = $rule['MaxLux'] ?? 1000;
            if ($luxId > 0 && IPS_VariableExists($luxId)) {
                $currentLux = GetValue($luxId);
                if ($currentLux >= $maxLux) {
                    return; // Too bright, do not turn on
                }
            }

            // Night Mode?
            $nightMode = $rule['NightMode'] ?? false;
            $targetValue = true; // Default Boolean Switch
            if ($nightMode) {
                $hour = (int)date('H');
                if ($hour >= 23 || $hour < 6) { // Night time
                    $targetValue = 10; // 10%
                } else {
                    $targetValue = 100; // 100%
                }
            }

            // Turn on
            $var = IPS_GetVariable($targetId);
            if ($var['VariableType'] == 0) { // Boolean
                if (!@RequestAction($targetId, true)) {
                    $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $targetId | Wert: true");
                }
            } else {
                if (!@RequestAction($targetId, $targetValue)) {
                    $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $targetId | Wert: " . var_export($targetValue, true));
                }
            }
            
            // Track active timer (reusing active timer dict so house mode can clear it)
            $activeTimers = json_decode($this->ReadAttributeString('ActiveTimers'), true);
            if (!is_array($activeTimers)) $activeTimers = [];
            $activeTimers[$timerName] = $targetId;
            $this->WriteAttributeString('ActiveTimers', json_encode($activeTimers));

        } else {
            // Door closed, start off-delay timer
            $duration = $rule['DurationSec'] ?? 10;
            if ($duration == 0) {
                $this->ProcessDoorOff($ruleIndex);
            } else {
                $this->SetTimerInterval($timerName, $duration * 1000);
            }
        }
    }

    public function ProcessDoorOff(int $ruleIndex): void
    {
        $timerName = 'DoorOffTimer_' . $ruleIndex;
        $this->SetTimerInterval($timerName, 0); // Stop timer

        $doorRules = json_decode($this->ReadPropertyString('DoorRules'), true);
        if (is_array($doorRules) && isset($doorRules[$ruleIndex])) {
            $targetId = $doorRules[$ruleIndex]['TargetLightID'] ?? 0;
            if ($targetId > 0 && IPS_VariableExists($targetId)) {
                $var = IPS_GetVariable($targetId);
                if ($var['VariableType'] == 0) {
                    if (!@RequestAction($targetId, false)) {
                        $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $targetId | Wert: false");
                    }
                } else {
                    if (!@RequestAction($targetId, 0)) {
                        $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $targetId | Wert: 0");
                    }
                }
            }
        }
    }

    private function ProcessSceneTrigger(array $rule): void
    {
        $targetId = $rule['TargetLightID'] ?? 0;
        if ($targetId <= 0 || !IPS_VariableExists($targetId)) return;

        $targetValStr = $rule['TargetValue'] ?? 'true';
        $targetVal = null;
        
        if (strtolower($targetValStr) === 'true') $targetVal = true;
        elseif (strtolower($targetValStr) === 'false') $targetVal = false;
        elseif (is_numeric($targetValStr)) $targetVal = (float)$targetValStr;
        else $targetVal = $targetValStr;

        if (!@RequestAction($targetId, $targetVal)) {
            $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $targetId | Wert: " . var_export($targetVal, true));
        }
    }


    public function CalculateTwilightTimers(): void
    {
        // Stop all twilight timers first to clear deleted or inactive rules
        for ($i = 0; $i < 50; $i++) {
            $this->SetTimerInterval("TwilightTimer_$i", 0);
        }

        $rules = json_decode($this->ReadPropertyString('TwilightRules'), true);
        if (!is_array($rules)) return;

        $sunsetId = $this->ReadPropertyInteger('SunsetVariableID');
        $sunriseId = $this->ReadPropertyInteger('SunriseVariableID');
        
        $sunsetTime = 0;
        $sunriseTime = 0;

        if ($sunsetId > 0 && IPS_VariableExists($sunsetId)) {
            $sunsetTime = (int)GetValue($sunsetId);
        }
        if ($sunriseId > 0 && IPS_VariableExists($sunriseId)) {
            $sunriseTime = (int)GetValue($sunriseId);
        }

        $now = time();

        foreach ($rules as $index => $rule) {
            if ($index >= 50) break;

            $isActive = $rule['Active'] ?? true;
            if (!$isActive) {
                continue;
            }

            $triggerType = $rule['TriggerType'] ?? 1; // 1=Sunset, 2=Sunrise, 3=Time
            $timeVal = $rule['TimeValue'] ?? '0';
            
            $targetTime = 0;

            if ($triggerType == 1 && $sunsetTime > 0) {
                $offset = (int)$timeVal * 60; // offset in minutes
                $targetTime = $sunsetTime + $offset;
            } elseif ($triggerType == 2 && $sunriseTime > 0) {
                $offset = (int)$timeVal * 60;
                $targetTime = $sunriseTime + $offset;
            } elseif ($triggerType == 3) {
                $timeParts = explode(':', $timeVal);
                if (count($timeParts) == 2) {
                    $targetTime = mktime((int)$timeParts[0], (int)$timeParts[1], 0, (int)date('m'), (int)date('d'), (int)date('Y'));
                }
            }

            if ($targetTime > 0) {
                // If the time is in the past, schedule it for tomorrow
                if ($targetTime <= $now) {
                    $targetTime += 86400;
                }
                
                $diffMs = ($targetTime - $now) * 1000;
                $timerName = 'TwilightTimer_'. $index;
                $this->SetTimerInterval($timerName, $diffMs);
            }
        }
    }

    public function ProcessTwilightTrigger(int $ruleIndex): void
    {
        // One-shot timer, so disable it
        $timerName = 'TwilightTimer_'. $ruleIndex;
        $this->SetTimerInterval($timerName, 0);

        $rules = json_decode($this->ReadPropertyString('TwilightRules'), true);
        if (is_array($rules) && isset($rules[$ruleIndex])) {
            $isActive = $rules[$ruleIndex]['Active'] ?? true;
            if ($isActive) {
                $targetId = $rules[$ruleIndex]['TargetLightID'] ?? 0;
                $actionVal = $rules[$ruleIndex]['ActionValue'] ?? 1; // 1=On, 0=Off

                if ($targetId > 0 && IPS_VariableExists($targetId)) {
                    $var = IPS_GetVariable($targetId);
                    if ($var['VariableType'] == 0) {
                        if (!@RequestAction($targetId, ($actionVal == 1))) {
                            $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $targetId | Wert: " . var_export(($actionVal == 1), true));
                        }
                    } else {
                        if (!@RequestAction($targetId, ($actionVal == 1) ? 100 : 0)) {
                            $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $targetId | Wert: " . var_export(($actionVal == 1) ? 100 : 0, true));
                        }
                    }
                }
            }
        }
        
        // We recalculate immediately so the next day's event is queued
        $this->CalculateTwilightTimers();
    }

    public function SetHouseMode(int $mode): void
    {
        // 0=Anwesenheit, 1=Abwesenheit, 2=Urlaub, 3=Party, 4=Heimkino, 5=Schlafen, 6=Putzen
        // Bei Abwesenheit/Urlaub deaktivieren wir die Bewegungsmelder-Lichter
        // Das passiert am einfachsten, indem wir alle Motion-Off-Timer löschen und die aktiven Lichter ausschalten
        
        if ($mode == 1 || $mode == 2 || $mode == 5) {
            $activeTimers = json_decode($this->ReadAttributeString('ActiveTimers'), true);
            if (is_array($activeTimers)) {
                foreach ($activeTimers as $timerName => $targetId) {
                    $this->SetTimerInterval($timerName, 0);
                    if ($targetId > 0 && IPS_VariableExists($targetId)) {
                        $var = IPS_GetVariable($targetId);
                        if ($var['VariableType'] == 0) {
                            if (!@RequestAction($targetId, false)) {
                                $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $targetId | Wert: false");
                            }
                        } else {
                            if (!@RequestAction($targetId, 0)) {
                                $this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', "ID: $targetId | Wert: 0");
                            }
                        }
                    }
                }
            }
            $this->WriteAttributeString('ActiveTimers', '[]');
            $this->SLog('INFO', 'Haus-Modus hat gewechselt. Schalte aktive Bewegungslichter aus.');
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->SLog('INFO', $Message);
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartActiveLighting: '. $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "ExpansionPanel",
            "caption": "⚙ Bewegungsgesteuerte Beleuchtung",
            "items": []
        },
        {
            "type": "List",
            "name": "MotionRules",
            "caption": "Bewegungsmelder-Regeln",
            "rowCount": 5,
            "add": true,
            "delete": true,
            "columns": [
                {
                    "caption": "Sensor (Bewegung)",
                    "name": "MotionVariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Ziel-Licht (Aktor)",
                    "name": "TargetLightID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Dauer (Sek)",
                    "name": "DurationSec",
                    "width": "80px",
                    "add": 120,
                    "edit": {
                        "type": "NumberSpinner"
                    }
                },
                {
                    "caption": "Max Lux",
                    "name": "MaxLux",
                    "width": "80px",
                    "add": 50,
                    "edit": {
                        "type": "NumberSpinner"
                    }
                },
                {
                    "caption": "Nacht-Modus (10%)",
                    "name": "NightMode",
                    "width": "100px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                }
            ]
        },
        {
            "type": "List",
            "name": "DoorRules",
            "caption": "Tür-/Fenster-Kontakt-Regeln",
            "rowCount": 5,
            "add": true,
            "delete": true,
            "columns": [
                {
                    "caption": "Sensor (Tür/Fenster)",
                    "name": "DoorVariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Ziel-Licht (Aktor)",
                    "name": "TargetLightID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Nachlauf (Sek)",
                    "name": "DurationSec",
                    "width": "80px",
                    "add": 10,
                    "edit": {
                        "type": "NumberSpinner"
                    }
                },
                {
                    "caption": "Max Lux",
                    "name": "MaxLux",
                    "width": "80px",
                    "add": 1000,
                    "edit": {
                        "type": "NumberSpinner"
                    }
                },
                {
                    "caption": "Nacht-Modus (10%)",
                    "name": "NightMode",
                    "width": "100px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Zeit- & Dämmerungssteuerung"
        },
        {
            "type": "List",
            "name": "TwilightRules",
            "caption": "Dämmerungs-Regeln",
            "rowCount": 5,
            "add": true,
            "delete": true,
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
                    "caption": "Trigger-Typ",
                    "name": "TriggerType",
                    "width": "150px",
                    "add": 1,
                    "edit": {
                        "type": "Select",
                        "options": [
                            {
                                "label": "Sonnenuntergang",
                                "value": 1
                            },
                            {
                                "label": "Sonnenaufgang",
                                "value": 2
                            },
                            {
                                "label": "Uhrzeit",
                                "value": 3
                            }
                        ]
                    }
                },
                {
                    "caption": "Offset (Min) / Zeit (HH:MM)",
                    "name": "TimeValue",
                    "width": "150px",
                    "add": "-30",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Ziel-Licht",
                    "name": "TargetLightID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Aktion",
                    "name": "ActionValue",
                    "width": "100px",
                    "add": 1,
                    "edit": {
                        "type": "Select",
                        "options": [
                            {
                                "label": "Einschalten",
                                "value": 1
                            },
                            {
                                "label": "Ausschalten",
                                "value": 0
                            }
                        ]
                    }
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Lichtstimmungen / Szenen"
        },
        {
            "type": "List",
            "name": "SceneRules",
            "caption": "Szenen-Konfiguration",
            "rowCount": 5,
            "add": true,
            "delete": true,
            "columns": [
                {
                    "caption": "Trigger (Szenen-Schalter)",
                    "name": "SceneVariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Ziel-Licht",
                    "name": "TargetLightID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Ziel-Wert (Bool / %)",
                    "name": "TargetValue",
                    "width": "150px",
                    "add": "true",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Manuelle Steuerung"
        },
        {
            "type": "List",
            "name": "ButtonRules",
            "caption": "Taster-Steuerung (Toggle)",
            "rowCount": 5,
            "add": true,
            "delete": true,
            "columns": [
                {
                    "caption": "Taster (Impuls)",
                    "name": "ButtonVariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Ziel-Licht",
                    "name": "TargetLightID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Auslöse-Wert",
                    "name": "TriggerValue",
                    "width": "100px",
                    "add": "true",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Gruppen-Name (Virtual Switch)",
                    "name": "GroupName",
                    "width": "150px",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Synchronisation (Master-Slave)"
        },
        {
            "type": "List",
            "name": "SyncRules",
            "caption": "Licht-Synchronisation",
            "rowCount": 5,
            "add": true,
            "delete": true,
            "columns": [
                {
                    "caption": "Master-Licht / Dimmer",
                    "name": "MasterVariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Ziel-Licht (Slave)",
                    "name": "TargetLightID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Abhängigkeiten"
        },
        {
            "type": "SelectVariable",
            "name": "SunsetVariableID",
            "caption": "Variable für Sonnenuntergang (Unix Timestamp)"
        },
        {
            "type": "Label",
            "caption": "Hier stellst du 'Variable für Sonnenaufgang (Unix Timestamp)' ein."
        },
        {
            "type": "SelectVariable",
            "name": "SunriseVariableID",
            "caption": "Variable für Sonnenaufgang (Unix Timestamp)"
        },
        {
            "type": "Label",
            "caption": "Hier stellst du 'Globaler Lux-Sensor für Bewegungsmelder (Optional)' ein."
        },
        {
            "type": "SelectVariable",
            "name": "GlobalLuxSensorID",
            "caption": "Globaler Lux-Sensor für Bewegungsmelder (Optional)"
        }
    ]
}
EOT;
    }

    private function GetProfileMinMax(int $variableId): array
    {
        $min = 0;
        $max = 100;
        
        if (IPS_VariableExists($variableId)) {
            $var = IPS_GetVariable($variableId);
            $profileName = $var['VariableCustomProfile'] != '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];
            
            if ($profileName != '' && IPS_VariableProfileExists($profileName)) {
                $profile = IPS_GetVariableProfile($profileName);
                if ($profile['MaxValue'] > $profile['MinValue']) {
                    $min = $profile['MinValue'];
                    $max = $profile['MaxValue'];
                }
            }
        }
        
        return ['min' => $min, 'max' => $max];
    }
}
