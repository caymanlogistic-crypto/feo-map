<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';

// Replicate slitex functions needed
function dgGetSlitexConfig(): array {
    $baseUrl = trim((string)($GLOBALS['slitexBaseUrl'] ?? getenv('SLITEX_BASE_URL') ?: 'https://slitex.online'));
    $token = trim((string)($GLOBALS['slitexApiToken'] ?? getenv('SLITEX_API_TOKEN') ?: ''));
    return ['base_url' => rtrim($baseUrl, '/'), 'token' => $token];
}
function dgSlitexRequest(string $method, string $url, string $token, ?array $payload = null): array {
    $ch = curl_init($url); if ($ch === false) return ['success'=>false,'status'=>0,'body'=>''];
    $headers = ['Accept: application/json', 'X-API-Token: ' . $token];
    $options = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_CUSTOMREQUEST=>strtoupper($method)];
    if ($payload !== null) { $headers[] = 'Content-Type: application/json'; $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE); }
    $options[CURLOPT_HTTPHEADER] = $headers; curl_setopt_array($ch, $options);
    $resp = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['success' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string)$resp];
}

$cfg = dgGetSlitexConfig();

// Device 560201 detail
echo "=== Device 560201 detail ===\n";
$r = dgSlitexRequest('GET', rtrim($cfg['base_url'], '/') . '/api/external/devices/560201', $cfg['token']);
echo "HTTP={$r['status']} success=" . ($r['success'] ? '1' : '0') . "\n";
$data = json_decode($r['body'], true);
if (is_array($data)) {
    $dev = $data['data'] ?? $data;
    echo "Top keys: " . implode(', ', array_keys($dev)) . "\n";
    echo "uniqueid: " . ($dev['uniqueid'] ?? '?') . "\n";
    echo "name: " . ($dev['name'] ?? '?') . "\n";
    foreach ($dev as $k => $v) {
        if (!is_array($v) && !is_object($v)) echo "  {$k}: " . (is_null($v) ? 'NULL' : (string)$v) . "\n";
    }
    foreach ($dev as $k => $v) {
        if (is_array($v)) echo "  {$k}: [array of " . count($v) . " items, keys: " . implode(', ', array_keys($v)) . "]\n";
    }
} else {
    echo "RAW: " . substr($r['body'], 0, 500) . "\n";
}

echo "DONE\n";
