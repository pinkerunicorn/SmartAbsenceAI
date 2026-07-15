<?php

declare(strict_types=1);

class SmartActiveLighting extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyString('MotionRules', '[]');
        $this->RegisterPropertyString('TwilightRules', '[]');
        $this->RegisterPropertyString('SceneRules', '[]');
        $this->RegisterPropertyInteger('GlobalLuxSensorID', 0);
        $this->RegisterPropertyInteger('SunsetVariableID', 0);
        $this->RegisterPropertyInteger('SunriseVariableID', 0);

        // Attributes
        $this->RegisterAttributeString('ActiveTimers', '[]');

        // Timer for daily recalculation of sunset/sunrise
        $this->RegisterTimer('DailyTwilightRecalc', 0, 'SAL_CalculateTwilightTimers($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // --- Auto-generated References ---
        $ref_GlobalLuxSensorID = $this->ReadPropertyInteger('GlobalLuxSensorID');
        if ($ref_GlobalLuxSensorID > 1 && @IPS_ObjectExists($ref_GlobalLuxSensorID)) {
            $this->RegisterReference($ref_GlobalLuxSensorID);
        }
        $ref_SunsetVariableID = $this->ReadPropertyInteger('SunsetVariableID');
        if ($ref_SunsetVariableID > 1 && @IPS_ObjectExists($ref_SunsetVariableID)) {
            $this->RegisterReference($ref_SunsetVariableID);
        }
        $ref_SunriseVariableID = $this->ReadPropertyInteger('SunriseVariableID');
        if ($ref_SunriseVariableID > 1 && @IPS_ObjectExists($ref_SunriseVariableID)) {
            $this->RegisterReference($ref_SunriseVariableID);
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

        // Register Scene Triggers
        $sceneRules = json_decode($this->ReadPropertyString('SceneRules'), true);
        if (is_array($sceneRules)) {
            foreach ($sceneRules as $rule) {
                if (isset($rule['SceneVariableID']) && $rule['SceneVariableID'] > 0) {
                    $this->RegisterMessage($rule['SceneVariableID'], VM_UPDATE);
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

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message == VM_UPDATE) {
            $val = $Data[0]; // New value
            $isTrigger = false;
            if (is_bool($val)) {
                $isTrigger = $val;
            } elseif (is_int($val) || is_float($val)) {
                $isTrigger = ($val > 0);
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

            // Check if Sender is a Scene Trigger
            $sceneRules = json_decode($this->ReadPropertyString('SceneRules'), true);
            if (is_array($sceneRules)) {
                foreach ($sceneRules as $rule) {
                    if (isset($rule['SceneVariableID']) && $rule['SceneVariableID'] == $SenderID && $isTrigger) {
                        $this->ProcessSceneTrigger($rule);
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
            RequestAction($targetId, true);
        } else {
            // Check if target is a boolean or integer/float (dimmer)
            $var = IPS_GetVariable($targetId);
            if ($var['VariableType'] == 0) { // Boolean
                RequestAction($targetId, true);
            } else {
                RequestAction($targetId, $targetValue);
            }
        }

        // Set Off-Delay Timer
        $duration = $rule['DurationSec'] ?? 120;
        $timerName = 'MotionOffTimer_'. $ruleIndex;
        $this->RegisterTimer($timerName, $duration * 1000, 'SAL_ProcessMotionOff($_IPS[\'TARGET\'], '. $ruleIndex . ');');
        
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
                    RequestAction($targetId, false);
                } else {
                    RequestAction($targetId, 0);
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

        RequestAction($targetId, $targetVal);
    }

    public function CalculateTwilightTimers(): void
    {
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
                $this->RegisterTimer($timerName, $diffMs, 'SAL_ProcessTwilightTrigger($_IPS[\'TARGET\'], '. $index . ');');
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
            $targetId = $rules[$ruleIndex]['TargetLightID'] ?? 0;
            $actionVal = $rules[$ruleIndex]['ActionValue'] ?? 1; // 1=On, 0=Off

            if ($targetId > 0 && IPS_VariableExists($targetId)) {
                $var = IPS_GetVariable($targetId);
                if ($var['VariableType'] == 0) {
                    RequestAction($targetId, ($actionVal == 1));
                } else {
                    RequestAction($targetId, ($actionVal == 1) ? 100 : 0);
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
                            RequestAction($targetId, false);
                        } else {
                            RequestAction($targetId, 0);
                        }
                    }
                }
            }
            $this->WriteAttributeString('ActiveTimers', '[]');
            IPS_LogMessage('SmartActiveLighting', 'Haus-Modus hat gewechselt. Schalte aktive Bewegungslichter aus.');
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
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
            "caption": "Abhängigkeiten"
        },
        {
            "type": "SelectVariable",
            "name": "SunsetVariableID",
            "caption": "Variable für Sonnenuntergang (Unix Timestamp)"
        },
        {
            "type": "SelectVariable",
            "name": "SunriseVariableID",
            "caption": "Variable für Sonnenaufgang (Unix Timestamp)"
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
}


