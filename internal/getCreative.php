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

if (!$campaign) {
    exit(json_encode(["error" => "campaign_required"]));
}

$baseDir = "/var/www/html/internal/campaigns/$campaign";

$active = "$baseDir/creative_offer.php";
$original = "$baseDir/creative_original.php";

if (!file_exists($active)) {
    exit(json_encode(["error" => "creative_missing"]));
}

function extractHtml($file) {
    $content = file_get_contents($file);
    preg_match("/HTML'\n([\s\S]*?)\nHTML;/", $content, $matches);
    return $matches[1] ?? "";
}

echo json_encode([
    "activeHtml" => extractHtml($active),
    "originalHtml" => file_exists($original)
        ? extractHtml($original)
        : extractHtml($active)
]);
