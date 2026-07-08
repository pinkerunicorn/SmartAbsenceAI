<?php

declare(strict_types=1);

class SmartHomeWatchdog extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('VisuInstanceID', 0);
        $this->RegisterPropertyInteger('TTSInstanceID', 0);
        
        $this->RegisterPropertyString('Rules', '[]');
        
        // Timer for delayed alarms (runs every 1 minute)
        $this->RegisterTimer('DelayTimer', 0, 'SHW_CheckDelays($_IPS[\'TARGET\']);');
        
        // Buffer to keep track of active delays: array of [varID => startTime]
        $this->SetBuffer('ActiveDelays', '{}');
        
        // Last fired buffer so we only fire once per trigger
        $this->SetBuffer('FiredAlarms', '{}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $rulesJson = $this->ReadPropertyString('Rules');
        $rules = json_decode($rulesJson, true);
        if (!is_array($rules)) $rules = [];

        // Unregister all first (for cleanup)
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message == VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        $hasDelays = false;
        foreach ($rules as $rule) {
            if (isset($rule['Active']) && $rule['Active']) {
                $varId = (int)$rule['VariableID'];
                if ($varId > 0 && IPS_VariableExists($varId)) {
                    $this->RegisterMessage($varId, VM_UPDATE);
                    
                    if (isset($rule['DelayMinutes']) && (int)$rule['DelayMinutes'] > 0) {
                        $hasDelays = true;
                    }
                }
            }
        }

        if ($hasDelays) {
            $this->SetTimerInterval('DelayTimer', 60000); // 1 minute
        } else {
            $this->SetTimerInterval('DelayTimer', 0);
        }
        
        $this->SetStatus(102);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message == VM_UPDATE) {
            $value = $Data[0];
            $this->EvaluateVariable($SenderID, $value);
        }
    }
    
    private function EvaluateVariable(int $varID, $currentValue): void
    {
        $rulesJson = $this->ReadPropertyString('Rules');
        $rules = json_decode($rulesJson, true);
        if (!is_array($rules)) return;
        
        $activeDelays = json_decode($this->GetBuffer('ActiveDelays'), true);
        if (!is_array($activeDelays)) $activeDelays = [];
        
        $firedAlarms = json_decode($this->GetBuffer('FiredAlarms'), true);
        if (!is_array($firedAlarms)) $firedAlarms = [];
        
        foreach ($rules as $index => $rule) {
            if (isset($rule['Active']) && $rule['Active'] && $rule['VariableID'] == $varID) {
                $isMet = $this->IsConditionMet($currentValue, $rule['Condition'], $rule['TargetValue']);
                $delay = (int)($rule['DelayMinutes'] ?? 0);
                $retrigger = isset($rule['RetriggerOnUpdate']) && $rule['RetriggerOnUpdate'];
                
                if ($isMet) {
                    // Condition is met!
                    if ($delay > 0) {
                        if (!isset($activeDelays[$varID]) || $retrigger) {
                            // Start delay (or restart if retrigger is enabled)
                            $activeDelays[$varID] = time();
                            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeWatchdog: Bedingung für '{$rule['Name']}' erfüllt. Starte Verzögerung von {$delay} Minuten.");
                        }
                    } else {
                        // Instant trigger
                        if (!isset($firedAlarms[$varID]) || !$firedAlarms[$varID] || $retrigger) {
                            $this->FireAlarm($rule);
                            if (!$retrigger) {
                                $firedAlarms[$varID] = true;
                            }
                        }
                    }
                } else {
                    // Condition no longer met
                    if (isset($activeDelays[$varID])) {
                        unset($activeDelays[$varID]);
                        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeWatchdog: Bedingung für '{$rule['Name']}' nicht mehr erfüllt. Verzögerung abgebrochen.");
                    }
                    if (isset($firedAlarms[$varID])) {
                        unset($firedAlarms[$varID]);
                    }
                }
            }
        }
        
        $this->SetBuffer('ActiveDelays', json_encode($activeDelays));
        $this->SetBuffer('FiredAlarms', json_encode($firedAlarms));
    }
    
    public function CheckDelays(): void
    {
        $rulesJson = $this->ReadPropertyString('Rules');
        $rules = json_decode($rulesJson, true);
        if (!is_array($rules)) return;
        
        $activeDelays = json_decode($this->GetBuffer('ActiveDelays'), true);
        if (!is_array($activeDelays)) return;
        
        $firedAlarms = json_decode($this->GetBuffer('FiredAlarms'), true);
        if (!is_array($firedAlarms)) $firedAlarms = [];
        
        $now = time();
        $changed = false;
        
        foreach ($activeDelays as $varID => $startTime) {
            // Find rule for this VarID
            $matchedRule = null;
            foreach ($rules as $rule) {
                if ($rule['VariableID'] == $varID && isset($rule['Active']) && $rule['Active']) {
                    $matchedRule = $rule;
                    break;
                }
            }
            
            if ($matchedRule) {
                $delaySecs = (int)($matchedRule['DelayMinutes'] ?? 0) * 60;
                if ($now - $startTime >= $delaySecs) {
                    // Delay elapsed!
                    if (!isset($firedAlarms[$varID]) || !$firedAlarms[$varID]) {
                        $this->FireAlarm($matchedRule);
                        $firedAlarms[$varID] = true;
                    }
                    unset($activeDelays[$varID]);
                    $changed = true;
                }
            } else {
                // Rule was deleted or deactivated
                unset($activeDelays[$varID]);
                $changed = true;
            }
        }
        
        if ($changed) {
            $this->SetBuffer('ActiveDelays', json_encode($activeDelays));
            $this->SetBuffer('FiredAlarms', json_encode($firedAlarms));
        }
    }
    
    private function IsConditionMet($currentValue, string $operator, string $targetValue): bool
    {
        // Normalize boolean strings
        if (strtolower($targetValue) === 'true') $targetValue = true;
        if (strtolower($targetValue) === 'false') $targetValue = false;
        
        if (is_bool($currentValue) && is_string($targetValue)) {
            $targetValue = (strtolower($targetValue) === 'true' || $targetValue === '1');
        }
        
        if (is_numeric($currentValue) && is_numeric($targetValue)) {
            $currentValue = (float)$currentValue;
            $targetValue = (float)$targetValue;
        } else if (is_string($currentValue)) {
            $currentValue = strtolower(trim($currentValue));
            if (is_string($targetValue)) {
                $targetValue = strtolower(trim($targetValue));
            }
        }
        
        switch ($operator) {
            case '==': return $currentValue == $targetValue;
            case '!=': return $currentValue != $targetValue;
            case '>':  return $currentValue > $targetValue;
            case '<':  return $currentValue < $targetValue;
        }
        return false;
    }
    
    private function FireAlarm(array $rule): void
    {
        $name = $rule['Name'] ?? 'Unbekannter Alarm';
        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeWatchdog: ALARM AUSGELÖST -> " . $name);
        
        if (isset($rule['EnableVisu']) && $rule['EnableVisu']) {
            $visuId = $this->ReadPropertyInteger('VisuInstanceID');
            if ($visuId > 0 && IPS_InstanceExists($visuId)) {
                if (function_exists('VISU_PostNotification')) {
                    $text = $rule['TTSText'] ?? "Ein Ereignis ist eingetreten.";
                    @VISU_PostNotification($visuId, $name, $text, "Alert", 0);
                }
            }
        }
        
        if (isset($rule['EnableTTS']) && $rule['EnableTTS']) {
            $ttsId = $this->ReadPropertyInteger('TTSInstanceID');
            $ttsText = $rule['TTSText'] ?? "";
            if ($ttsId > 0 && IPS_InstanceExists($ttsId) && trim($ttsText) != "") {
                if (function_exists('GSTTS_PlayMessage')) {
                    @GSTTS_PlayMessage($ttsId, $ttsText);
                }
            }
        }
    }
    
    public function TestNotification(): void
    {
        $rulesJson = $this->ReadPropertyString('Rules');
        $rules = json_decode($rulesJson, true);
        if (is_array($rules) && count($rules) > 0) {
            foreach ($rules as $rule) {
                if (isset($rule['Active']) && $rule['Active']) {
                    $this->FireAlarm($rule);
                    echo "Test-Alarm für '{$rule['Name']}' ausgelöst!\n";
                    return;
                }
            }
        }
        echo "Keine aktive Regel gefunden, die getestet werden könnte.\n";
    }
}
