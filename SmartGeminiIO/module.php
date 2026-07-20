<?php

declare(strict_types=1);

require_once __DIR__ . '/../SmartLog/libs/Trait_SmartLog.php';

/**
 * SmartGeminiIO — Zentraler Gemini API Client für alle IP-Symcon KI-Module.
 *
 * Dieses Modul verwaltet den API-Schlüssel und das Modell an einer einzigen Stelle.
 * Andere Module (ImperialDishwasherAI, SmartLawnAI, etc.) rufen GIO_Query() auf,
 * anstatt die Gemini API direkt anzusprechen.
 *
 * Verwendung durch Caller-Module:
 *   $geminiId = GIO_FindInstance(); // oder manuell konfigurieren
 *   $jsonResult = GIO_Query($geminiId, $prompt, $systemInstruction, json_encode($schema));
 *   $parsed = json_decode($jsonResult, true); // direkt verwendbar
 *
 * Caller wrappen in IPS_RunScriptText() wenn asynchron gewünscht.
 */
class SmartGeminiIO extends IPSModuleStrict
{
    use SmartLog_Trait;
    // Gemini API Basis-URL (v1beta für responseSchema-Support)
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function Create(): void
    {
        parent::Create();

        // Konfiguration
        $this->RegisterPropertyString('ApiKey', '');
        $this->RegisterPropertyString('Model', 'gemini-2.5-flash');
        $this->RegisterPropertyInteger('TimeoutSeconds', 30);

        // Statistik & Status (intern, nicht primär für Webfront)
        $this->RegisterVariableInteger('TotalRequests', 'Gesamt-Anfragen', '', 1);
        IPS_SetIcon($this->GetIDForIdent('TotalRequests'), 'Information');

        $this->RegisterVariableInteger('SuccessfulRequests', 'Erfolgreiche Anfragen', '', 2);
        IPS_SetIcon($this->GetIDForIdent('SuccessfulRequests'), 'Ok');

        $this->RegisterVariableInteger('FailedRequests', 'Fehlgeschlagene Anfragen', '', 3);
        IPS_SetIcon($this->GetIDForIdent('FailedRequests'), 'Warning');

        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 4);
        IPS_SetIcon($this->GetIDForIdent('LastError'), 'Warning');

        $this->RegisterVariableString('LastModel', 'Letztes Modell', '', 5);
        IPS_SetIcon($this->GetIDForIdent('LastModel'), 'Information');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (empty($this->ReadPropertyString('ApiKey'))) {
            $this->SetStatus(104); // IS_INACTIVE
        } else {
            $this->SetStatus(102); // IS_ACTIVE
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Öffentliche API
    // ─────────────────────────────────────────────────────────────────

    /**
     * Sendet einen Prompt an die Gemini API und gibt den extrahierten JSON-Text zurück.
     *
     * @param string $userPrompt        Der Benutzer-Prompt
     * @param string $systemInstruction Die System-Anweisung (z.B. "Du antwortest im JSON-Format.")
     * @param string $schemaJson        JSON-String des responseSchema (leerer String = kein Schema)
     * @param float  $temperature       KI-Temperatur (0.0 = deterministisch, 1.0 = kreativ), Standard: 0.1
     * @return string Extrahierter JSON-Text aus der Gemini-Antwort, oder leerer String bei Fehler
     */
    public function Query(
        string $userPrompt,
        string $systemInstruction = '',
        string $schemaJson = '',
        float  $temperature = 0.1
    ): string {
        $apiKey  = trim($this->ReadPropertyString('ApiKey'));
        $model   = trim($this->ReadPropertyString('Model'));
        $timeout = $this->ReadPropertyInteger('TimeoutSeconds');

        if (empty($apiKey)) {
            $this->SetStatus(104);
            $this->SetValue('LastError', 'Kein API-Key konfiguriert.');
            $this->SLog('ERROR', 'Abfrage abgebrochen.', 'Grund: Kein API-Key konfiguriert');
            return '';
        }

        $url = self::API_BASE . $model . ':generateContent?key=' . $apiKey;

        // Payload aufbauen
        $payload = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $userPrompt]]
                ]
            ],
            'generationConfig' => [
                'temperature' => $temperature
            ]
        ];

        if (!empty($systemInstruction)) {
            $payload['system_instruction'] = [
                'parts' => [['text' => $systemInstruction]]
            ];
        }

        if (!empty($schemaJson)) {
            if ($schemaJson === 'application/json') {
                // JSON-Modus ohne Schema (Gemini gibt freies JSON zurück)
                $payload['generationConfig']['responseMimeType'] = 'application/json';
            } else {
                $schema = json_decode($schemaJson, true);
                if (is_array($schema)) {
                    // JSON-Modus mit strukturiertem Schema
                    $payload['generationConfig']['responseMimeType'] = 'application/json';
                    $payload['generationConfig']['responseSchema']   = $schema;
                }
            }
        }

        $jsonPayload = json_encode($payload);

        // Zähler erhöhen
        $this->SetValue('TotalRequests', $this->GetValue('TotalRequests') + 1);
        $this->SetValue('LastModel', $model);

        // HTTP POST via cURL (synchron)
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $maxRetries = 2;
        $attempt = 0;
        $rawResponse = false;
        $httpCode = 0;
        $curlError = '';

        do {
            $rawResponse = curl_exec($ch);
            if ($rawResponse === false) {
                $curlError = curl_error($ch);
                $this->SLog('WARNING', 'API-Anfrage fehlgeschlagen, Wiederholungsversuch...', 'Versuch: ' . ($attempt + 1) . ' | Fehler: ' . $curlError);
                $attempt++;
                if ($attempt < $maxRetries) {
                    usleep(500000); // 500ms warten
                }
                continue;
            }
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode !== 200) {
                $this->SLog('WARNING', 'API-Fehlercode erhalten, Wiederholungsversuch...', 'Versuch: ' . ($attempt + 1) . ' | HTTP: ' . $httpCode);
                $attempt++;
                if ($attempt < $maxRetries) usleep(500000);
                continue;
            }
            break; // Erfolg
        } while ($attempt < $maxRetries);

        curl_close($ch);

        if ($rawResponse === false || $httpCode !== 200) {
            $errorMsg = "Gemini API Fehler (HTTP $httpCode)";
            if ($rawResponse === false) {
                $errorMsg .= ': cURL Error - ' . $curlError;
            } else {
                $errData = json_decode($rawResponse, true);
                if (isset($errData['error']['message'])) {
                    $errorMsg .= ': ' . $errData['error']['message'];
                }
            }
            $this->SetValue('LastError', $errorMsg);
            $this->SetValue('FailedRequests', $this->GetValue('FailedRequests') + 1);
            $this->SLog('ERROR', 'Gemini API Fehler.', "Grund: $errorMsg");
            return '';
        }

        // Erfolgreiche Antwort parsen
        $data = json_decode($rawResponse, true);
        $extractedText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($extractedText)) {
            $errorMsg = 'Gemini API: Leere oder unerwartete Antwortstruktur.';
            $this->SetValue('LastError', $errorMsg);
            $this->SetValue('FailedRequests', $this->GetValue('FailedRequests') + 1);
            $this->SLog('ERROR', 'Gemini API Fehler.', "Grund: $errorMsg");
            return '';
        }

        $this->SetValue('LastError', '');
        $this->SetValue('SuccessfulRequests', $this->GetValue('SuccessfulRequests') + 1);
        $this->SLog('INFO', 'Gemini API erfolgreich abgefragt.', "Modell: $model | Prompt-Länge: " . strlen($userPrompt));

        return $extractedText;
    }

    /**
     * Gibt die Instance-ID des ersten verfügbaren SmartGeminiIO zurück.
     * Nützlich für Callers zur Auto-Discovery.
     *
     * Verwendung in Caller-Modul:
     *   $geminiId = GIO_FindInstance(IPS_GetInstanceListByModuleID('{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}')[0]);
     *
     * @return int Instance-ID oder 0 wenn nicht gefunden
     */
    public function FindInstance(): int
    {
        return $this->InstanceID;
    }

    /**
     * Führt einen schnellen Verbindungstest zur Gemini API durch.
     */
    public function TestConnection(): void
    {
        $result = $this->Query(
            'Antworte mit {"status":"ok"}.',
            'Du antwortest ausschließlich im JSON-Format.',
            '{"type":"OBJECT","properties":{"status":{"type":"STRING"}},"required":["status"]}'
        );

        if (!empty($result)) {
            echo "✅ Verbindung erfolgreich. Antwort: $result";
        } else {
            echo "❌ Verbindung fehlgeschlagen. Prüfe API-Key und Modell. Letzter Fehler: " . $this->GetValue('LastError');
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Statistische Hilfsmethode
    // ─────────────────────────────────────────────────────────────────

    public function ResetCounters(): void
    {
        $this->SetValue('TotalRequests', 0);
        $this->SetValue('SuccessfulRequests', 0);
        $this->SetValue('FailedRequests', 0);
        $this->SetValue('LastError', '');
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->SLog('INFO', $Message);
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartGeminiIO: ' . $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "caption": "SmartGeminiIO — Zentraler Gemini API Client\n\nKonfiguriere hier einmalig deinen Google Gemini API-Key und das gewünschte Modell. Alle anderen Module (Spülmaschine, Rasen-KI, etc.) nutzen automatisch diese Instanz."
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "ValidationTextBox",
                    "name": "ApiKey",
                    "caption": "Google Gemini API-Key"
                },
                {
                    "type": "ValidationTextBox",
                    "name": "Model",
                    "caption": "Modell (z.B. gemini-2.5-flash)"
                }
            ]
        },
        {
            "type": "NumberSpinner",
            "name": "TimeoutSeconds",
            "caption": "Timeout (Sekunden)",
            "minimum": 5,
            "maximum": 120
        },
        {
            "type": "Label",
            "caption": "Tipp: Empfohlene Modelle:\n- gemini-2.5-flash (schnell, kosteneffizient)\n- gemini-2.5-pro (höchste Qualität, langsamer)"
        }
    ],
    "actions": [
        {
            "type": "Button",
            "label": "🔌 Verbindungstest",
            "onClick": "GIO_TestConnection($id);"
        },
        {
            "type": "Button",
            "label": "🔄 Zähler zurücksetzen",
            "onClick": "GIO_ResetCounters($id);"
        }
    ],
    "status": [
        {"code": 102, "icon": "active",   "caption": "Bereit — API-Key konfiguriert."},
        {"code": 104, "icon": "inactive", "caption": "Kein API-Key konfiguriert."}
    ]
}
EOT;
    }
}
