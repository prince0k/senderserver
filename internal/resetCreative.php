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

$campaign = $data["campaignName"] ?? null;

if (!$campaign) {
    exit(json_encode(["error" => "campaign_required"]));
}

$baseDir = "/var/www/html/internal/campaigns/$campaign";

$active = "$baseDir/creative_offer.php";
$original = "$baseDir/creative_original.php";

if (!file_exists($original)) {
    exit(json_encode(["error" => "original_missing"]));
}

copy($original, $active);

echo json_encode(["status" => "reset_done"]);
