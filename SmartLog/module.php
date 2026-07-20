<?php

declare(strict_types=1);

/**
 * SmartLog — Zentrales Logging-Modul für alle IP-Symcon Module.
 *
 * Sammelt Logmeldungen aller Module in einem JSON-Ringbuffer und
 * stellt sie in der Tile View (Kachelansicht) als filterbares Event-Log dar.
 *
 * Verwendung durch andere Module:
 *   $logId = IPS_GetInstanceListByModuleID('{A1B2C3D4-E5F6-7890-ABCD-EF1234567890}')[0];
 *   SLOG_Info($logId, 'MeinModul', 'Etwas ist passiert', 'Optionale Details');
 */
class SmartLog extends IPSModuleStrict
{
    private const ATTR_LOG_DATA = 'LogData';
    private const ATTR_STATUS = 'VisualisierungsStatus';

    private const VALID_LEVELS = ['DEBUG', 'INFO', 'WARNING', 'ERROR'];

    private const LEVEL_COLORS = [
        'DEBUG'   => '#6B7280',
        'INFO'    => '#3B82F6',
        'WARNING' => '#F59E0B',
        'ERROR'   => '#EF4444',
    ];

    private const LEVEL_ICONS = [
        'DEBUG'   => '🔍',
        'INFO'    => 'ℹ️',
        'WARNING' => '⚠️',
        'ERROR'   => '❌',
    ];

    public function Create(): void
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger('MaxEntries', 200);
        $this->RegisterPropertyInteger('AutoRefreshSekunden', 10);
        $this->RegisterPropertyBoolean('MirrorToSyslog', false);

        // Attribute (persistenter Speicher)
        $this->RegisterAttributeString(self::ATTR_LOG_DATA, '[]');
        try {
            $this->RegisterAttributeString(self::ATTR_STATUS, json_encode([
                'seite'       => 0,
                'maxZeilen'   => 30,
                'levelFilter' => [],
                'textFilter'  => '',
                'sourceFilter' => [],
            ], JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            IPS_LogMessage('SmartLog', 'JSON-Fehler: ' . $e->getMessage());
        }

        // Variablen
        $this->RegisterVariableInteger('EntryCount', 'Log-Einträge', '', 1);
        IPS_SetIcon($this->GetIDForIdent('EntryCount'), 'Information');

        $this->RegisterVariableString('LastEntry', 'Letzter Eintrag', '', 2);
        IPS_SetIcon($this->GetIDForIdent('LastEntry'), 'Information');

        // Timer für Auto-Refresh
        $this->RegisterTimer('VisualisierungAktualisieren', 0, 'SLOG_AktualisierenVisualisierung($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Tile View aktivieren
        $this->SetVisualizationType(1);

        $intervall = max(0, $this->ReadPropertyInteger('AutoRefreshSekunden')) * 1000;
        $this->SetTimerInterval('VisualisierungAktualisieren', $intervall);

        $this->SetStatus(102);
    }

    // ─────────────────────────────────────────────────────────────────
    // Öffentliche Logging-API
    // ─────────────────────────────────────────────────────────────────

    /**
     * Zentrale Log-Methode — wird von allen Modulen aufgerufen.
     *
     * @param string $level   DEBUG, INFO, WARNING, ERROR
     * @param string $source  Name des aufrufenden Moduls (z.B. 'SmartLawnAI')
     * @param string $message Kurze Logmeldung
     * @param string $details Optionale Details
     */
    public function Log(string $level, string $source, string $message, string $details = ''): void
    {
        $level = strtoupper(trim($level));
        if (!in_array($level, self::VALID_LEVELS, true)) {
            $level = 'INFO';
        }

        $entry = [
            't' => time(),
            'l' => $level,
            's' => $source,
            'm' => $message,
        ];

        if ($details !== '') {
            $entry['d'] = $details;
        }

        // Ringbuffer
        $logData = $this->leseLogDaten();
        array_unshift($logData, $entry);

        $max = max(10, $this->ReadPropertyInteger('MaxEntries'));
        if (count($logData) > $max) {
            $logData = array_slice($logData, 0, $max);
        }

        try {
            $this->WriteAttributeString(self::ATTR_LOG_DATA, json_encode($logData, JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            IPS_LogMessage('SmartLog', 'JSON-Fehler: ' . $e->getMessage());
        }

        // Variablen aktualisieren
        $this->SetValue('EntryCount', count($logData));
        $this->SetValue('LastEntry', "[{$level}] {$source}: {$message}");

        // Tile View aktualisieren
        $this->aktualisiereVisualisierung();

        // Optional ins Symcon-Systemlog schreiben
        if ($this->ReadPropertyBoolean('MirrorToSyslog')) {
            IPS_LogMessage('SmartVillaKunterbunt', "{$source}: {$message}");
        }
    }

    public function Debug(string $source, string $message, string $details = ''): void
    {
        $this->Log('DEBUG', $source, $message, $details);
    }

    public function Info(string $source, string $message, string $details = ''): void
    {
        $this->Log('INFO', $source, $message, $details);
    }

    public function Warning(string $source, string $message, string $details = ''): void
    {
        $this->Log('WARNING', $source, $message, $details);
    }

    public function Error(string $source, string $message, string $details = ''): void
    {
        $this->Log('ERROR', $source, $message, $details);
    }

    public function ClearLog(): void
    {
        $this->WriteAttributeString(self::ATTR_LOG_DATA, '[]');
        $this->SetValue('EntryCount', 0);
        $this->SetValue('LastEntry', '');
        $this->aktualisiereVisualisierung();
    }

    public function FindInstance(): int
    {
        return $this->InstanceID;
    }

    // ─────────────────────────────────────────────────────────────────
    // Tile View
    // ─────────────────────────────────────────────────────────────────

    public function GetVisualizationTile(): string
    {
        $datei = __DIR__ . '/module.html';
        if (!is_file($datei)) {
            return '<div style="padding:1rem;font-family:sans-serif;">module.html nicht gefunden.</div>';
        }

        $html = (string) file_get_contents($datei);

        $cssDatei = __DIR__ . '/module.css';
        $cssBlock = '';
        if (is_file($cssDatei)) {
            $css = file_get_contents($cssDatei);
            if ($css !== false) {
                $cssBlock = "<style>\n" . $css . "\n</style>";
            }
        }

        $initialDaten = $this->erstelleVisualisierungsDaten();

        try {
            $encoded = json_encode($initialDaten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            IPS_LogMessage('SmartLog', 'JSON-Fehler: ' . $e->getMessage());
            $encoded = '{}';
        }
        $html = str_replace('%%MODULE_CSS%%', $cssBlock, $html);
        return str_replace(
            '%%INITIAL_DATA%%',
            $encoded,
            $html
        );
    }

    public function RequestAction(string $ident, mixed $value): void
    {
        $status = $this->leseStatus();

        switch ($ident) {
            case 'FilterLevel':
                try {
                    $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    IPS_LogMessage('SmartLog', 'JSON-Fehler: ' . $e->getMessage());
                    $decoded = null;
                }
                $status['levelFilter'] = is_array($decoded) ? $decoded : [];
                break;

            case 'FilterText':
                $status['textFilter'] = (string) $value;
                break;

            case 'FilterSource':
                try {
                    $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    IPS_LogMessage('SmartLog', 'JSON-Fehler: ' . $e->getMessage());
                    $decoded = null;
                }
                $status['sourceFilter'] = is_array($decoded) ? $decoded : [];
                break;

            case 'Seite':
                $status['seite'] = max(0, (int) $value);
                break;

            case 'ClearLog':
                $this->ClearLog();
                return;

            default:
                parent::RequestAction($ident, $value);
                return;
        }

        $this->schreibeStatus($status);
        $this->aktualisiereVisualisierung();
    }

    public function AktualisierenVisualisierung(): void
    {
        $this->aktualisiereVisualisierung();
    }

    // ─────────────────────────────────────────────────────────────────
    // Private Hilfsmethoden
    // ─────────────────────────────────────────────────────────────────

    private function leseLogDaten(): array
    {
        $json = $this->ReadAttributeString(self::ATTR_LOG_DATA);
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            IPS_LogMessage('SmartLog', 'JSON-Fehler: ' . $e->getMessage());
            $data = [];
        }
        return is_array($data) ? $data : [];
    }

    private function leseStatus(): array
    {
        $json = $this->ReadAttributeString(self::ATTR_STATUS);
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            IPS_LogMessage('SmartLog', 'JSON-Fehler: ' . $e->getMessage());
            $data = [];
        }
        return is_array($data) ? $data : [
            'seite' => 0,
            'maxZeilen' => 30,
            'levelFilter' => [],
            'textFilter' => '',
            'sourceFilter' => [],
        ];
    }

    private function schreibeStatus(array $status): void
    {
        try {
            $this->WriteAttributeString(self::ATTR_STATUS, json_encode($status, JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            IPS_LogMessage('SmartLog', 'JSON-Fehler: ' . $e->getMessage());
        }
    }

    private function erstelleVisualisierungsDaten(): array
    {
        $status = $this->leseStatus();
        $logData = $this->leseLogDaten();

        // Filter anwenden
        $gefiltert = $this->filtern($logData, $status);

        // Verfügbare Sources und Levels sammeln (aus ungefilterten Daten)
        $verfuegbareSources = [];
        $verfuegbareLevels = [];
        foreach ($logData as $entry) {
            $s = $entry['s'] ?? '';
            $l = $entry['l'] ?? '';
            if ($s !== '' && !in_array($s, $verfuegbareSources, true)) {
                $verfuegbareSources[] = $s;
            }
            if ($l !== '' && !in_array($l, $verfuegbareLevels, true)) {
                $verfuegbareLevels[] = $l;
            }
        }
        sort($verfuegbareSources);

        // Paginierung
        $maxZeilen = max(1, $status['maxZeilen'] ?? 30);
        $seite = max(0, $status['seite'] ?? 0);
        $gesamtTreffer = count($gefiltert);
        $gesamtSeiten = max(1, (int) ceil($gesamtTreffer / $maxZeilen));

        if ($seite >= $gesamtSeiten) {
            $seite = max(0, $gesamtSeiten - 1);
        }

        $offset = $seite * $maxZeilen;
        $seitenDaten = array_slice($gefiltert, $offset, $maxZeilen);

        return [
            'ok'                 => true,
            'zeilen'             => $seitenDaten,
            'seite'              => $seite,
            'gesamtSeiten'       => $gesamtSeiten,
            'gesamtTreffer'      => $gesamtTreffer,
            'gesamtEintraege'    => count($logData),
            'maxZeilen'          => $maxZeilen,
            'levelFilter'        => $status['levelFilter'] ?? [],
            'textFilter'         => $status['textFilter'] ?? '',
            'sourceFilter'       => $status['sourceFilter'] ?? [],
            'verfuegbareSources' => $verfuegbareSources,
            'verfuegbareLevels'  => $verfuegbareLevels,
            'levelColors'        => self::LEVEL_COLORS,
            'levelIcons'         => self::LEVEL_ICONS,
        ];
    }

    private function filtern(array $logData, array $status): array
    {
        $levelFilter = $status['levelFilter'] ?? [];
        $textFilter = strtolower(trim($status['textFilter'] ?? ''));
        $sourceFilter = $status['sourceFilter'] ?? [];

        if (empty($levelFilter) && $textFilter === '' && empty($sourceFilter)) {
            return $logData;
        }

        return array_values(array_filter($logData, function (array $entry) use ($levelFilter, $textFilter, $sourceFilter): bool {
            // Level-Filter
            if (!empty($levelFilter) && !in_array($entry['l'] ?? '', $levelFilter, true)) {
                return false;
            }

            // Source-Filter
            if (!empty($sourceFilter) && !in_array($entry['s'] ?? '', $sourceFilter, true)) {
                return false;
            }

            // Text-Filter
            if ($textFilter !== '') {
                $haystack = strtolower(($entry['m'] ?? '') . ' ' . ($entry['d'] ?? '') . ' ' . ($entry['s'] ?? ''));
                if (strpos($haystack, $textFilter) === false) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function aktualisiereVisualisierung(): void
    {
        $daten = $this->erstelleVisualisierungsDaten();
        try {
            $encoded = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            IPS_LogMessage('SmartLog', 'JSON-Fehler: ' . $e->getMessage());
            return;
        }
        $this->UpdateVisualizationValue($encoded);
    }

    // ─────────────────────────────────────────────────────────────────
    // Konfigurationsformular
    // ─────────────────────────────────────────────────────────────────

    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartLog: ' . $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "caption": "SmartLog — Zentrales Logging für alle Module\n\nAlle Einstellungen werden direkt in der Tile View (Kachelansicht) angezeigt."
        },
        {
            "type": "NumberSpinner",
            "name": "MaxEntries",
            "caption": "Maximale Log-Einträge",
            "minimum": 10,
            "maximum": 1000
        },
        {
            "type": "NumberSpinner",
            "name": "AutoRefreshSekunden",
            "caption": "Auto-Refresh (Sekunden, 0 = aus)",
            "minimum": 0,
            "maximum": 300
        },
        {
            "type": "CheckBox",
            "name": "MirrorToSyslog",
            "caption": "Logs zusätzlich ins IP-Symcon Syslog schreiben"
        }
    ],
    "actions": [
        {
            "type": "Button",
            "label": "🗑️ Log leeren",
            "onClick": "SLOG_ClearLog($id);"
        },
        {
            "type": "Button",
            "label": "🔄 Visualisierung aktualisieren",
            "onClick": "SLOG_AktualisierenVisualisierung($id);"
        }
    ],
    "status": [
        {"code": 102, "icon": "active", "caption": "Bereit — Log aktiv."},
        {"code": 104, "icon": "inactive", "caption": "Inaktiv."}
    ]
}
EOT;
    }
}
