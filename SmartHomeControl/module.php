<?php

declare(strict_types=1);

class SmartHomeControl extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Instance links
        $this->RegisterPropertyInteger('HeatingInstance', 0);
        $this->RegisterPropertyBoolean('EnableHeating', true);

        $this->RegisterPropertyInteger('SecurityInstance', 0);
        $this->RegisterPropertyBoolean('EnableSecurity', true);

        $this->RegisterPropertyInteger('LightingInstance', 0);
        $this->RegisterPropertyBoolean('EnableLighting', true);
        
        $this->RegisterPropertyInteger('ActiveLightingInstance', 0);
        $this->RegisterPropertyBoolean('EnableActiveLighting', true);
        
        $this->RegisterPropertyInteger('ShadingInstance', 0);
        $this->RegisterPropertyBoolean('EnableShading', true);
        
        $this->RegisterPropertyInteger('LawnInstance', 0);
        $this->RegisterPropertyBoolean('EnableLawn', true);
        
        $this->RegisterPropertyString('GarageInstances', '[]');
        $this->RegisterPropertyBoolean('EnableGarage', true);
        
        $defaultModes = [
            ['ModeID' => 0, 'ModeName' => 'Anwesenheit', 'Icon' => 'House', 'Color' => -1, 'SequencerInstance' => 0, 'NotifyHeating' => true, 'NotifyLighting' => true, 'NotifyActiveLighting' => true, 'NotifySecurity' => true, 'NotifyShading' => true, 'NotifyLawn' => true, 'NotifyGarage' => true],
            ['ModeID' => 1, 'ModeName' => 'Abwesenheit', 'Icon' => 'Motion', 'Color' => -1, 'SequencerInstance' => 0, 'NotifyHeating' => true, 'NotifyLighting' => true, 'NotifyActiveLighting' => true, 'NotifySecurity' => true, 'NotifyShading' => true, 'NotifyLawn' => true, 'NotifyGarage' => true],
            ['ModeID' => 2, 'ModeName' => 'Urlaub', 'Icon' => 'Suitcase', 'Color' => -1, 'SequencerInstance' => 0, 'NotifyHeating' => true, 'NotifyLighting' => true, 'NotifyActiveLighting' => true, 'NotifySecurity' => true, 'NotifyShading' => true, 'NotifyLawn' => true, 'NotifyGarage' => true]
        ];
        $this->RegisterPropertyString('HouseModes', json_encode($defaultModes));
        
        $this->RegisterPropertyString('CalendarURL', '');
        
        if (!IPS_VariableProfileExists('SmartHome.HouseMode')) {
            IPS_CreateVariableProfile('SmartHome.HouseMode', 1);
        }
        $profileInfo = IPS_GetVariableProfile('SmartHome.HouseMode');
        foreach ($profileInfo['Associations'] as $ass) {
            IPS_SetVariableProfileAssociation('SmartHome.HouseMode', $ass['Value'], "", "", -1);
        }



        $this->RegisterVariableInteger('HouseMode', '🏠 Haus Modus', 'SmartHome.HouseMode', 2);
        $this->EnableAction('HouseMode');
        
        // Google Home / Alexa Interface Variable (Boolean)
        $this->RegisterVariableBoolean('PresenceStatus', 'Anwesenheit (Google Home)', '', 1);
        $this->EnableAction('PresenceStatus');
        
        // Timer für Kalender-Check
        $this->RegisterTimer('CalendarCheck', 0, 'SHC_CheckCalendar($_IPS[\'TARGET\']);');
        
        // Attribut für Log-Daten
        $this->RegisterAttributeString('LogData', '[]');
        
        // HTML Log Variable
        $this->RegisterVariableString('ControllerLog', '📝 System Log', '', 2);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $modesJson = $this->ReadPropertyString('HouseModes');
        $modes = json_decode($modesJson, true);
        if (!is_array($modes)) {
            $modes = [];
        }
        foreach ($modes as $mode) {
            IPS_SetVariableProfileAssociation('SmartHome.HouseMode', $mode['ModeID'], $mode['ModeName'], $mode['Icon'], $mode['Color']);
        }

        // Moderne IP-Symcon 8+ Darstellung anwenden
        if (function_exists('IPS_SetVariableCustomPresentation')) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('HouseMode'), [
                'PRESENTATION'   => VARIABLE_PRESENTATION_VALUE_PRESENTATION
            ]);
            
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('PresenceStatus'), [
                'PRESENTATION'   => VARIABLE_PRESENTATION_SWITCH
            ]);
        }
        
        $this->MaintainVariable('AbsenceStatus', '', 0, '', 0, false);

        // Timer starten (alle 30 Minuten)
        $this->SetTimerInterval('CalendarCheck', 30 * 60 * 1000);

        $this->SetStatus(102);
    }

    public function RequestAction(string $Ident, $Value): void
    {
        if ($Ident == 'HouseMode') {
            $this->SetHouseMode($Value);
        }
        
        // Google Home Toggle
        if ($Ident == 'PresenceStatus') {
            $mode = $Value ? 0 : 1; // true = Anwesenheit, false = Abwesenheit
            $this->SetHouseMode($mode);
        }
    }

    public function SetHouseMode(int $newMode, int $vacationEndTime = 0): void
    {
        $oldMode = $this->GetValue('HouseMode');
        
        if ($oldMode != $newMode) {
            $this->TriggerDeactivationSequence($oldMode);
        }
        
        $this->SetValue('HouseMode', $newMode);
        $this->SetValue('PresenceStatus', ($newMode != 1 && $newMode != 2));
        
        $this->ApplyHouseModeState($newMode, $vacationEndTime);
    }
    
    private function TriggerDeactivationSequence(int $mode): void
    {
        $modesJson = $this->ReadPropertyString('HouseModes');
        $modes = json_decode($modesJson, true);
        if (is_array($modes)) {
            foreach ($modes as $m) {
                if ($m['ModeID'] == $mode) {
                    $seqInst = $m['SequencerInstance'] ?? 0;
                    if ($seqInst > 0 && IPS_InstanceExists($seqInst) && function_exists('SHSQ_RunDeactivationSequence')) {
                        SHSQ_RunDeactivationSequence($seqInst);
                        $this->AddLogEvent("Austritts-Sequenz für Modus '" . $m['ModeName'] . "' ausgeführt.", '⏪');
                    }
                    break;
                }
            }
        }
    }

    private function ApplyHouseModeState(int $mode, int $vacationEndTime = 0): void
    {
        $heatingInst = $this->ReadPropertyInteger('HeatingInstance');
        $secInst = $this->ReadPropertyInteger('SecurityInstance');
        $lightInst = $this->ReadPropertyInteger('LightingInstance');
        $activeLightInst = $this->ReadPropertyInteger('ActiveLightingInstance');
        $shadeInst = $this->ReadPropertyInteger('ShadingInstance');
        $lawnInst = $this->ReadPropertyInteger('LawnInstance');
        $garageInstsJson = $this->ReadPropertyString('GarageInstances');

        $modesJson = $this->ReadPropertyString('HouseModes');
        $modes = json_decode($modesJson, true);
        
        $currentModeConfig = null;
        if (is_array($modes)) {
            foreach ($modes as $m) {
                if ($m['ModeID'] == $mode) {
                    $currentModeConfig = $m;
                    break;
                }
            }
        }
        
        $modeName = $currentModeConfig ? $currentModeConfig['ModeName'] : "Unbekannt ($mode)";
        IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeControl: Haus-Modus gewechselt auf " . $modeName);

        // Standard-Matrix (falls nichts konfiguriert ist, alles ausführen)
        $notifyHeating = $currentModeConfig ? ($currentModeConfig['NotifyHeating'] ?? true) : true;
        $notifySecurity = $currentModeConfig ? ($currentModeConfig['NotifySecurity'] ?? true) : true;
        $notifyLighting = $currentModeConfig ? ($currentModeConfig['NotifyLighting'] ?? true) : true;
        $notifyActiveLighting = $currentModeConfig ? ($currentModeConfig['NotifyActiveLighting'] ?? true) : true;
        $notifyShading = $currentModeConfig ? ($currentModeConfig['NotifyShading'] ?? true) : true;
        $notifyLawn = $currentModeConfig ? ($currentModeConfig['NotifyLawn'] ?? true) : true;
        $notifyGarage = $currentModeConfig ? ($currentModeConfig['NotifyGarage'] ?? true) : true;
        $sequencerInst = $currentModeConfig ? ($currentModeConfig['SequencerInstance'] ?? 0) : 0;

        $this->AddLogEvent("Modus gewechselt auf: " . $modeName, '🏠');

        if ($notifyHeating && $this->ReadPropertyBoolean('EnableHeating') && $heatingInst > 0 && IPS_InstanceExists($heatingInst) && function_exists('SHH_SetHouseMode')) {
            SHH_SetHouseMode($heatingInst, $mode, $vacationEndTime);
        }

        if ($notifySecurity && $this->ReadPropertyBoolean('EnableSecurity') && $secInst > 0 && IPS_InstanceExists($secInst) && function_exists('SHS_SetHouseMode')) {
            SHS_SetHouseMode($secInst, $mode);
        }

        if ($notifyLighting && $this->ReadPropertyBoolean('EnableLighting') && $lightInst > 0 && IPS_InstanceExists($lightInst) && function_exists('SHL_SetHouseMode')) {
            SHL_SetHouseMode($lightInst, $mode);
        }
        
        if ($notifyActiveLighting && $this->ReadPropertyBoolean('EnableActiveLighting') && $activeLightInst > 0 && IPS_InstanceExists($activeLightInst) && function_exists('SAL_SetHouseMode')) {
            SAL_SetHouseMode($activeLightInst, $mode);
        }
        
        if ($notifyShading && $this->ReadPropertyBoolean('EnableShading') && $shadeInst > 0 && IPS_InstanceExists($shadeInst) && function_exists('SHSH_SetHouseMode')) {
            SHSH_SetHouseMode($shadeInst, $mode);
        }
        
        if ($notifyLawn && $this->ReadPropertyBoolean('EnableLawn') && $lawnInst > 0 && IPS_InstanceExists($lawnInst) && function_exists('SLAI_SetHouseMode')) {
            SLAI_SetHouseMode($lawnInst, $mode);
        }
        
        if ($notifyGarage && $this->ReadPropertyBoolean('EnableGarage')) {
            $garageInsts = json_decode($garageInstsJson, true);
            if (is_array($garageInsts)) {
                foreach ($garageInsts as $garage) {
                    if (isset($garage['InstanceID'])) {
                        $gId = $garage['InstanceID'];
                        if ($gId > 0 && IPS_InstanceExists($gId) && function_exists('SHG_SetHouseMode')) {
                            SHG_SetHouseMode($gId, $mode);
                        }
                    }
                }
            }
        }
        
        if ($sequencerInst > 0 && IPS_InstanceExists($sequencerInst) && function_exists('SHSQ_RunSequence')) {
            SHSQ_RunSequence($sequencerInst);
            $this->AddLogEvent("Eintritts-Sequenz ausgeführt.", '▶️');
        }

    }
    
    public function CheckCalendar(): void
    {
        $url = $this->ReadPropertyString('CalendarURL');
        if (empty($url)) {
            $this->AddLogEvent("SmartHomeControl: CheckCalendar - Keine iCal-URL hinterlegt.", 'ℹ️');
            return;
        }
        
        $icalData = @file_get_contents($url);
        if (!$icalData) {
            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeControl: CheckCalendar - Konnte iCal-Daten nicht abrufen.");
            $this->AddLogEvent("SmartHomeControl: Fehler: Konnte Kalenderdaten nicht abrufen.", '❌');
            return;
        }
        
        // Sehr simpler iCal Parser für VEVENT
        $events = [];
        $lines = explode("\n", $icalData);
        $currentEvent = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === 'BEGIN:VEVENT') {
                $currentEvent = [];
            } elseif ($line === 'END:VEVENT') {
                if ($currentEvent !== null) {
                    $events[] = $currentEvent;
                    $currentEvent = null;
                }
            } elseif ($currentEvent !== null) {
                if (strpos($line, 'SUMMARY:') === 0) {
                    $currentEvent['SUMMARY'] = substr($line, 8);
                } elseif (strpos($line, 'DTSTART') === 0) {
                    $parts = explode(':', $line);
                    if (count($parts) >= 2) {
                        $currentEvent['DTSTART'] = strtotime($parts[1]);
                    }
                } elseif (strpos($line, 'DTEND') === 0) {
                    $parts = explode(':', $line);
                    if (count($parts) >= 2) {
                        $currentEvent['DTEND'] = strtotime($parts[1]);
                    }
                }
            }
        }
        
        $now = time();
        $vacationFound = false;
        $vacationEndTime = 0;
        
        foreach ($events as $event) {
            if (isset($event['SUMMARY']) && strtoupper(trim($event['SUMMARY'])) === 'URLAUB') {
                if (isset($event['DTSTART']) && isset($event['DTEND'])) {
                    if ($now >= $event['DTSTART'] && $now <= $event['DTEND']) {
                        $vacationFound = true;
                        $vacationEndTime = $event['DTEND'];
                        break;
                    }
                }
            }
        }
        
        $currentMode = GetValue($this->GetIDForIdent('HouseMode'));
        
        if ($vacationFound && $currentMode !== 2) {
            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeControl: CheckCalendar - Urlaubstermin gefunden! Wechsle in Modus Urlaub (Ende: " . date('d.m.Y H:i', $vacationEndTime) . ").");
            $this->AddLogEvent("SmartHomeControl: Kalender: Urlaubstermin aktiv! Wechsle in den Urlaubs-Modus (Ende: " . date('d.m. H:i', $vacationEndTime) . ").", '🧳');
            $this->SetHouseMode(2, $vacationEndTime);
        } elseif (!$vacationFound && $currentMode === 2) {
            IPS_LogMessage('SmartVillaKunterbunt', "SmartHomeControl: CheckCalendar - Urlaubstermin beendet! Wechsle zurück in Modus Anwesenheit.");
            $this->AddLogEvent("SmartHomeControl: Kalender: Urlaubstermin beendet! Wechsle zurück auf Anwesenheit.", '🟢');
            $this->SetHouseMode(0);
        } elseif (!$vacationFound) {
            $this->AddLogEvent("SmartHomeControl: Kalender geprüft: Aktuell ist kein Urlaub eingetragen.", '📅');
        } else {
            $this->AddLogEvent("SmartHomeControl: Kalender geprüft: Urlaub ist aktiv (Ende: " . date('d.m. H:i', $vacationEndTime) . ").", '📅');
        }
    }

    private function AddLogEvent(string $message, string $icon = 'ℹ️'): void
    {
        $logJson = $this->ReadAttributeString('LogData');
        $log = json_decode($logJson, true);
        if (!is_array($log)) $log = [];
        
        array_unshift($log, [
            'time' => time(),
            'msg' => $message,
            'icon' => $icon
        ]);
        
        if (count($log) > 50) {
            $log = array_slice($log, 0, 50);
        }
        
        $this->WriteAttributeString('LogData', json_encode($log));
        $this->RenderLog($log);
    }

    private function RenderLog(array $log): void
    {
        if (count($log) === 0) {
            $this->SetValue('ControllerLog', '<div style="padding: 10px; color: #888;">Noch keine Ereignisse protokolliert.</div>');
            return;
        }

        $html = '<div style="display: flex; flex-direction: column; gap: 8px; padding: 5px;">';
        foreach ($log as $entry) {
            $timeStr = date('d.m.Y H:i:s', $entry['time']);
            $msg = htmlspecialchars($entry['msg']);
            $icon = $entry['icon'];

            $html .= '<div style="background: rgba(255,255,255,0.05); border-left: 3px solid #00a8ff; padding: 8px 12px; border-radius: 4px;">';
            $html .= '<div style="font-size: 0.8em; color: #aaa; margin-bottom: 3px;">' . $timeStr . '</div>';
            $html .= '<div style="display: flex; align-items: center; gap: 8px;">';
            $html .= '<span style="font-size: 1.2em;">' . $icon . '</span>';
            $html .= '<span>' . $msg . '</span>';
            $html .= '</div></div>';
        }
        $html .= '</div>';

        $this->SetValue('ControllerLog', $html);
    }
}
