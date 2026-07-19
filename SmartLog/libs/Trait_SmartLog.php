<?php

declare(strict_types=1);

/**
 * SmartLog Trait — Einbinden in jedes Modul für zentrales Logging.
 *
 * Verwendung:
 *   require_once __DIR__ . '/../SmartLog/libs/Trait_SmartLog.php';
 *   class MeinModul extends IPSModuleStrict {
 *       use SmartLog_Trait;
 *       ...
 *       $this->SLog('INFO', 'Etwas ist passiert');
 *   }
 *
 * Der Trait findet die SmartLog-Instanz automatisch per ModuleID.
 * Falls keine SmartLog-Instanz existiert, wird auf IPS_LogMessage() zurückgefallen.
 */
trait SmartLog_Trait
{
    /**
     * Sendet eine Logmeldung an das zentrale SmartLog-Modul.
     *
     * @param string $level   DEBUG, INFO, WARNING, ERROR
     * @param string $message Kurze Logmeldung
     * @param string $details Optionale Details
     */
    private function SLog(string $level, string $message, string $details = ''): void
    {
        // Modulnamen aus dem Klassennamen ableiten
        $source = static::class;

        $slogInstances = @IPS_GetInstanceListByModuleID('{A1B2C3D4-E5F6-7890-ABCD-EF1234567890}');
        if (is_array($slogInstances) && count($slogInstances) > 0) {
            @SLOG_Log($slogInstances[0], $level, $source, $message, $details);
        } else {
            IPS_LogMessage('SmartVillaKunterbunt', $source . ': ' . $message);
        }
    }

    private function SLogDebug(string $message, string $details = ''): void
    {
        $this->SLog('DEBUG', $message, $details);
    }

    private function SLogInfo(string $message, string $details = ''): void
    {
        $this->SLog('INFO', $message, $details);
    }

    private function SLogWarning(string $message, string $details = ''): void
    {
        $this->SLog('WARNING', $message, $details);
    }

    private function SLogError(string $message, string $details = ''): void
    {
        $this->SLog('ERROR', $message, $details);
    }
}
