<?php

$TRACK_SERVER = "http://localhost:3001";

/* =========================================================
   VALIDATE TOKEN
========================================================= */

if (empty($_GET['k'])) {
    http_response_code(400);
    exit;
}

// 🔥 DO NOT MODIFY TOKEN (critical)
$token = $_GET['k'];

// OLD token (64 hex)
function isOldToken($t) {
    return preg_match('/^[a-f0-9]{64}$/i', $t);
}

// basic sanity check
if (!isOldToken($token) && strlen($token) < 20) {
    http_response_code(400);
    exit;
}

/* =========================================================
   CAPTURE CLIENT DATA
========================================================= */

// Real IP (Cloudflare supported)
$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? 'unknown';

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referer   = $_SERVER['HTTP_REFERER'] ?? '';
$acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

/* =========================================================
   BASIC BOT DETECTION
========================================================= */

$isBot = false;

if (
    stripos($userAgent, 'Googlebot') !== false ||
    stripos($userAgent, 'bingbot') !== false ||
    stripos($userAgent, 'Yahoo') !== false
) {
    $isBot = true;
}

/* =========================================================
   BUILD TRACK URL (SAFE)
========================================================= */

// 🔥 token untouched, baaki encode
$url = $TRACK_SERVER . "/t/click?"
    . "k=" . $token
    . "&ip=" . urlencode($ip)
    . "&ua=" . urlencode(substr($userAgent, 0, 200))
    . "&ref=" . urlencode(substr($referer, 0, 200))
    . "&bot=" . ($isBot ? 1 : 0)
    . "&ts=" . time();

/* =========================================================
   REDIRECT USER (FAST)
========================================================= */

header("Location: " . $url, true, 302);
exit;
