<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$backendBaseUrl = trim((string)($config['backend_base_url'] ?? ''));
$timeoutSeconds = max(1, (int)($config['timeout_seconds'] ?? 20));

$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/api');
$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = rawurldecode((string)(parse_url($requestUri, PHP_URL_PATH) ?? '/api'));
$queryString = (string)($_SERVER['QUERY_STRING'] ?? '');
$relativePath = preg_replace('#^/api/?#', '', $path);
$relativePath = ltrim((string)$relativePath, '/');

if ($backendBaseUrl !== '') {
    proxyRequest(rtrim($backendBaseUrl, '/') . '/api/' . $relativePath, $queryString, $timeoutSeconds);
    exit;
}

if ($relativePath === '' || $relativePath === 'health') {
    sendJson(200, [
        'ok' => true,
        'backend' => 'php-hostinger-compat',
        'mode' => 'built-in',
    ]);
    exit;
}

switch ($relativePath) {
    case 'overpass':
        $overpassBody = file_get_contents('php://input') ?: '';
        proxyRequest(
            'https://overpass-api.de/api/interpreter',
            '',
            $timeoutSeconds,
            $requestMethod,
            $overpassBody,
            ['Content-Type: text/plain; charset=utf-8'],
        );
        exit;

    case 'opensky':
        proxyRequest('https://opensky-network.org/api/states/all', $queryString, $timeoutSeconds);
        exit;

    case 'opensky-track':
        $incomingTrackQuery = [];
        parse_str($queryString, $incomingTrackQuery);
        $icao24 = strtolower(trim((string)($incomingTrackQuery['icao24'] ?? '')));
        if (!preg_match('/^[0-9a-f]{6}$/', $icao24)) {
            sendJson(400, ['error' => 'icao24 must be a 6-char hex string']);
            exit;
        }
        proxyRequest(
            'https://opensky-network.org/api/tracks/all?icao24=' . rawurlencode($icao24) . '&time=0',
            '',
            $timeoutSeconds
        );
        exit;

    case 'adsblol/mil':
        proxyRequest('https://api.adsb.lol/v2/mil', $queryString, $timeoutSeconds);
        exit;

    case 'adsblol/trace':
        $incomingTraceQuery = [];
        parse_str($queryString, $incomingTraceQuery);
        $hex = strtolower(trim((string)($incomingTraceQuery['hex'] ?? '')));
        if (!preg_match('/^[0-9a-f~]{6,7}$/', $hex)) {
            sendJson(400, ['error' => 'hex must be a 6-7 char hex string']);
            exit;
        }
        $traceUrl = 'https://adsb.lol/data/traces/' . substr($hex, -2) . '/trace_full_' . rawurlencode($hex) . '.json';
        proxyRequest($traceUrl, '', $timeoutSeconds);
        exit;

    case 'celestrak/active':
        proxyRequest('https://celestrak.org/NORAD/elements/gp.php?GROUP=ACTIVE&FORMAT=TLE', '', $timeoutSeconds);
        exit;

    case 'launches':
        proxyRequest(
            'https://ll.thespacedevs.com/2.2.0/launch/upcoming/?limit=100',
            '',
            $timeoutSeconds
        );
        exit;

    case 'tomtom/status':
        sendJson(200, ['hasKey' => false]);
        exit;

    default:
        sendJson(501, [
            'error' => 'Endpoint is not available in the built-in Hostinger PHP backend.',
            'path' => '/api/' . $relativePath,
            'hint' => 'Set GEV_API_BACKEND_BASE_URL to forward all /api calls to a full backend.',
        ]);
}

function proxyRequest(
    string $baseUrl,
    string $queryString,
    int $timeoutSeconds,
    ?string $method = null,
    string $body = '',
    array $extraHeaders = []
): void {
    $method = strtoupper($method ?: (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $url = $baseUrl;
    if ($queryString !== '') {
        $url .= (str_contains($url, '?') ? '&' : '?') . $queryString;
    }

    if (!function_exists('curl_init')) {
        sendJson(500, ['error' => 'cURL extension is required for Hostinger API proxy mode.']);
        return;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        sendJson(500, ['error' => 'Unable to initialize outbound API request.']);
        return;
    }

    $headers = ['Accept: */*'];
    foreach ($extraHeaders as $header) $headers[] = $header;

    if ($method !== 'GET' && $method !== 'HEAD') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $headers[] = 'Content-Length: ' . strlen($body);
        }
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSeconds),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'gods-eye-view-hostinger-api/1.0',
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        sendJson(502, ['error' => 'Upstream API request failed.', 'detail' => $error ?: 'Unknown cURL error']);
        return;
    }

    $rawHeaders = substr($response, 0, $headerSize);
    $rawBody = substr($response, $headerSize);
    $lines = preg_split("/\r\n|\n|\r/", $rawHeaders) ?: [];
    $contentType = '';
    foreach ($lines as $line) {
        if (stripos($line, 'Content-Type:') === 0) {
            $contentType = trim(substr($line, strlen('Content-Type:')));
        }
    }

    if ($httpCode <= 0) $httpCode = 502;
    http_response_code($httpCode);
    header('Cache-Control: no-store, max-age=0');
    if ($contentType !== '') {
        header('Content-Type: ' . $contentType);
    }
    echo $rawBody;
}

function sendJson(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}
