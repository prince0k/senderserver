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
    log_line("campaign.log", "CREATE_CAMPAIGN_FORBIDDEN");
    exit(json_encode(["error" => "forbidden"]));
}

/* =========================================================
   INPUT
========================================================= */

$payload = json_decode(file_get_contents("php://input"), true);

if (!is_array($payload)) {
    http_response_code(400);
    log_line("campaign.log", "CREATE_CAMPAIGN_INVALID_JSON");
    exit(json_encode(["error" => "invalid_json"]));
}

$dbaEmail = isset($payload["dba"]) ? trim($payload["dba"]) : "";
/* =========================================================
   HELPERS
========================================================= */

function safe_name($value) {
    $value = strtolower(trim($value));
    $value = preg_replace("/[^a-zA-Z0-9_-]/", "_", $value);
    return substr($value, 0, 120);
}

function fail($msg, $ctx = []) {
    log_line("campaign.log", "CREATE_CAMPAIGN_FAIL", [
        "error" => $msg,
        "ctx"   => $ctx
    ]);
    http_response_code(400);
    exit(json_encode(["error" => $msg]));
}

/* =========================================================
   REQUIRED FIELDS
========================================================= */

$campaign = safe_name($payload["campaignName"] ?? "");
$creative = safe_name($payload["creativeName"] ?? "");
$offerId  = safe_name($payload["offerId"] ?? "");

$creativeHtml     = $payload["creativeHtml"] ?? null;
$finalDataFileUrl = $payload["finalDataFileUrl"] ?? null;
$createOnly       = !empty($payload["createOnly"]);

if (!$campaign || !$creative || !$offerId) {
    fail("missing_required_fields", compact("campaign","creative","offerId"));
}

if (!$creativeHtml || trim($creativeHtml) === "") {
    fail("creative_html_required");
}

/* Validate data URL only if LIVE mode */
if (!$createOnly) {
    if (!$finalDataFileUrl || !filter_var($finalDataFileUrl, FILTER_VALIDATE_URL)) {
        fail("invalid_data_file_url");
    }
}

/* =========================================================
   BASE DIR
========================================================= */

$baseDir = __DIR__ . "/campaigns/$campaign";

if (!is_dir($baseDir)) {
    if (is_dir($baseDir)) {
        fail("campaign_already_exists", ["campaign"=>$campaign]);
    }
    if (!mkdir($baseDir, 0775, true)) {
        fail("campaign_dir_create_failed", ["path"=>$baseDir]);
    }
}

/* =========================================================
   CREATIVE FILE
========================================================= */

function writeCreative($path, $html) {

    $php  = "<?php\n";
    $php .= "\$CREATIVE_HTML = <<<'HTML'\n";
    $php .= $html . "\n";
    $php .= "HTML;\n";

    if (file_put_contents($path, $php) === false) {
        fail("creative_write_failed", ["path"=>$path]);
    }
}

$activeCreative   = "$baseDir/creative_offer.php";
$originalCreative = "$baseDir/creative_original.php";

writeCreative($activeCreative, $creativeHtml);

if (!file_exists($originalCreative)) {
    writeCreative($originalCreative, $creativeHtml);
}

/* =========================================================
   OFFER CONFIG
========================================================= */

$offerConfigContent = "<?php\n\$OFFER_ID = '" . addslashes($offerId) . "';\n";

if (file_put_contents("$baseDir/offer_config.php", $offerConfigContent) === false) {
    fail("offer_config_write_failed");
}

/* =========================================================
   DBA CONFIG
========================================================= */

$dbaConfig = "<?php\n\$DBA_EMAIL = '" . addslashes($dbaEmail) . "';\n";

if (file_put_contents("$baseDir/dba_config.php", $dbaConfig) === false) {
    fail("dba_config_write_failed");
}

/* =========================================================
   COPY SEND TEMPLATE
========================================================= */

$template = __DIR__ . "/send_template.php";
$sendFile = "$baseDir/{$campaign}_send.php";

if (!is_file($template)) {
    fail("send_template_missing", ["template"=>$template]);
}

if (!copy($template, $sendFile)) {
    fail("send_file_copy_failed");
}

/* =========================================================
   INITIAL STATUS FILE
========================================================= */

file_put_contents(
    "$baseDir/status.json",
    json_encode([
        "status" => "CREATED",
        "created_at" => date("Y-m-d H:i:s")
    ])
);

/* =========================================================
   SUCCESS RESPONSE
========================================================= */

$files = [
    basename($sendFile),
    "creative_offer.php",
    "offer_config.php"
];

if (!$createOnly) {
    $files[] = "data.txt";
}

log_line("campaign.log", "CREATE_CAMPAIGN_SUCCESS", [
    "campaign" => $campaign,
    "createOnly" => $createOnly,
    "dba" => $dbaEmail
]);

echo json_encode([
    "status"   => "ok",
    "campaign" => $campaign,
    "path"     => $baseDir,
    "files"    => $files
]);

exit;
