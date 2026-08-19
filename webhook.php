<?php
// Lightweight & Fast Dynamic Multi-Webhook Forwarder with Auto /api Prefix
require_once __DIR__ . '/config.php';

$config = get_config();

// Verify optional secret token
if (!empty($config['secret_key'])) {
    $token = $_GET['key'] ?? $_SERVER['HTTP_X_SECRET_KEY'] ?? '';
    if ($token !== $config['secret_key']) {
        http_response_code(401);
        exit('Unauthorized');
    }
}

// Read incoming request
$body = file_get_contents('php://input');
$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/webhooks/adyen/central';
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$queryString = parse_url($requestUri, PHP_URL_QUERY);

// Ensure /api/ prefix for Laravel routing
$forwardPath = $requestPath;
if (strpos($forwardPath, '/api/') !== 0 && $forwardPath !== '/api') {
    $forwardPath = '/api' . (strpos($forwardPath, '/') === 0 ? $forwardPath : '/' . $forwardPath);
}
$forwardUri = $forwardPath . ($queryString ? '?' . $queryString : '');

$headers = [];
if (function_exists('getallheaders')) {
    $headers = getallheaders();
} else {
    foreach ($_SERVER as $k => $v) {
        if (substr($k, 0, 5) === 'HTTP_') {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
            $headers[$name] = $v;
        } elseif ($k === 'CONTENT_TYPE') {
            $headers['Content-Type'] = $v;
        }
    }
}

// Determine destination URL
$rawTarget = trim($config['target_url'] ?? '');
$fCode = 0;
$fStatus = 'Disabled';
$duration = 0;
$destinationUrl = '';

if (!empty($config['forwarding_enabled']) && !empty($rawTarget)) {
    $parsed = parse_url($rawTarget);
    if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
        $portStr = !empty($parsed['port']) ? ':' . $parsed['port'] : '';
        $baseHost = $parsed['scheme'] . '://' . $parsed['host'] . $portStr;
        $destinationUrl = $baseHost . $forwardUri;
    } else {
        $destinationUrl = rtrim($rawTarget, '/') . $forwardUri;
    }

    $t0 = microtime(true);
    $forwardHeaders = [];
    $skip = ['host', 'content-length', 'connection', 'accept-encoding'];
    foreach ($headers as $k => $v) {
        if (!in_array(strtolower($k), $skip)) {
            $forwardHeaders[] = "$k: $v";
        }
    }
    $forwardHeaders[] = 'X-Forwarded-By: dev.lakru.one';

    $ch = curl_init($destinationUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $forwardHeaders);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $res = curl_exec($ch);
    $fCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    unset($ch);

    $duration = round((microtime(true) - $t0) * 1000, 1);
    $fStatus = $err ? ('Fail: ' . $err) : ("HTTP $fCode");
}

// Log event
add_log([
    'id' => substr(md5(uniqid()), 0, 8),
    'timestamp' => time(),
    'time' => date('H:i:s'),
    'date' => date('M d'),
    'path' => $requestPath,
    'dest' => $destinationUrl,
    'ip' => $ip,
    'method' => $method,
    'code' => $fCode,
    'status' => $fStatus,
    'ms' => $duration,
    'payload' => mb_substr($body, 0, 25000)
]);

// Acknowledge Adyen
http_response_code(200);
header('Content-Type: text/plain');
echo '[accepted]';
