<?php
require __DIR__ . "/config/security.php";
header("Content-Type: application/json");

$INTERNAL_KEY = getInternalKey();

if (
    empty($_SERVER["HTTP_X_INTERNAL_KEY"]) ||
    !hash_equals($INTERNAL_KEY, $_SERVER["HTTP_X_INTERNAL_KEY"])
) {
    http_response_code(403);
    exit(json_encode(["error" => "forbidden"]));
}

$data = json_decode(file_get_contents("php://input"), true);

function safe_name($value) {
    $value = strtolower(trim($value));
    $value = preg_replace("/[^a-zA-Z0-9_-]/", "_", $value);
    return substr($value, 0, 120);
}

$campaign = safe_name($data["campaignName"] ?? "");
$subject  = isset($data["subject"]) ? trim($data["subject"]) : null;
$fromName = isset($data["fromName"]) ? trim($data["fromName"]) : null;

if (!$campaign) {
    exit(json_encode(["error" => "invalid_campaign"]));
}

$baseDir = "/var/www/html/internal/campaigns/$campaign";

if (!is_dir($baseDir)) {
    exit(json_encode(["error" => "campaign_missing"]));
}

/* ================= SUBJECT UPDATE ================= */

if ($subject !== null) {

    $subjectFile = "$baseDir/subject.php";

    $php =
"<?php\n" .
"\$SUBJECT_LINE = " . var_export($subject, true) . ";\n";

    file_put_contents($subjectFile, $php, LOCK_EX);
}

/* ================= FROM UPDATE ================= */

if ($fromName !== null) {

    $fromFile = "$baseDir/from.php";

    $php =
"<?php\n" .
"\$FROM_NAME = " . var_export($fromName, true) . ";\n";

    file_put_contents($fromFile, $php, LOCK_EX);
}

file_put_contents(
    "$baseDir/header_update.log",
    date("Y-m-d H:i:s") .
    " SUBJECT: $subject | FROM: $fromName\n",
    FILE_APPEND
);

echo json_encode([
  "status" => "updated"
]);
