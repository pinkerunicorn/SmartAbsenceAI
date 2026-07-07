<?php

declare(strict_types=1);

class SmartHomeControl extends IPSModuleStrict
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
        
        $this->RegisterPropertyInteger('ShadingInstance', 0);
        $this->RegisterPropertyBoolean('EnableShading', true);
        
        $defaultModes = [
            ['ModeID' => 0, 'ModeName' => 'Anwesenheit', 'Icon' => 'House', 'Color' => -1, 'SequencerInstance' => 0, 'NotifyHeating' => true, 'NotifyLighting' => true, 'NotifySecurity' => true, 'NotifyShading' => true, 'NotifySonos' => true],
            ['ModeID' => 1, 'ModeName' => 'Abwesenheit', 'Icon' => 'Motion', 'Color' => -1, 'SequencerInstance' => 0, 'NotifyHeating' => true, 'NotifyLighting' => true, 'NotifySecurity' => true, 'NotifyShading' => true, 'NotifySonos' => true],
            ['ModeID' => 2, 'ModeName' => 'Urlaub', 'Icon' => 'Suitcase', 'Color' => -1, 'SequencerInstance' => 0, 'NotifyHeating' => true, 'NotifyLighting' => true, 'NotifySecurity' => true, 'NotifyShading' => true, 'NotifySonos' => true]
        ];
        $this->RegisterPropertyString('HouseModes', json_encode($defaultModes));
        
        $this->RegisterPropertyString('SonosInstances', '[]');
        $this->RegisterPropertyBoolean('EnableSonos', true);
        
        $this->RegisterPropertyString('CalendarURL', '');



        $this->RegisterVariableInteger('HouseMode', '🏠 Haus Modus', 'SmartHome.HouseMode', 2);
        $this->EnableAction('HouseMode');
        
        // Google Home / Alexa Interface Variable (Boolean)
        $this->RegisterVariableBoolean('PresenceStatus', 'Anwesenheit (Google Home)', '~Switch', 1);
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

        // Moderne IP-Symcon 8+ Darstellung anwenden
        if (function_exists('IPS_SetVariableCustomPresentation')) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('HouseMode'), [
                'PRESENTATION'   => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            ]);
        }

        // Profil für Haus-Modus dynamisch anlegen/updaten
        if (!IPS_VariableProfileExists('SmartHome.HouseMode')) {
            IPS_CreateVariableProfile('SmartHome.HouseMode', 1);
        }
        
        $modesJson = $this->ReadPropertyString('HouseModes');
        $modes = json_decode($modesJson, true);
        if (!is_array($modes)) {
            $modes = [];
        }
        
        // Zuerst alte Assoziationen löschen
        $profileInfo = IPS_GetVariableProfile('SmartHome.HouseMode');
        foreach ($profileInfo['Associations'] as $ass) {
            IPS_SetVariableProfileAssociation('SmartHome.HouseMode', $ass['Value'], "", "", -1);
        }
        
        // Neue Assoziationen anlegen
        foreach ($modes as $mode) {
            IPS_SetVariableProfileAssociation('SmartHome.HouseMode', $mode['ModeID'], $mode['ModeName'], $mode['Icon'], $mode['Color']);
        }
        
        $this->MaintainVariable('AbsenceStatus', '', 0, '', 0, false);

        // Timer starten (alle 30 Minuten)
        $this->SetTimerInterval('CalendarCheck', 30 * 60 * 1000);

        $this->SetStatus(102);
    }

    public function RequestAction(string $Ident, $Value): void
    {
        if ($Ident == 'HouseMode') {
            // Prüfungen bei Abwesenheit oder Urlaub
            if ($Value == 1 || $Value == 2) {
                // Prüfen auf offene Fenster
                $secInst = $this->ReadPropertyInteger('SecurityInstance');
                if ($secInst > 0 && IPS_InstanceExists($secInst)) {
                    // Hole offene Fenster vom Security-Modul
                    $openItems = [];
                    if (function_exists('SAS_GetOpenWindows')) {
                        $openItems = SAS_GetOpenWindows($secInst);
                    }
                    if (count($openItems) > 0) {
                        $msg = "Warnung: Folgende Fenster/Türen sind offen: " . implode(", ", $openItems) . ". Abwesenheit wird trotzdem aktiviert.";
                        IPS_LogMessage('SmartVillaKunterbunt', $msg);
                        $this->AddLogEvent($msg, '⚠️');
                        
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
            $this->SetValue('PresenceStatus', ($Value != 1 && $Value != 2)); // Alles außer Abwesend/Urlaub gilt für Google als Anwesend
            $this->SetHouseMode($Value);
        }
        
        // Google Home Toggle
        if ($Ident == 'PresenceStatus') {
            $this->SetValue('PresenceStatus', $Value);
            $mode = $Value ? 0 : 1; // true = Anwesend, false = Abwesend
            $this->SetValue('HouseMode', $mode);
            $this->SetHouseMode($mode);
        }
    }

    public function SetHouseMode(int $mode, int $vacationEndTime = 0): void
    {
        $heatingInst = $this->ReadPropertyInteger('HeatingInstance');
        $secInst = $this->ReadPropertyInteger('SecurityInstance');
        $lightInst = $this->ReadPropertyInteger('LightingInstance');
        $shadeInst = $this->ReadPropertyInteger('ShadingInstance');

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
        $notifyShading = $currentModeConfig ? ($currentModeConfig['NotifyShading'] ?? true) : true;
        $notifySonos = $currentModeConfig ? ($currentModeConfig['NotifySonos'] ?? true) : true;
        $sequencerInst = $currentModeConfig ? ($currentModeConfig['SequencerInstance'] ?? 0) : 0;

        $this->AddLogEvent("Modus geändert zu: " . $modeName, '🏠');

        if ($notifyHeating && $this->ReadPropertyBoolean('EnableHeating') && $heatingInst > 0 && IPS_InstanceExists($heatingInst) && function_exists('SHH_SetHouseMode')) {
            SHH_SetHouseMode($heatingInst, $mode, $vacationEndTime);
        }

        if ($notifySecurity && $this->ReadPropertyBoolean('EnableSecurity') && $secInst > 0 && IPS_InstanceExists($secInst) && function_exists('SHS_SetHouseMode')) {
            SHS_SetHouseMode($secInst, $mode);
        }

        if ($notifyLighting && $this->ReadPropertyBoolean('EnableLighting') && $lightInst > 0 && IPS_InstanceExists($lightInst) && function_exists('SHL_SetHouseMode')) {
            SHL_SetHouseMode($lightInst, $mode);
        }
        
        if ($notifyShading && $this->ReadPropertyBoolean('EnableShading') && $shadeInst > 0 && IPS_InstanceExists($shadeInst) && function_exists('SHSH_SetHouseMode')) {
            SHSH_SetHouseMode($shadeInst, $mode);
        }
        
        if ($sequencerInst > 0 && IPS_InstanceExists($sequencerInst) && function_exists('SHSQ_RunSequence')) {
            SHSQ_RunSequence($sequencerInst);
            $this->AddLogEvent("Sequencer ausgelöst.", '⚡');
        }

        // Sonos ansteuern
        if ($notifySonos) {
            $this->ControlSonos($mode);
        }
    }
    
    private function ControlSonos(int $mode): void
    {
        if (!$this->ReadPropertyBoolean('EnableSonos')) return;
        
        $sonosJson = $this->ReadPropertyString('SonosInstances');
        $sonosList = json_decode($sonosJson, true);
        if (!is_array($sonosList)) return;
        
        foreach ($sonosList as $sonos) {
            $instId = $sonos['InstanceID'] ?? 0;
            if ($instId <= 0 || !IPS_InstanceExists($instId)) continue;
            
            if ($mode == 6) { // Putzen = Play
                @SNS_Play($instId);
            } elseif ($mode == 1 || $mode == 2 || $mode == 5) { // Abwesenheit, Urlaub, Schlafen = Pause
                @SNS_Pause($instId);
            }
        }
    }
    
    public function CheckCalendar(): void
    {
        $url = $this->ReadPropertyString('CalendarURL');
        if (empty($url)) {
            $this->AddLogEvent("CheckCalendar: Keine iCal-URL hinterlegt.", 'ℹ️');
            return;
        }
        
        $icalData = @file_get_contents($url);
        if (!$icalData) {
            IPS_LogMessage('SmartVillaKunterbunt', "CheckCalendar: Konnte iCal-Daten nicht abrufen.");
            $this->AddLogEvent("Fehler: Konnte Kalenderdaten nicht abrufen.", '❌');
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
            IPS_LogMessage('SmartVillaKunterbunt', "CheckCalendar: Urlaubstermin gefunden! Wechsle in Modus Urlaub (Ende: " . date('d.m.Y H:i', $vacationEndTime) . ").");
            $this->AddLogEvent("Kalender: Urlaubstermin aktiv! Wechsle in den Urlaubs-Modus (Ende: " . date('d.m. H:i', $vacationEndTime) . ").", '🧳');
            $this->SetValue('HouseMode', 2);
            $this->SetHouseMode(2, $vacationEndTime);
        } elseif (!$vacationFound && $currentMode === 2) {
            IPS_LogMessage('SmartVillaKunterbunt', "CheckCalendar: Urlaubstermin beendet! Wechsle zurück in Modus Anwesenheit.");
            $this->AddLogEvent("Kalender: Urlaubstermin beendet! Wechsle zurück auf Anwesenheit.", '🟢');
            $this->SetValue('HouseMode', 0);
            $this->SetHouseMode(0);
        } elseif (!$vacationFound) {
            $this->AddLogEvent("Kalender geprüft: Aktuell ist kein Urlaub eingetragen.", '📅');
        } else {
            $this->AddLogEvent("Kalender geprüft: Urlaub ist aktiv (Ende: " . date('d.m. H:i', $vacationEndTime) . ").", '📅');
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
