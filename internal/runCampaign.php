<?php

require __DIR__ . "/logger.php";
require __DIR__ . "/config/security.php";
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

/* =========================================================
   REQUIRED INPUT
========================================================= */

$campaignName = trim($data["campaignName"] ?? "");
$MODE         = $data["mode"] ?? null;
$ROUTES       = $data["routes"] ?? null;

$SEEDS        = $data["seeds"] ?? [];
$TOTAL_SEND   = $data["totalSend"] ?? null;
$SEND_SECONDS = $data["sendInSeconds"] ?? null;
$SEND_MIN     = $data["sendInMinutes"] ?? null;
$SEND_HOUR    = $data["sendInHours"] ?? null;

$TRACKING_MODE = $data["trackingMode"] ?? "from";
$TRACK_DOMAIN  = $data["trackingDomain"] ?? null;

$SEED_AFTER = $data["seedAfter"] ?? 0;
$SEED_MODE  = $data["seedMode"] ?? "round";

$CONTENT_MODE  = $data["contentMode"] ?? "multipart";
$TEXT_ENCODING = $data["textEncoding"] ?? "base64";
$HTML_ENCODING = $data["htmlEncoding"] ?? "base64";

$ENVELOPE_MODE           = $data["envelopeMode"] ?? "route";
$ENVELOPE_CUSTOM_TYPE    = $data["envelopeCustomType"] ?? "pattern";
$ENVELOPE_CUSTOM_EMAIL   = $data["envelopeCustomEmail"] ?? null;
$ENVELOPE_CUSTOM_DOMAIN  = $data["envelopeCustomDomain"] ?? null;
$ENVELOPE_PATTERN_BLOCKS = $data["envelopePatternBlocks"] ?? 3;
$ENVELOPE_PATTERN_LENGTH = $data["envelopePatternLength"] ?? 5;

$HEADER_MODE           = $data["headerMode"] ?? "route";
$HEADER_CUSTOM_TYPE    = $data["headerCustomType"] ?? "pattern";
$HEADER_CUSTOM_EMAIL   = $data["headerCustomEmail"] ?? null;
$HEADER_CUSTOM_DOMAIN  = $data["headerCustomDomain"] ?? null;
$HEADER_PATTERN_BLOCKS = $data["headerPatternBlocks"] ?? 3;
$HEADER_PATTERN_LENGTH = $data["headerPatternLength"] ?? 5;

$HEADER_BLOCK_MODE   = $data["headerBlockMode"] ?? "default"; 
$CUSTOM_HEADER_BLOCK = $data["customHeaderBlock"] ?? null;

$DEFAULT_SUBJECT   = $data["subject"] ?? null;
$DEFAULT_FROM_NAME = $data["fromName"] ?? null;
$DBA_EMAIL = $data["dba"] ?? null;
$ALIASES = $data["aliases"] ?? [];
/* =========================================================
   VALIDATION
========================================================= */

if (!$campaignName || !in_array($MODE, ["TEST", "LIVE"], true)) {
    exit(json_encode(["error" => "invalid_mode_or_campaign"]));
}

if (!is_array($ROUTES) || count($ROUTES) === 0) {
    exit(json_encode(["error" => "routes_required"]));
}

if (!empty($ALIASES) && !is_array($ALIASES)) {
    exit(json_encode(["error" => "aliases_must_be_array"]));
}

/* ================= TRACKING VALIDATION ================= */

if (!in_array($TRACKING_MODE, ["from", "domain"], true)) {
    exit(json_encode(["error" => "invalid_tracking_mode"]));
}

if ($TRACKING_MODE === "from") {
    $TRACK_DOMAIN = null;
} else {
    if (!$TRACK_DOMAIN) {
        exit(json_encode(["error" => "tracking_domain_required"]));
    }
}

/* ================= ENCODING VALIDATION ================= */

if (!in_array($CONTENT_MODE, ["html", "multipart"], true)) {
    $CONTENT_MODE = "multipart";
}

if (!in_array($TEXT_ENCODING, ["base64", "quoted-printable", "7bit"], true)) {
    $TEXT_ENCODING = "base64";
}

if (!in_array($HTML_ENCODING, ["base64", "quoted-printable", "7bit"], true)) {
    $HTML_ENCODING = "base64";
}

if (!in_array($ENVELOPE_MODE, ["route", "random", "custom"], true)) {
    $ENVELOPE_MODE = "route";
}

if (!in_array($HEADER_MODE, ["route", "random", "custom"], true)) {
    $HEADER_MODE = "route";
}

/* ================= ENVELOPE DOMAIN VALIDATION ================= */

if (
    $ENVELOPE_MODE === "custom" &&
    $ENVELOPE_CUSTOM_TYPE === "pattern" &&
    empty($ENVELOPE_CUSTOM_DOMAIN)
) {
    exit(json_encode(["error" => "envelope_domain_required"]));
}

/* ================= HEADER DOMAIN VALIDATION ================= */

if (
    $HEADER_MODE === "custom" &&
    $HEADER_CUSTOM_TYPE === "pattern" &&
    empty($HEADER_CUSTOM_DOMAIN)
) {
    exit(json_encode(["error" => "header_domain_required"]));
}

if (
    $ENVELOPE_MODE === "custom" &&
    $ENVELOPE_CUSTOM_TYPE === "fixed" &&
    empty($ENVELOPE_CUSTOM_EMAIL)
) {
    exit(json_encode(["error" => "envelope_email_required"]));
}

if (
    $HEADER_MODE === "custom" &&
    $HEADER_CUSTOM_TYPE === "fixed" &&
    empty($HEADER_CUSTOM_EMAIL)
) {
    exit(json_encode(["error" => "header_email_required"]));
}

if (!in_array($HEADER_BLOCK_MODE, ["default", "custom"], true)) {
    $HEADER_BLOCK_MODE = "default";
}

if ($HEADER_BLOCK_MODE === "custom" && empty($CUSTOM_HEADER_BLOCK)) {
    exit(json_encode(["error" => "custom_header_block_required"]));
}

$DEFAULT_SUBJECT = trim((string)$DEFAULT_SUBJECT);
if ($DEFAULT_SUBJECT === "") {
    $DEFAULT_SUBJECT = "Default Subject";
}

$DEFAULT_FROM_NAME = trim((string)$DEFAULT_FROM_NAME);
if ($DEFAULT_FROM_NAME === "") {
    $DEFAULT_FROM_NAME = "Default Sender";
}




/* ================= SEED MODE VALIDATION ================= */

if (!in_array($SEED_MODE, ["round", "random"], true)) {
    $SEED_MODE = "round";
}

/* ================= MODE VALIDATION ================= */

if ($MODE === "TEST") {

    if (!is_array($SEEDS) || count($SEEDS) === 0) {
        exit(json_encode(["error" => "seeds_required_for_test"]));
    }
}

if ($MODE === "LIVE") {

    if (!is_numeric($TOTAL_SEND) || $TOTAL_SEND <= 0) {
        exit(json_encode(["error" => "invalid_total_send"]));
    }

    if (
        (!is_numeric($SEND_SECONDS) || $SEND_SECONDS <= 0) &&
        (!is_numeric($SEND_MIN) || $SEND_MIN <= 0) &&
        (!is_numeric($SEND_HOUR) || $SEND_HOUR <= 0)
    ) {
        exit(json_encode(["error" => "invalid_send_time"]));
    }


    $TOTAL_SEND = (int)$TOTAL_SEND;
    $SEND_SECONDS = is_numeric($SEND_SECONDS) ? (int)$SEND_SECONDS : null;
    $SEND_MIN     = is_numeric($SEND_MIN) ? (int)$SEND_MIN : null;
    $SEND_HOUR    = is_numeric($SEND_HOUR) ? (int)$SEND_HOUR : null;


    if ($SEED_AFTER) {
        if (!is_numeric($SEED_AFTER) || $SEED_AFTER <= 0) {
            exit(json_encode(["error" => "invalid_seed_after"]));
        }

        if (!is_array($SEEDS) || count($SEEDS) === 0) {
            exit(json_encode(["error" => "seeds_required_for_seed_after"]));
        }

        $SEED_AFTER = (int)$SEED_AFTER;
    }
}

/* =========================================================
   LOAD SEND FILE
========================================================= */

$baseDir  = "/var/www/html/internal/campaigns/$campaignName";
$sendFile = "$baseDir/{$campaignName}_send.php";

if (!is_file($sendFile)) {
    exit(json_encode(["error" => "send_file_missing"]));
}

/* =========================================================
   WRITE RUNTIME HEADER FILES (SL / FL)
========================================================= */

file_put_contents(
    "$baseDir/subject.php",
    "<?php\n\$SUBJECT_LINE = " . var_export($DEFAULT_SUBJECT, true) . ";\n",
    LOCK_EX
);

file_put_contents(
    "$baseDir/from.php",
    "<?php\n\$FROM_NAME = " . var_export($DEFAULT_FROM_NAME, true) . ";\n",
    LOCK_EX
);

/* =========================================================
   SET STATUS RUNNING
========================================================= */

file_put_contents(
    "$baseDir/status.json",
    json_encode([
        "status" => "RUNNING",
        "started_at" => date("Y-m-d H:i:s"),
        "mode" => $MODE
    ])
);

/* =========================================================
   LOG START
========================================================= */

log_line("campaign.log", "CAMPAIGN_TRIGGERED", [
    "campaign" => $campaignName,
    "mode"     => $MODE,
    "pid"      => getmypid()
]);

/* =========================================================
   PREPARE ENV VARS
========================================================= */
file_put_contents(__DIR__."/debug.txt",
    "SUBJECT FINAL: ".$DEFAULT_SUBJECT."\n".
    "FROM FINAL: ".$DEFAULT_FROM_NAME."\n",
    FILE_APPEND
);

$envVars = [
    "CAMPAIGN_NAME"   => $campaignName,
    "MODE"            => $MODE,
    "ROUTES"          => base64_encode(json_encode($ROUTES)),
    "SEEDS"           => base64_encode(json_encode($SEEDS)),
    "TOTAL_SEND"      => $TOTAL_SEND,
    "SEND_IN_SECONDS" => $SEND_SECONDS,
    "SEND_IN_MINUTES" => $SEND_MIN,
    "SEND_IN_HOURS"   => $SEND_HOUR,
    "DBA_EMAIL"       => $DBA_EMAIL,
    "TRACKING_MODE"   => $TRACKING_MODE,
    "TRACK_DOMAIN"    => $TRACK_DOMAIN,
    "SEED_AFTER"      => $SEED_AFTER,
    "SEED_MODE"       => $SEED_MODE,
    "CONTENT_MODE"    => $CONTENT_MODE,
    "TEXT_ENCODING"   => $TEXT_ENCODING,
    "HTML_ENCODING"   => $HTML_ENCODING,

    // 🔥 ADD THESE:
    "ENVELOPE_MODE"           => $ENVELOPE_MODE,
    "ENVELOPE_CUSTOM_TYPE"    => $ENVELOPE_CUSTOM_TYPE,
    "ENVELOPE_CUSTOM_EMAIL"   => $ENVELOPE_CUSTOM_EMAIL,
    "ENVELOPE_CUSTOM_DOMAIN"  => $ENVELOPE_CUSTOM_DOMAIN,
    "ENVELOPE_PATTERN_BLOCKS" => $ENVELOPE_PATTERN_BLOCKS,
    "ENVELOPE_PATTERN_LENGTH" => $ENVELOPE_PATTERN_LENGTH,

    "HEADER_MODE"           => $HEADER_MODE,
    "HEADER_CUSTOM_TYPE"    => $HEADER_CUSTOM_TYPE,
    "HEADER_CUSTOM_EMAIL"   => $HEADER_CUSTOM_EMAIL,
    "HEADER_CUSTOM_DOMAIN"  => $HEADER_CUSTOM_DOMAIN,
    "HEADER_PATTERN_BLOCKS" => $HEADER_PATTERN_BLOCKS,
    "HEADER_PATTERN_LENGTH" => $HEADER_PATTERN_LENGTH,

    "TEST_ROUTES" => $data["testRoutes"] ?? null,
    "TEST_SEEDS" => $data["testSeeds"] ?? null,
    "TEST_TOTAL_SEND" => $data["testTotalSend"] ?? null,

    "HEADER_BLOCK_MODE"   => $HEADER_BLOCK_MODE,
    "CUSTOM_HEADER_BLOCK" => $CUSTOM_HEADER_BLOCK ? base64_encode($CUSTOM_HEADER_BLOCK) : null,

    "FINAL_DATA_FILE_URL" => $data["finalDataFileUrl"] ?? null,
    "ALIASES" => !empty($ALIASES) ? base64_encode(json_encode($ALIASES)) : null,
];


/* =========================================================
   BUILD SAFE ENV STRING
========================================================= */

$env = "";

foreach ($envVars as $key => $value) {
    if ($value === null || $value === "") continue;
    $safeValue = escapeshellarg((string)$value);
    $env .= "$key=$safeValue ";
}

/* =========================================================
   EXECUTE IN BACKGROUND
========================================================= */

$cmd = $env . "nohup php " . escapeshellarg($sendFile) .
       " > $baseDir/run_output.log 2>&1 & echo $!";

exec($cmd, $output, $returnCode);

$pid = isset($output[0]) ? (int)$output[0] : null;

/* =========================================================
   🔥 START TOKEN WORKER (ADD HERE)
========================================================= */


file_put_contents(__DIR__."/exec_debug.txt",
    "CMD:\n".$cmd."\n\nRETURN:\n".$returnCode."\n\nOUTPUT:\n".print_r($output,true),
    FILE_APPEND
);


/* =========================================================
   RESPONSE
========================================================= */

echo json_encode([
    "status"   => "started",
    "campaign" => $campaignName,
    "mode"     => $MODE
]);

exit;

