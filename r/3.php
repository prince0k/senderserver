<?php

$TRACK_SERVER = "http://localhost:3001";

/* =========================
   TOKEN VALIDATION
========================= */

$token = trim($_GET['k'] ?? '');
if (!$token) {
    http_response_code(400);
    exit;
}

function isOldToken($t) {
    return preg_match('/^[a-f0-9]{64}$/i', $t);
}

function isNewToken($t) {
    return strlen($t) > 20 && preg_match('/^[A-Za-z0-9\-_]+$/', $t);
}

if (!isOldToken($token) && !isNewToken($token)) {
    http_response_code(400);
    exit;
}

/* =========================
   CLIENT META
========================= */

if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
} else {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

$userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
$referer   = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 200);

/* =========================
   BUILD URL
========================= */

$query = http_build_query([
    "k"   => $token,
    "ip"  => $ip,
    "ua"  => $userAgent,
    "ref" => $referer,
    "ts"  => time()
]);

$url = $TRACK_SERVER . "/t/optout?" . $query;

/* =========================
   REDIRECT
========================= */

header("Location: $url", true, 302);
exit;
