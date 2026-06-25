<?php

declare(strict_types=1);

class SmartAbsenceController extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('WebFrontInstance', 0);
        $this->RegisterPropertyBoolean('PushNotifyWindows', true);
        $this->RegisterPropertyBoolean('PushNotifyLights', true);

        // Instance links
        $this->RegisterPropertyInteger('HeatingInstance', 0);
        $this->RegisterPropertyBoolean('EnableHeating', true);

        $this->RegisterPropertyInteger('SecurityInstance', 0);
        $this->RegisterPropertyBoolean('EnableSecurity', true);

        $this->RegisterPropertyInteger('LightingInstance', 0);
        $this->RegisterPropertyBoolean('EnableLighting', true);

        // Status Variable (Schalter für Abwesenheit)
        $this->RegisterVariableBoolean('AbsenceStatus', 'Abwesenheitsmodus', '', 1);
        $this->EnableAction('AbsenceStatus');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Moderne IP-Symcon 8+ Darstellung anwenden
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('AbsenceStatus'), [
            'PRESENTATION'   => VARIABLE_PRESENTATION_SWITCH,
            'ICON'           => 'power-off',
            'GLOW_COLOR'     => 16776960, // 0xFFFF00 (Gelb)
            'GLOW_INTENSITY' => 50
        ]);

        $this->SetStatus(102);
    }

    public function RequestAction(string $Ident, $Value): void
    {
        if ($Ident == 'AbsenceStatus') {
            if ($Value == true) {
                // Prüfen auf offene Fenster
                $secInst = $this->ReadPropertyInteger('SecurityInstance');
                if ($secInst > 0 && IPS_InstanceExists($secInst)) {
                    // Hole offene Fenster vom Security-Modul
                    $openItems = [];
                    if (function_exists('SAS_GetOpenWindows')) {
                        $openItems = SAS_GetOpenWindows($secInst);
                    }
                    if (count($openItems) > 0) {
                        $msg = "Warnung: Folgende Fenster/Türen sind offen: " . implode(", ", $openItems) . ". Abwesenheit wird trotzdem vollständig aktiviert.";
                        $this->LogMessage($msg, KL_WARNING);
                        
                        $wfc = $this->ReadPropertyInteger('WebFrontInstance');
                        if ($wfc > 0 && IPS_InstanceExists($wfc) && $this->ReadPropertyBoolean('PushNotifyWindows')) {
                            if (function_exists('VISU_PostNotification')) {
                                @VISU_PostNotification($wfc, "Achtung beim Verlassen", "Offen: " . implode(", ", $openItems), "Warning", 0);
                            }
                            if (function_exists('WFC_PushNotification')) {
                                @WFC_PushNotification($wfc, "Achtung beim Verlassen", "Offen: " . implode(", ", $openItems), "", 0);
                            }
                        }
                    }
                }
                
                // Prüfen auf aktive Lampen
                $lightInst = $this->ReadPropertyInteger('LightingInstance');
                if ($lightInst > 0 && IPS_InstanceExists($lightInst)) {
                    $activeLights = [];
                    if (function_exists('SAL_GetActiveLights')) {
                        $activeLights = SAL_GetActiveLights($lightInst);
                    }
                    if (count($activeLights) > 0) {
                        $wfc = $this->ReadPropertyInteger('WebFrontInstance');
                        if ($wfc > 0 && IPS_InstanceExists($wfc) && $this->ReadPropertyBoolean('PushNotifyLights')) {
                            if (function_exists('VISU_PostNotification')) {
                                @VISU_PostNotification($wfc, "Achtung beim Verlassen", "Noch an: " . implode(", ", $activeLights), "Light", 0);
                            }
                            if (function_exists('WFC_PushNotification')) {
                                @WFC_PushNotification($wfc, "Achtung beim Verlassen", "Noch an: " . implode(", ", $activeLights), "", 0);
                            }
                        }
                    }
                }
            }

            $this->SetValue($Ident, $Value);
            $this->SetAbsence($Value);
        }
    }

    public function SetAbsence(bool $status): void
    {
        $heatingInst = $this->ReadPropertyInteger('HeatingInstance');
        $secInst = $this->ReadPropertyInteger('SecurityInstance');
        $lightInst = $this->ReadPropertyInteger('LightingInstance');

        if ($status) {
            $this->LogMessage("SmartAbsenceController: Abwesenheitsmodus AKTIVIERT.", KL_NOTIFY);
            
            if ($this->ReadPropertyBoolean('EnableHeating') && $heatingInst > 0 && IPS_InstanceExists($heatingInst) && function_exists('SAH_SetAbsence')) {
                SAH_SetAbsence($heatingInst, true);
            }
            if ($this->ReadPropertyBoolean('EnableSecurity') && $secInst > 0 && IPS_InstanceExists($secInst) && function_exists('SAS_SetAbsence')) {
                SAS_SetAbsence($secInst, true);
            }
            if ($this->ReadPropertyBoolean('EnableLighting') && $lightInst > 0 && IPS_InstanceExists($lightInst) && function_exists('SAL_SetAbsence')) {
                SAL_SetAbsence($lightInst, true);
            }
        } else {
            $this->LogMessage("SmartAbsenceController: Abwesenheitsmodus DEAKTIVIERT.", KL_NOTIFY);

            if ($this->ReadPropertyBoolean('EnableHeating') && $heatingInst > 0 && IPS_InstanceExists($heatingInst) && function_exists('SAH_SetAbsence')) {
                SAH_SetAbsence($heatingInst, false);
            }
            if ($this->ReadPropertyBoolean('EnableLighting') && $lightInst > 0 && IPS_InstanceExists($lightInst) && function_exists('SAL_SetAbsence')) {
                SAL_SetAbsence($lightInst, false);
            }
            if ($this->ReadPropertyBoolean('EnableSecurity') && $secInst > 0 && IPS_InstanceExists($secInst) && function_exists('SAS_SetAbsence')) {
                SAS_SetAbsence($secInst, false);
            }
        }
    }
}
