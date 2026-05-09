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
$html = $data["html"] ?? null;

if (!$campaign || !$html) {
    exit(json_encode(["error" => "invalid_input"]));
}

$baseDir = "/var/www/html/internal/campaigns/$campaign";
$active = "$baseDir/creative_offer.php";

if (!is_dir($baseDir)) {
    exit(json_encode(["error" => "campaign_missing"]));
}

$php =
"<?php\n" .
"\$CREATIVE_HTML = <<<'HTML'\n" .
$html .
"\nHTML;\n";

file_put_contents($active, $php);

echo json_encode(["status" => "updated"]);
