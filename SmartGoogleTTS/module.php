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
    }

    public function PlayMessage(string $Text)
    {
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

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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
            mkdir($moduleDir, 0777, true);
        }

        $fileName = "tts_" . md5($Text . $voiceName) . ".mp3";
        $filePath = $moduleDir . DIRECTORY_SEPARATOR . $fileName;

        // Write file
        file_put_contents($filePath, $audioContent);

        // Construct URL
        $baseURL = rtrim($baseURL, "/");
        $fileURL = $baseURL . "/user/SmartGoogleTTS/" . $fileName;

        // Play on Sonos
        $filesArray = json_encode([$fileURL]);
        
        if (function_exists('SNS_PlayFiles')) {
            SNS_PlayFiles($targetSonosID, $filesArray, "+0");
        } else {
            echo "Warnung: Funktion SNS_PlayFiles existiert nicht. Bitte sicherstellen, dass das Sonos Modul korrekt installiert ist.";
            return false;
        }

        return $fileURL;
    }
}
