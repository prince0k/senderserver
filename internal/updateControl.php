<?php
require __DIR__ . "/config/security.php";
header("Content-Type: application/json");

/* =====================
   SECURITY
===================== */

$INTERNAL_KEY = getInternalKey();

if (
    empty($_SERVER["HTTP_X_INTERNAL_KEY"]) ||
    !hash_equals($INTERNAL_KEY, $_SERVER["HTTP_X_INTERNAL_KEY"])
) {
    http_response_code(403);
    exit(json_encode(["error" => "forbidden"]));
}

/* =====================
   INPUT
===================== */

$data = json_decode(file_get_contents("php://input"), true);

$campaignName = trim($data["campaignName"] ?? "");
$action       = trim($data["action"] ?? "");

if (!$campaignName || !$action) {
    exit(json_encode(["error" => "invalid_input"]));
}

/* =====================
   VALIDATE ACTION
===================== */

$allowed = [
    "PAUSE"  => "PAUSED",
    "RESUME" => "RUNNING",
    "STOP"   => "STOPPED",
];

if (!isset($allowed[$action])) {
    exit(json_encode(["error" => "invalid_action"]));
}

$baseDir = "/var/www/html/internal/campaigns/$campaignName";

if (!is_dir($baseDir)) {
    exit(json_encode(["error" => "campaign_not_found"]));
}

/* =====================
   WRITE CONTROL FILE
===================== */

$controlFile = "$baseDir/control.json";

$result = file_put_contents(
    $controlFile,
    json_encode([
        "status"     => $allowed[$action],
        "updated_at" => date("Y-m-d H:i:s")
    ]),
    LOCK_EX
);

if ($result === false) {
    http_response_code(500);
    exit(json_encode(["error" => "write_failed"]));
}
/* =====================
   WRITE RUNTIME CONFIG (ONLY ON RESUME)
===================== */

if ($action === "RESUME") {

    $runtimeConfigFile = "$baseDir/runtime_config.json";

    $existing = [];

    if (file_exists($runtimeConfigFile)) {
        $existing = json_decode(file_get_contents($runtimeConfigFile), true) ?: [];
    }

    $newConfig = [
        "totalSend" => isset($data["totalSend"])
            ? (int)$data["totalSend"]
            : ($existing["totalSend"] ?? null),

        "sendInMinutes" => isset($data["sendInMinutes"])
            ? (int)$data["sendInMinutes"]
            : ($existing["sendInMinutes"] ?? null),

        "sendInSeconds" => isset($data["sendInSeconds"])
            ? (int)$data["sendInSeconds"]
            : ($existing["sendInSeconds"] ?? null),

        "updated_at" => date("Y-m-d H:i:s")
    ];

    file_put_contents(
        $runtimeConfigFile,
        json_encode($newConfig),
        LOCK_EX
    );
}

echo json_encode([
    "status" => "updated",
    "new_state" => $allowed[$action]
]);

