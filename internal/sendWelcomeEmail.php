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

/* =========================================================
   BUILD MIME MESSAGE
========================================================= */

$fromDomain = explode("@", $fromEmail)[1] ?? "localhost";
$boundary   = "----=_WelcomePart_" . md5(uniqid(mt_rand(), true));
$messageId  = "<w-" . rand_welcome_str(8) . "-" . md5($to) . "@$fromDomain>";
$date       = date("r");

// Plain text fallback
$textContent = strip_tags(
    str_replace(
        ["<br>", "<br/>", "<br />", "</p>", "</h1>", "</h2>", "</h3>", "</li>"],
        "\n",
        $html
    )
);
$textContent = html_entity_decode($textContent, ENT_QUOTES, "UTF-8");
$textContent = preg_replace("/\n{3,}/", "\n\n", trim($textContent));

// Encoded parts
$textB64 = chunk_split(base64_encode($textContent));
$htmlB64 = chunk_split(base64_encode($html));

$body  = "--$boundary\r\n";
$body .= "Content-Type: text/plain; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: base64\r\n\r\n";
$body .= $textB64 . "\r\n";
$body .= "--$boundary\r\n";
$body .= "Content-Type: text/html; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: base64\r\n\r\n";
$body .= $htmlB64 . "\r\n";
$body .= "--$boundary--\r\n";

/* =========================================================
   SMTP HEADERS
========================================================= */

$headerBlock  = "Date: $date\r\n";
$headerBlock .= "From: $fromName <$fromEmail>\r\n";
$headerBlock .= "To: " . ($toName ? "$toName <$to>" : "<$to>") . "\r\n";
$headerBlock .= "Subject: $subject\r\n";
$headerBlock .= "Message-ID: $messageId\r\n";
$headerBlock .= "MIME-Version: 1.0\r\n";
$headerBlock .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
$headerBlock .= "X-Mailer: NutriGuide-Welcome/1.0\r\n";
$headerBlock .= "List-Unsubscribe: <mailto:unsubscribe@$fromDomain>\r\n";

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

// EHLO
$ehloHost = $vmta ?: $fromDomain;
fwrite($smtp, "EHLO $ehloHost\r\n");
$ehloResp = smtp_read($smtp);

// MAIL FROM (with VMTA if specified)
$mailFromCmd = "MAIL FROM:<$envelopeFrom>";
if ($vmta) {
    $mailFromCmd .= " VMTA=$vmta";
}
$mailFromCmd .= "\r\n";

fwrite($smtp, $mailFromCmd);
$mailResp = smtp_read($smtp);

if (smtp_code($mailResp) !== 250) {
    fwrite($smtp, "QUIT\r\n");
    fclose($smtp);
    http_response_code(500);
    exit(json_encode(["error" => "smtp_mail_from_rejected", "resp" => trim($mailResp)]));
}

// RCPT TO
fwrite($smtp, "RCPT TO:<$to>\r\n");
$rcptResp = smtp_read($smtp);

if (smtp_code($rcptResp) !== 250) {
    fwrite($smtp, "QUIT\r\n");
    fclose($smtp);
    http_response_code(500);
    exit(json_encode(["error" => "smtp_rcpt_rejected", "resp" => trim($rcptResp)]));
}

// DATA
fwrite($smtp, "DATA\r\n");
$dataResp = smtp_read($smtp);

if (smtp_code($dataResp) !== 354) {
    fwrite($smtp, "QUIT\r\n");
    fclose($smtp);
    http_response_code(500);
    exit(json_encode(["error" => "smtp_data_rejected"]));
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
