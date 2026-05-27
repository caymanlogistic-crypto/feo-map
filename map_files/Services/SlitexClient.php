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

    public function renameDevice(string $uniqueid, string $name): array
    {
        $path = '/api/external/devices/' . rawurlencode($uniqueid) . '/name';
        $url = $this->baseUrl . $path;

        $ch = curl_init();
        if ($ch === false) {
            mapError('Slitex rename: curl_init failed', ['uniqueid' => $uniqueid]);
            return ['success' => false, 'status' => 0, 'error' => 'curl_init failed', 'body' => ''];
        }

        $payload = json_encode(['name' => $name], JSON_UNESCAPED_UNICODE);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-API-Token: ' . $this->token,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlErr) {
            mapError('Slitex rename request failed', ['url' => $url, 'http_code' => $httpCode, 'error' => $curlErr]);
            return ['success' => false, 'status' => $httpCode, 'error' => $curlErr ?: 'Request failed', 'body' => ''];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            mapError('Slitex rename non-2xx', ['url' => $url, 'http_code' => $httpCode, 'body' => mb_substr((string)$response, 0, 500)]);
            return ['success' => false, 'status' => $httpCode, 'error' => 'HTTP ' . $httpCode, 'body' => (string)$response];
        }

        return ['success' => true, 'status' => $httpCode, 'error' => '', 'body' => (string)$response];
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
