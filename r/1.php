<?php

$TRACK_SERVER = "http://localhost:3001";

/* =========================================================
   FAST PIXEL RESPONSE (1x1 GIF)
========================================================= */

header("Content-Type: image/gif");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

echo base64_decode(
    "R0lGODlhAQABAIABAP///wAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="
);

/* =========================================================
   DETACH CLIENT CONNECTION
========================================================= */

ignore_user_abort(true);
set_time_limit(2);

if (function_exists("fastcgi_finish_request")) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    @flush();
}

/* =========================================================
   TOKEN VALIDATION
========================================================= */

if (empty($_GET["k"])) {
    exit;
}

$token = trim($_GET["k"]);

// OLD token (64 hex)
function isOldToken($t) {
    return preg_match('/^[a-f0-9]{64}$/i', $t);
}

// NEW token (AES base64url)
function isNewToken($t) {
    return strlen($t) > 64;
}

if (!isOldToken($token) && !isNewToken($token)) {
    exit;
}

/* =========================================================
   CAPTURE CLIENT DATA
========================================================= */

$ip =
    $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? 'unknown';

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

/* =========================================================
   BASIC BOT DETECTION
========================================================= */

$isBot = false;

if (
    stripos($userAgent, 'GoogleImageProxy') !== false ||
    stripos($userAgent, 'Googlebot') !== false ||
    stripos($userAgent, 'Yahoo') !== false ||
    stripos($userAgent, 'bingbot') !== false
) {
    $isBot = true;
}

if (
    stripos($userAgent, 'AppleWebKit') !== false &&
    empty($acceptLang)
) {
    $isBot = true;
}

/* =========================================================
   BUILD TRACK URL
========================================================= */

$query = http_build_query([
    "k"   => $token,
    "ip"  => $ip,
    "ua"  => $userAgent,
    "ref" => $referer,
    "bot" => $isBot ? 1 : 0,
    "ts"  => time()
]);

$url = $TRACK_SERVER . "/t/open?" . $query;

/* =========================================================
   FIRE & FORGET REQUEST
========================================================= */

$parts = parse_url($url);

$scheme = $parts['scheme'] ?? 'https';
$host = $parts['host'] ?? '';
$path = $parts['path'] ?? '/';
$query = $parts['query'] ?? '';

$port = ($scheme === 'https') ? 443 : 80;

$fp = @fsockopen(
    ($scheme === 'https' ? "ssl://" : "") . $host,
    $port,
    $errno,
    $errstr,
    1
);

if ($fp) {
    fwrite($fp, "GET $path?$query HTTP/1.1\r\n");
    fwrite($fp, "Host: $host\r\n");
    fwrite($fp, "Connection: Close\r\n\r\n");
    fclose($fp);
}

exit;
