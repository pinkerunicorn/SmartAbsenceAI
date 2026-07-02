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
        
        $this->RegisterPropertyString("SonosVolume", "+0");
        $this->RegisterPropertyFloat("SpeakingRate", 1.0);
        $this->RegisterPropertyFloat("Pitch", 0.0);

        // Register Timer in Create (interval 0 disables it initially)
        $this->RegisterTimer("CleanupTimer", 0, 'SGTTS_CleanupCache($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->RegisterHook("/hook/SmartGoogleTTS_" . $this->InstanceID);

        // Set Timer Interval to 24 hours (86400000 ms) in ApplyChanges
        $this->SetTimerInterval("CleanupTimer", 86400000);
    }

    public function ClearCache()
    {
        $userDir = IPS_GetKernelDir() . "webfront" . DIRECTORY_SEPARATOR . "user" . DIRECTORY_SEPARATOR;
        $moduleDir = $userDir . "SmartGoogleTTS";
        if (is_dir($moduleDir)) {
            $files = glob($moduleDir . DIRECTORY_SEPARATOR . "*.mp3");
            $count = 0;
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                    $count++;
                }
            }
            echo "Cache geleert. " . $count . " Dateien gelöscht.";
        } else {
            echo "Cache-Verzeichnis existiert nicht.";
        }
    }

    public function CleanupCache()
    {
        $userDir = IPS_GetKernelDir() . "webfront" . DIRECTORY_SEPARATOR . "user" . DIRECTORY_SEPARATOR;
        $moduleDir = $userDir . "SmartGoogleTTS";
        if (is_dir($moduleDir)) {
            $files = glob($moduleDir . DIRECTORY_SEPARATOR . "*.mp3");
            $now = time();
            foreach ($files as $file) {
                if (is_file($file)) {
                    // Delete files older than 30 days
                    if ($now - filemtime($file) >= 30 * 24 * 3600) {
                        unlink($file);
                    }
                }
            }
        }
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
        
        $Volume = $this->ReadPropertyString("SonosVolume");
        if ($Volume === "") {
            $Volume = "+0"; // Fallback to unchanged
        }

        $speakingRate = $this->ReadPropertyFloat("SpeakingRate");
        $pitch = $this->ReadPropertyFloat("Pitch");

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

        // Define target directory and file name
        $userDir = IPS_GetKernelDir() . "webfront" . DIRECTORY_SEPARATOR . "user" . DIRECTORY_SEPARATOR;
        $moduleDir = $userDir . "SmartGoogleTTS";
        
        if (!is_dir($moduleDir)) {
            if (!mkdir($moduleDir, 0777, true)) {
                echo "Fehler: Konnte Verzeichnis nicht erstellen: " . $moduleDir;
                return false;
            }
        }

        // Include volume, pitch and rate in the hash so different settings generate different files!
        $hashString = $Text . $voiceName . $speakingRate . $pitch;
        $fileName = "tts_" . md5($hashString) . ".mp3";
        $filePath = $moduleDir . DIRECTORY_SEPARATOR . $fileName;

        if (!file_exists($filePath)) {
            $this->SendDebug("GoogleTTS", "Datei nicht im Cache. Sende Request an Google API...", 0);

            // Check if user is using SSML (Speech Synthesis Markup Language)
            $isSSML = (strpos(trim($Text), '<speak>') === 0);
            $inputPayload = $isSSML ? ["ssml" => $Text] : ["text" => $Text];

            // API Endpoint
            $url = "https://texttospeech.googleapis.com/v1/text:synthesize?key=" . $apiKey;

            // Request Payload
            $data = [
                "input" => $inputPayload,
                "voice" => [
                    "languageCode" => $languageCode,
                    "name" => $voiceName
                ],
                "audioConfig" => [
                    "audioEncoding" => "MP3",
                    "speakingRate" => $speakingRate,
                    "pitch" => $pitch
                ]
            ];

            // cURL Request
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

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

            $this->SendDebug("GoogleTTS", "Speichere MP3 in Pfad: " . $filePath, 0);

            // Write file
            if (file_put_contents($filePath, $audioContent) === false) {
                echo "Fehler: Konnte MP3-Datei nicht schreiben: " . $filePath;
                return false;
            }

            // Set permissions so the webserver can read it
            chmod($filePath, 0777);
        } else {
            $this->SendDebug("GoogleTTS", "Audio existiert bereits im Cache. Überspringe Google API Anfrage.", 0);
        }

        // Construct URL via Webhook
        $baseURL = rtrim($baseURL, "/");
        $fileURL = $baseURL . "/hook/SmartGoogleTTS_" . $this->InstanceID . "/" . $fileName;

        $this->SendDebug("GoogleTTS", "Generierte Webhook-URL für Sonos: " . $fileURL, 0);

        // Play on Sonos
        $filesArray = json_encode([$fileURL]);
        
        if (function_exists('SNS_PlayFiles')) {
            $this->SendDebug("GoogleTTS", "Rufe SNS_PlayFiles auf Instanz " . $targetSonosID . " auf mit Lautstärke " . $Volume . "...", 0);
            SNS_PlayFiles($targetSonosID, $filesArray, $Volume);
        } else {
            echo "Warnung: Funktion SNS_PlayFiles existiert nicht. Bitte sicherstellen, dass das Sonos Modul korrekt installiert ist.";
            return false;
        }

        return $fileURL;
    }
}
