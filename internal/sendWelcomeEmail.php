<?php

require __DIR__ . "/config/security.php";
require __DIR__ . "/logger.php";

header("Content-Type: application/json");

ini_set("display_errors", 0);
ini_set("log_errors", 1);
ini_set("error_log", "/var/www/html/sender_logs/php_errors.log");
error_reporting(E_ALL);

/* =========================================================
   SECURITY
========================================================= */

$INTERNAL_KEY = getInternalKey();

if (
    empty($_SERVER["HTTP_X_INTERNAL_KEY"]) ||
    !hash_equals($INTERNAL_KEY, $_SERVER["HTTP_X_INTERNAL_KEY"])
) {
    http_response_code(403);
    exit(json_encode(["error" => "forbidden"]));
}

/* =========================================================
   INPUT
========================================================= */

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    exit(json_encode(["error" => "invalid_json"]));
}

$to         = trim($data["to"] ?? "");
$toName     = trim($data["toName"] ?? "");
$fromEmail  = trim($data["fromEmail"] ?? "");
$fromName   = trim($data["fromName"] ?? "NutriGuide");
$subject    = trim($data["subject"] ?? "Welcome!");
$html       = $data["html"] ?? "";
$vmta       = trim($data["vmta"] ?? "");

/* =========================================================
   VALIDATION
========================================================= */

if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit(json_encode(["error" => "invalid_recipient"]));
}

if (!$fromEmail || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit(json_encode(["error" => "invalid_from_email"]));
}

if (!$html || strlen(trim($html)) < 10) {
    http_response_code(400);
    exit(json_encode(["error" => "html_required"]));
}

/* =========================================================
   HELPERS
========================================================= */

function rand_welcome_str($len) {
    $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
    $out = "";
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

function encrypt_token($payload){
    $key = "9f3a8c7d6e5b4a3c2d1e0f9a8b7c6d5e"; // 32 bytes exact
    if (strlen($key) !== 32) return null;
    $iv = random_bytes(16);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $encrypted = openssl_encrypt($json, "AES-256-CBC", $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) return null;
    $final = $iv . $encrypted;
    return rtrim(strtr(base64_encode($final), '+/', '-_'), '=');
}

function track($type, $email, $rl=null, $list=null, $route=null){
    $payload = [
        "type" => $type,
        "offer_id" => "WELCOME_TRIGGER", // Static ID for triggers
        "email" => $email,
        "rl" => $rl,
        "list_id" => $list,
        "send_domain" => $route["domain"] ?? null,
        "vmta" => $route["vmta"] ?? null
    ];
    return encrypt_token($payload);
}


/* =========================================================
   BUILD MIME MESSAGE
========================================================= */

$fromDomain = explode("@", $fromEmail)[1] ?? "localhost";

/* =========================================================
   UNSUBSCRIBE LINK
 ========================================================= */

// Resolve core tracking links
$trackBase = "https://" . $fromDomain;
$boundary   = "----=_WelcomePart_" . md5(uniqid(mt_rand(), true));
$messageId  = "<w-" . rand_welcome_str(8) . "-" . md5($to) . "@$fromDomain>";
$date       = date("r");
$redirection1 = $trackBase . "/r/2.php?k=" . rawurlencode(track("click", $to, 1, null, ["domain" => $fromDomain, "vmta" => $vmta]));
$redirection2 = $trackBase . "/r/2.php?k=" . rawurlencode(track("click", $to, 2, null, ["domain" => $fromDomain, "vmta" => $vmta]));
$optout = $trackBase . "/r/3.php?k=" . rawurlencode(track("optout", $to, null, null, ["domain" => $fromDomain, "vmta" => $vmta]));
$uns = $trackBase . "/r/4.php?k=" . rawurlencode(track("unsub", $to, null, null, ["domain" => $fromDomain, "vmta" => $vmta]));
$impression = $trackBase . "/r/1.php?k=" . rawurlencode(track("open", $to, null, null, ["domain" => $fromDomain, "vmta" => $vmta]));
$listUnsubUrl = $uns; // Consistency

$search = ["{redirection1}", "{redirection2}", "{optout}", "{uns}", "{impression}", "{{IMAGE_HOST}}", "{dba}"];
$replace = [$redirection1, $redirection2, $optout, $uns, $impression, $trackBase, "Support"];

$htmlResolved = str_replace($search, $replace, $html);

// Convert HTML to Text with link preservation
$textContent = preg_replace('/<a\s+href="([^"]+)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $htmlResolved);
$textContent = str_replace(["<br>", "<br/>", "<br />", "</p>", "</h1>", "</h2>", "</h3>", "</li>"], "\n", $textContent);
$textContent = strip_tags($textContent);
$textContent = html_entity_decode($textContent, ENT_QUOTES, "UTF-8");
$textContent = preg_replace("/\n{3,}/", "\n\n", trim($textContent));

// Add Unsubscribe footer
$htmlWithUnsub = $htmlResolved . '<br><br><p style="font-size: 11px; color: #999; text-align: center;">You received this because you signed up on our site. <a href="' . $listUnsubUrl . '">Unsubscribe</a></p>';
$textWithUnsub = $textContent . "\n\nUnsubscribe: " . $listUnsubUrl;

$textEncoding = $data["textEncoding"] ?? "base64";
$htmlEncoding = $data["htmlEncoding"] ?? "base64";

function encode_part($content, $encoding) {
    if ($encoding === "quoted-printable") {
        return quoted_printable_encode($content);
    } elseif ($encoding === "base64") {
        return chunk_split(base64_encode($content));
    } elseif ($encoding === "8bit") {
        return $content; // Raw 8-bit
    } else {
        return $content; // Fallback to 7bit/8bit
    }
}

$encodedText = encode_part($textWithUnsub, $textEncoding);
$encodedHtml = encode_part($htmlWithUnsub, $htmlEncoding);

$body  = "--$boundary\r\n";
$body .= "Content-Type: text/plain; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: $textEncoding\r\n\r\n";
$body .= $encodedText . "\r\n";
$body .= "--$boundary\r\n";
$body .= "Content-Type: text/html; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: $htmlEncoding\r\n\r\n";
$body .= $encodedHtml . "\r\n";
$body .= "--$boundary--\r\n";


/* =========================================================
   SMTP HEADERS
========================================================= */

$date = date("r");
$customHeaders = $data["customHeaders"] ?? "";

if (!empty($customHeaders)) {
    $headerBlock = str_replace(
        ["{date}", "{fromName}", "{fromEmail}", "{to}", "{subject}", "{mid}", "{vmta}", "{boundary}", "{listUnsubUrl}", "{replyTo}"],
        [$date, $fromName, $fromEmail, $to, $subject, $messageId, $vmta, $boundary, $listUnsubUrl, $fromEmail],
        $customHeaders
    );
    // Ensure CRLF
    $headerBlock = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $headerBlock));
} else {
    $headerBlock  = "Date: $date\r\n";
    $headerBlock .= "From: $fromName <$fromEmail>\r\n";
    $headerBlock .= "To: " . ($toName ? "$toName <$to>" : "<$to>") . "\r\n";
    $headerBlock .= "Subject: $subject\r\n";
    $headerBlock .= "Message-ID: $messageId\r\n";
    $headerBlock .= "MIME-Version: 1.0\r\n";
    $headerBlock .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    $headerBlock .= "X-virtual-MTA: $vmta\r\n";
    $headerBlock .= "List-Unsubscribe: <$listUnsubUrl>\r\n";
    $headerBlock .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
    $headerBlock .= "X-Mailer: NutriGuide-Welcome/1.0\r\n";
}

$fullMessage = $headerBlock . "\r\n" . $body;

/* =========================================================
   INJECT VIA PMTA (LOCAL SMTP :2525)
========================================================= */

$envelopeFrom = $fromEmail;

$smtp = @fsockopen("127.0.0.1", 2525, $errno, $errstr, 5);

if (!$smtp) {
    log_line("welcome.log", "SMTP_CONNECT_FAIL", [
        "to"    => $to,
        "error" => "$errno: $errstr"
    ]);
    http_response_code(500);
    exit(json_encode(["error" => "smtp_connect_failed", "detail" => $errstr]));
}

/* ---- SMTP conversation ---- */

function smtp_read($sock) {
    $resp = "";
    while ($line = fgets($sock, 512)) {
        $resp .= $line;
        if (substr($line, 3, 1) === " ") break;
    }
    return $resp;
}

function smtp_code($resp) {
    return (int)substr($resp, 0, 3);
}

$greeting = smtp_read($smtp);

if (smtp_code($greeting) !== 220) {
    fclose($smtp);
    http_response_code(500);
    exit(json_encode(["error" => "smtp_greeting_failed"]));
}

// HELO
fputs($smtp, "HELO localhost\r\n");
list($ok, $resp) = [(int)substr($r = smtp_read($smtp), 0, 3) === 250, $r];
if (!$ok) {
    fclose($smtp);
    http_response_code(500);
    exit(json_encode(["error" => "smtp_helo_rejected", "resp" => trim($resp)]));
}

// MAIL FROM
fputs($smtp, "MAIL FROM:<$envelopeFrom>\r\n");
list($ok, $resp) = [(int)substr($r = smtp_read($smtp), 0, 3) === 250, $r];
if (!$ok) {
    fclose($smtp);
    http_response_code(500);
    exit(json_encode(["error" => "smtp_mail_from_rejected", "resp" => trim($resp)]));
}

// RCPT TO
fputs($smtp, "RCPT TO:<$to>\r\n");
list($ok, $resp) = [(int)substr($r = smtp_read($smtp), 0, 3) === 250, $r];
if (!$ok) {
    fclose($smtp);
    http_response_code(500);
    exit(json_encode(["error" => "smtp_rcpt_rejected", "resp" => trim($resp)]));
}

// DATA
fputs($smtp, "DATA\r\n");
list($ok, $resp) = [(int)substr($r = smtp_read($smtp), 0, 3) === 354, $r];
if (!$ok) {
    fclose($smtp);
    http_response_code(500);
    exit(json_encode(["error" => "smtp_data_rejected", "resp" => trim($resp)]));
}

// Send message body (dot-stuffing)
$lines = explode("\n", str_replace("\r\n", "\n", $fullMessage));
foreach ($lines as $line) {
    if (substr($line, 0, 1) === ".") {
        $line = "." . $line;
    }
    fwrite($smtp, $line . "\r\n");
}

// End data
fwrite($smtp, ".\r\n");
$endResp = smtp_read($smtp);

if (smtp_code($endResp) !== 250) {
    fwrite($smtp, "QUIT\r\n");
    fclose($smtp);
    http_response_code(500);
    exit(json_encode(["error" => "smtp_send_failed", "resp" => trim($endResp)]));
}

// QUIT
fwrite($smtp, "QUIT\r\n");
fclose($smtp);

/* =========================================================
   LOG + RESPONSE
========================================================= */

log_line("welcome.log", "WELCOME_SENT", [
    "to"        => $to,
    "from"      => $fromEmail,
    "vmta"      => $vmta,
    "messageId" => $messageId
]);

echo json_encode([
    "status"    => "sent",
    "to"        => $to,
    "messageId" => $messageId,
    "timestamp" => date("Y-m-d H:i:s")
]);
