<?php

declare(strict_types=1);

class SmartHomeHeating extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Target temperature during absence (Fallback)
        $this->RegisterPropertyFloat('TargetTemperature', 17.0);
        $this->RegisterPropertyFloat('FrostWarningThreshold', 5.0);

        // JSON array of thermostat instances: [{"InstanceID": 12345, "TargetTemperature": 17.0}]
        $this->RegisterPropertyString('HeatingInstances', '[]');

        // Internal attribute to save previous states
        $this->RegisterAttributeString('PreviousStates', '{}');

        // GUI Variables
        $this->RegisterVariableString('HeatingStatus', 'ℹ️ Status', '', 1);
        $this->RegisterVariableFloat('AverageTemperature', '🌡️ Ø Haus-Temperatur', '', 2);
        
        $this->RegisterVariableBoolean('HeatingSeason', '❄️ Heizperiode aktiv', '~Switch', 10);
        $this->EnableAction('HeatingSeason');
        
        $this->RegisterVariableBoolean('IsAbsenkbetrieb', '📉 Absenkbetrieb', '', 15);
        $this->RegisterVariableBoolean('AlarmFrostWarning', 'Alarm: Frostgefahr', '~Alert', 20);
        $this->EnableAction('AlarmFrostWarning');

        // Timer for periodic temperature update
        $this->RegisterTimer('UpdateTempTimer', 0, 'SHH_UpdateAverageTemperature($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('HeatingStatus'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Information'
        ]);

        
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('AverageTemperature'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Temperature',
            'SUFFIX'       => ' °C'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('IsAbsenkbetrieb'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'TrendDown'
        ]);

        // Variable Aggregation (Logging) für Ø Haus-Temperatur aktivieren
        $avgTempId = $this->GetIDForIdent('AverageTemperature');
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) > 0) {
            $archiveID = $archiveIDs[0];
            $changed = false;
            if (!AC_GetLoggingStatus($archiveID, $avgTempId)) {
                AC_SetLoggingStatus($archiveID, $avgTempId, true);
                $changed = true;
            }
            if (AC_GetAggregationType($archiveID, $avgTempId) !== 0) { // 0 = Standard (Ø)
                AC_SetAggregationType($archiveID, $avgTempId, 0);
                $changed = true;
            }
            if ($changed) {
                IPS_ApplyChanges($archiveID);
            }
        }

        $this->SetTimerInterval('UpdateTempTimer', 15 * 60 * 1000); // 15 Minuten
        $this->UpdateAverageTemperature();

        $this->SetStatus(102);
    }
    
    public function RequestAction(string $Ident, $Value): void
    {
        if ($Ident === 'HeatingSeason') {
            $this->SetValue($Ident, $Value);
        } elseif ($Ident === 'AlarmFrostWarning') {
            $this->SetValue($Ident, false);
        }
    }

    public function SetHouseMode(int $mode, bool $isAbsence = false, bool $isSleep = false, int $vacationEndTime = 0): void
    {
        $heatingInsts = json_decode($this->ReadPropertyString('HeatingInstances'), true);
        if (!is_array($heatingInsts)) return;
        
        $roomCount = count($heatingInsts);

        // 0=Anwesenheit, 1=Abwesenheit, 2=Urlaub, 3=Party, 4=Heimkino, 5=Schlafen, 6=Putzen
        $isVacation = ($mode == 2);
        $isAbsence = ($isAbsence || $isSleep || $isVacation || $mode == 1 || $mode == 5);
        
        if ($isAbsence || $isVacation) {
            $isHeatingSeason = GetValue($this->GetIDForIdent('HeatingSeason'));
            if (!$isHeatingSeason) {
                $this->SetValue('IsAbsenkbetrieb', false);
                $this->SetValue('HeatingStatus', '☀️ Heizpause (Sommer) - Keine Absenkung');
                IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeHeating: Sommerbetrieb aktiv, Heizkörper werden nicht abgesenkt.");
                return;
            }
            
            $this->SetValue('IsAbsenkbetrieb', true);
            
            $globalTargetTemp = $this->ReadPropertyFloat('TargetTemperature');
            // Bei Urlaub noch weiter absenken (2 Grad kühler als normale Abwesenheit)
            if ($isVacation) {
                $globalTargetTemp = max(12.0, $globalTargetTemp - 2.0); 
            }
            
            $previousStates = [];
            foreach ($heatingInsts as $heating) {
                $instId = $heating['InstanceID'];
                if ($instId <= 0 || !IPS_InstanceExists($instId)) continue;
                
                $individualTemp = isset($heating['TargetTemperature']) ? (float)$heating['TargetTemperature'] : $globalTargetTemp;
                if ($isVacation) {
                    $individualTemp = max(12.0, $individualTemp - 2.0);
                }

                $targetTempId = 0;
                $controlModeId = 0;

                // Variablen unterhalb der Instanz suchen
                foreach (IPS_GetChildrenIDs($instId) as $childId) {
                    $obj = IPS_GetObject($childId);
                    $ident = $obj['ObjectIdent'];
                    $name = $obj['ObjectName'];
                    
                    if (strpos($name, 'Sollwert Temperatur') !== false || $ident === 'SET_POINT_TEMPERATURE' || $ident === 'POINT_TEMPERATURE') {
                        $targetTempId = $childId;
                    }
                    if (strpos($name, 'Kontrollmodus') !== false || strpos($name, 'Control Mode') !== false || $ident === 'CONTROL_MODE' || $ident === 'SET_POINT_MODE') {
                        $controlModeId = $childId;
                    }
                }

                $state = [
                    'tempId' => $targetTempId,
                    'prevTemp' => ($targetTempId > 0 && IPS_VariableExists($targetTempId)) ? GetValue($targetTempId) : null,
                    'modeId' => $controlModeId,
                    'prevMode' => ($controlModeId > 0 && IPS_VariableExists($controlModeId)) ? GetValue($controlModeId) : null
                ];
                $previousStates[$instId] = $state;

                if ($controlModeId > 0 && IPS_VariableExists($controlModeId)) {
                    $currentMode = GetValue($controlModeId);
                    if (is_string($currentMode)) {
                        RequestAction($controlModeId, 'MANUAL');
                    } else {
                        RequestAction($controlModeId, 1); // Meistens 1 = Manu
                    }
                    IPS_Sleep(500); // Kurz warten für Homematic
                }

                if ($targetTempId > 0 && IPS_VariableExists($targetTempId)) {
                    RequestAction($targetTempId, $individualTemp);
                }
            }
            $this->WriteAttributeString('PreviousStates', json_encode($previousStates));
            
            if ($isVacation) {
                $dateStr = ($vacationEndTime > 0) ? " bis " . date('d.m. H:i', $vacationEndTime) : "";
                $this->SetValue('HeatingStatus', '🧳 Urlaub aktiv' . $dateStr . ' (' . $roomCount . ' Räume tief abgesenkt)');
                IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeHeating: Urlaubs-Absenktemperatur aktiviert.");
            } else {
                $this->SetValue('HeatingStatus', '🌙 Abwesenheit aktiv (' . $roomCount . ' Räume manuell abgesenkt)');
                IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeHeating: Absenktemperatur (mit Manu-Modus) aktiviert.");
            }
        } else {
            // Modus 0 (Anwesenheit), 3 (Party), 4 (Heimkino), 6 (Putzen) -> Heizung normal!
            $this->SetValue('IsAbsenkbetrieb', false);
            $isHeatingSeason = GetValue($this->GetIDForIdent('HeatingSeason'));
            if (!$isHeatingSeason) {
                $this->SetValue('HeatingStatus', '☀️ Heizpause (Sommer) - Inaktiv');
                IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeHeating: Sommerbetrieb aktiv, keine Änderungen beim Statuswechsel.");
                return;
            }

            $previousStatesStr = $this->ReadAttributeString('PreviousStates');
            $previousStates = json_decode($previousStatesStr, true);
            if (is_array($previousStates)) {
                foreach ($previousStates as $instId => $state) {
                    $modeId = isset($state['modeId']) ? $state['modeId'] : 0;
                    $prevMode = isset($state['prevMode']) ? $state['prevMode'] : null;
                    $tempId = isset($state['tempId']) ? $state['tempId'] : 0;
                    $prevTemp = isset($state['prevTemp']) ? $state['prevTemp'] : null;

                    if ($modeId > 0 && $prevMode !== null && IPS_VariableExists($modeId)) {
                        RequestAction($modeId, $prevMode);
                    } elseif ($tempId > 0 && $prevTemp !== null && IPS_VariableExists($tempId)) {
                        RequestAction($tempId, $prevTemp);
                    }
                }
            }
            $this->WriteAttributeString('PreviousStates', '{}');
            $this->SetValue('HeatingStatus', '🟢 Normalbetrieb (Profil gesteuert)');
            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeHeating: Normaltemperatur / Auto-Modus wiederhergestellt.");
        }
        $this->UpdateAverageTemperature();
    }

    public function UpdateAverageTemperature(): void
    {
        $heatingInsts = json_decode($this->ReadPropertyString('HeatingInstances'), true);
        if (!is_array($heatingInsts) || count($heatingInsts) == 0) return;

        $sumTemp = 0.0;
        $count = 0;

        foreach ($heatingInsts as $heating) {
            $instId = $heating['InstanceID'];
            if ($instId <= 0 || !IPS_InstanceExists($instId)) continue;

            $actualTemp = 0.0;
            $fallbackTemp = 0.0;

            foreach (IPS_GetChildrenIDs($instId) as $childId) {
                $obj = IPS_GetObject($childId);
                $ident = $obj['ObjectIdent'];
                $name = $obj['ObjectName'];

                if (strpos($name, 'Aktuelle Temperatur') !== false || $ident === 'ACTUAL_TEMPERATURE') {
                    $val = (float)GetValue($childId);
                    if ($val > 0) $actualTemp = $val;
                }
                if (strpos($name, 'Ventil-Ist-Temperatur') !== false || $ident === 'VALVE_ACTUAL_TEMPERATURE') {
                    $val = (float)GetValue($childId);
                    if ($val > 0) $fallbackTemp = $val;
                }
            }

            if ($actualTemp > 0) {
                $sumTemp += $actualTemp;
                $count++;
            } elseif ($fallbackTemp > 0) {
                $sumTemp += $fallbackTemp;
                $count++;
            }
        }

        if ($count > 0) {
            $avg = round($sumTemp / $count, 1);
            $this->SetValueIfChanged('AverageTemperature', $avg);
            
            $frostThreshold = $this->ReadPropertyFloat('FrostWarningThreshold');
            if ($avg < $frostThreshold) {
                if (!$this->GetValue('AlarmFrostWarning')) {
                    $this->SetValue('AlarmFrostWarning', true);
                    IPS_LogMessage('SmartHomeHeating', "Frostgefahr erkannt! Ø-Temperatur ist $avg °C");
                }
            } else {
                if ($this->GetValue('AlarmFrostWarning')) {
                    $this->SetValue('AlarmFrostWarning', false);
                }
            }
        }
    }
    
    private function SetValueIfChanged(string $Ident, $Value): void
    {
        $id = $this->GetIDForIdent($Ident);
        if (GetValue($id) !== $Value) {
            $this->SetValue($Ident, $Value);
        }
    }
}
