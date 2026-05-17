<?php
class SlitexClient
{
    private $baseUrl;
    private $token;

    public function __construct($baseUrl, $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    public function fetchDevices()
    {
        return $this->fetch('/api/external/devices');
    }

    public function fetchAdminFields()
    {
        return $this->fetch('/api/external/devices/admin-fields');
    }

    public function fetch($path)
    {
        $url = $this->baseUrl . $path;

        $ch = curl_init();
        if ($ch === false) {
            mapError('curl_init failed', ['url' => $url]);
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["X-API-Token: {$this->token}"],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlErr) {
            mapError('Slitex request failed', ['url' => $url, 'http_code' => $httpCode, 'error' => $curlErr]);
            return [];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            mapError('Slitex non-2xx response', ['url' => $url, 'http_code' => $httpCode]);
            return [];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            mapError('Slitex invalid JSON', ['url' => $url, 'json_error' => json_last_error_msg()]);
            return [];
        }

        return $decoded;
    }
}
