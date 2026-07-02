<?php

declare(strict_types=1);

class SmartGoogleTTS extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Register Properties
        $this->RegisterPropertyString("ApiKey", "");
        $this->RegisterPropertyString("VoiceName", "de-DE-Wavenet-C");
        $this->RegisterPropertyInteger("TargetSonosID", 0);
        $this->RegisterPropertyString("SymconBaseURL", "http://192.168.1.100:3777");
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->RegisterHook("/hook/SmartGoogleTTS_" . $this->InstanceID);
    }

    protected function RegisterHook($WebHook)
    {
        $ids = IPS_GetInstanceListByModuleID("{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}");
        if (sizeof($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], "Hooks"), true);
            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $WebHook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        return;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ["Hook" => $WebHook, "TargetID" => $this->InstanceID];
            }
            IPS_SetProperty($ids[0], "Hooks", json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        }
    }

    protected function ProcessHookData()
    {
        $uri = $_SERVER['REQUEST_URI'];
        $parts = explode('?', $uri); // Remove query string if any
        $path = $parts[0];
        $file = basename($path);

        if ($file === '' || strpos($file, '.mp3') === false) {
            http_response_code(400);
            echo "No valid file specified";
            return;
        }

        $userDir = IPS_GetKernelDir() . "webfront" . DIRECTORY_SEPARATOR . "user" . DIRECTORY_SEPARATOR;
        $moduleDir = $userDir . "SmartGoogleTTS";
        $filePath = $moduleDir . DIRECTORY_SEPARATOR . $file;

        if (file_exists($filePath)) {
            header("Content-Type: audio/mpeg");
            header("Content-Length: " . filesize($filePath));
            header("Accept-Ranges: bytes");
            readfile($filePath);
        } else {
            http_response_code(404);
            echo "File not found";
        }
    }

    public function PlayMessage(string $Text)
    {
        $this->SendDebug("GoogleTTS", "Starte Sprachausgabe mit Text: " . $Text, 0);

        $apiKey = $this->ReadPropertyString("ApiKey");
        $voiceName = $this->ReadPropertyString("VoiceName");
        $targetSonosID = $this->ReadPropertyInteger("TargetSonosID");
        $baseURL = $this->ReadPropertyString("SymconBaseURL");

        if (empty($apiKey)) {
            echo "Fehler: Google Cloud API Key ist nicht konfiguriert.";
            return false;
        }

        if ($targetSonosID == 0 || !IPS_InstanceExists($targetSonosID)) {
            echo "Fehler: Keine gueltige Sonos Ziel-Instanz konfiguriert.";
            return false;
        }

        // Determine language code from voice name (e.g. de-DE-Wavenet-C -> de-DE)
        $languageCode = substr($voiceName, 0, 5);

        // API Endpoint
        $url = "https://texttospeech.googleapis.com/v1/text:synthesize?key=" . $apiKey;

        // Request Payload
        $data = [
            "input" => [
                "text" => $Text
            ],
            "voice" => [
                "languageCode" => $languageCode,
                "name" => $voiceName
            ],
            "audioConfig" => [
                "audioEncoding" => "MP3"
            ]
        ];

        // cURL Request
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $this->SendDebug("GoogleTTS", "Sende Request an Google API...", 0);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->SendDebug("GoogleTTS", "Google API HTTP Code: " . $httpCode, 0);

        if ($httpCode !== 200) {
            echo "Fehler bei der Google TTS API Anfrage. HTTP Code: " . $httpCode . "\nResponse: " . $response;
            return false;
        }

        $result = json_decode($response, true);
        if (!isset($result['audioContent'])) {
            echo "Fehler: Keine Audio-Daten von Google empfangen.";
            return false;
        }

        $audioContent = base64_decode($result['audioContent']);

        // Define target directory and file name
        $userDir = IPS_GetKernelDir() . "webfront" . DIRECTORY_SEPARATOR . "user" . DIRECTORY_SEPARATOR;
        $moduleDir = $userDir . "SmartGoogleTTS";
        
        if (!is_dir($moduleDir)) {
            if (!mkdir($moduleDir, 0777, true)) {
                echo "Fehler: Konnte Verzeichnis nicht erstellen: " . $moduleDir;
                return false;
            }
        }

        $fileName = "tts_" . md5($Text . $voiceName) . ".mp3";
        $filePath = $moduleDir . DIRECTORY_SEPARATOR . $fileName;

        $this->SendDebug("GoogleTTS", "Speichere MP3 in Pfad: " . $filePath, 0);

        // Write file
        if (file_put_contents($filePath, $audioContent) === false) {
            echo "Fehler: Konnte MP3-Datei nicht schreiben: " . $filePath;
            return false;
        }

        // Set permissions so the webserver can read it
        chmod($filePath, 0777);

        // Output absolute path for debugging
        echo "Erfolgreich gespeichert unter: " . $filePath . "\n";

        // Construct URL via Webhook
        $baseURL = rtrim($baseURL, "/");
        $fileURL = $baseURL . "/hook/SmartGoogleTTS_" . $this->InstanceID . "/" . $fileName;

        $this->SendDebug("GoogleTTS", "Generierte Webhook-URL für Sonos: " . $fileURL, 0);

        // Play on Sonos
        $filesArray = json_encode([$fileURL]);
        
        if (function_exists('SNS_PlayFiles')) {
            $this->SendDebug("GoogleTTS", "Rufe SNS_PlayFiles auf Instanz " . $targetSonosID . " auf...", 0);
            SNS_PlayFiles($targetSonosID, $filesArray, "+0");
        } else {
            echo "Warnung: Funktion SNS_PlayFiles existiert nicht. Bitte sicherstellen, dass das Sonos Modul korrekt installiert ist.";
            return false;
        }

        return $fileURL;
    }
}
