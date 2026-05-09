<?php
require __DIR__ . "/config/security.php";
header("Content-Type: application/json");

/* ================= SECURITY ================= */

$INTERNAL_KEY = getInternalKey();

if (
    empty($_SERVER["HTTP_X_INTERNAL_KEY"]) ||
    !hash_equals($INTERNAL_KEY, $_SERVER["HTTP_X_INTERNAL_KEY"])
) {
    http_response_code(403);
    exit(json_encode(["error" => "forbidden"]));
}

/* ================= INPUT ================= */

$input = json_decode(file_get_contents("php://input"), true);

$action = $input["action"] ?? "";
$target = $input["target"] ?? "";      
$sourceIp = $input["source_ip"] ?? "";

/* ================= SAFE HELPERS ================= */

function validateTarget($target) {
    return preg_match('/^(\*|[a-zA-Z0-9\.\-]+)\/(\*|[0-9\.]+)$/', $target);
}

function safe($val) {
    return escapeshellarg($val);
}

/* ================= COMMAND ================= */

$command = "";

/* ---------- QUEUE COMMANDS ---------- */

if (in_array($action, [
    "enable_source",
    "pause_queue",
    "resume_queue",
    "delete_queue"
])) {

    if (!validateTarget($target)) {
        http_response_code(400);
        exit(json_encode(["error" => "invalid_target"]));
    }

    $safeTarget = safe($target);

    switch ($action) {

        case "enable_source":
            if (!filter_var($sourceIp, FILTER_VALIDATE_IP)) {
                http_response_code(400);
                exit(json_encode(["error" => "invalid_ip"]));
            }
            $safeIp = safe($sourceIp);
            $command = "/usr/bin/sudo /usr/sbin/pmta enable source $safeIp $safeTarget";
            break;

        case "pause_queue":
            $command = "/usr/bin/sudo /usr/sbin/pmta pause queue $safeTarget";
            break;

        case "resume_queue":
            $command = "/usr/bin/sudo /usr/sbin/pmta resume queue $safeTarget";
            break;

        case "delete_queue":
            $command = "/usr/bin/sudo /usr/sbin/pmta delete -queue=$safeTarget";
            break;
    }
}

/* ---------- SERVER COMMANDS ---------- */

else if (in_array($action, [
    "reload",
    "restart",
    "reset_counters",
    "status"
])) {

    switch ($action) {

        case "reload":
            $command = "/usr/bin/sudo /usr/sbin/pmta reload";
            break;

        case "restart":
            $command = "/usr/bin/sudo /bin/systemctl restart pmta.service && echo SUCCESS || echo FAIL";
            break;

        case "reset_counters":
            $command = "/usr/bin/sudo /usr/sbin/pmta reset counters";
            break;

        case "status":
            $command = "/usr/bin/sudo /usr/sbin/pmta show status";
            break;
    }
}

/* ---------- INVALID ---------- */

else {
    http_response_code(400);
    exit(json_encode(["error" => "invalid_action"]));
}

/* ================= EXECUTE ================= */

$output = [];
$returnCode = 0;

exec($command . " 2>&1", $output, $returnCode);

/* ================= RESPONSE ================= */

echo json_encode([
    "action" => $action,
    "command" => $command,
    "output" => $output,
    "status" => $returnCode === 0 ? "success" : "failed"
], JSON_PRETTY_PRINT);
