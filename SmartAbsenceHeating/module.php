<?php

class SmartAbsenceHeating extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Target temperature during absence
        $this->RegisterPropertyFloat('TargetTemperature', 17.0);

        // JSON array of thermostat variables: [{"VariableID": 12345}]
        $this->RegisterPropertyString('HeatingVariables', '[]');

        // Internal attribute to save previous states
        $this->RegisterAttributeString('PreviousStates', '{}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    public function SetAbsence(bool $status)
    {
        $heatingVars = json_decode($this->ReadPropertyString('HeatingVariables'), true);
        if (!is_array($heatingVars)) return;

        if ($status) {
            $targetTemp = $this->ReadPropertyFloat('TargetTemperature');
            $previousStates = [];
            foreach ($heatingVars as $heating) {
                $tempId = $heating['VariableID'];
                if ($tempId > 0 && IPS_VariableExists($tempId)) {
                    $previousStates[$tempId] = GetValue($tempId);
                    RequestAction($tempId, $targetTemp);
                }
            }
            $this->WriteAttributeString('PreviousStates', json_encode($previousStates));
            $this->LogMessage("SmartAbsenceHeating: Absenktemperatur aktiviert.", KL_NOTIFY);
        } else {
            $previousStatesStr = $this->ReadAttributeString('PreviousStates');
            $previousStates = json_decode($previousStatesStr, true);
            if (is_array($previousStates)) {
                foreach ($heatingVars as $heating) {
                    $tempId = $heating['VariableID'];
                    if ($tempId > 0 && isset($previousStates[$tempId]) && IPS_VariableExists($tempId)) {
                        RequestAction($tempId, $previousStates[$tempId]);
                    }
                }
            }
            $this->WriteAttributeString('PreviousStates', '{}');
            $this->LogMessage("SmartAbsenceHeating: Normaltemperatur wiederhergestellt.", KL_NOTIFY);
        }
    }
}
