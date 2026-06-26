<?php

declare(strict_types=1);

class SmartAbsenceHeating extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Target temperature during absence (Fallback)
        $this->RegisterPropertyFloat('TargetTemperature', 17.0);

        // JSON array of thermostat instances: [{"InstanceID": 12345, "TargetTemperature": 17.0}]
        $this->RegisterPropertyString('HeatingInstances', '[]');

        // Internal attribute to save previous states
        $this->RegisterAttributeString('PreviousStates', '{}');

        // GUI Variables
        $this->RegisterVariableString('HeatingStatus', 'Status', '', 1);
        $this->RegisterVariableFloat('AverageTemperature', 'Ø Haus-Temperatur', '', 2);

        // Timer for periodic temperature update
        $this->RegisterTimer('UpdateTempTimer', 0, 'SAH_UpdateAverageTemperature($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('HeatingStatus'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Information'
        ]);

        if (function_exists('IPS_SetVariableCustomPresentation')) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('AverageTemperature'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Temperature',
                'SUFFIX'       => ' °C'
            ]);
        }

        $this->SetTimerInterval('UpdateTempTimer', 15 * 60 * 1000); // 15 Minuten
        $this->UpdateAverageTemperature();

        $this->SetStatus(102);
    }

    public function SetAbsence(bool $status): void
    {
        $heatingInsts = json_decode($this->ReadPropertyString('HeatingInstances'), true);
        if (!is_array($heatingInsts)) return;
        
        $roomCount = count($heatingInsts);

        if ($status) {
            $globalTargetTemp = $this->ReadPropertyFloat('TargetTemperature');
            $previousStates = [];
            foreach ($heatingInsts as $heating) {
                $instId = $heating['InstanceID'];
                if ($instId <= 0 || !IPS_InstanceExists($instId)) continue;
                
                $individualTemp = isset($heating['TargetTemperature']) ? (float)$heating['TargetTemperature'] : $globalTargetTemp;

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
            $this->SetValue('HeatingStatus', '🌙 Abwesenheit aktiv (' . $roomCount . ' Räume manuell abgesenkt)');
            $this->LogMessage("SmartAbsenceHeating: Absenktemperatur (mit Manu-Modus) aktiviert.", KL_NOTIFY);
        } else {
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
            $this->LogMessage("SmartAbsenceHeating: Normaltemperatur / Auto-Modus wiederhergestellt.", KL_NOTIFY);
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
            $this->SetValue('AverageTemperature', $avg);
        }
    }
}
