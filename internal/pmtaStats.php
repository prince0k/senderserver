<?php
require __DIR__ . "/config/security.php";
header("Content-Type: application/json");

/* ===================== SECURITY ===================== */

$INTERNAL_KEY = getInternalKey();

if (
    empty($_SERVER["HTTP_X_INTERNAL_KEY"]) ||
    !hash_equals($INTERNAL_KEY, $_SERVER["HTTP_X_INTERNAL_KEY"])
) {
    http_response_code(403);
    exit(json_encode(["error" => "forbidden"]));
}

/* ===================== READ FILES ===================== */

$statusRaw = file_exists("/var/www/pmta_data/pmta_status.txt")
    ? file_get_contents("/var/www/pmta_data/pmta_status.txt")
    : "";

$queueRaw = file_exists("/var/www/pmta_data/pmta_queue.txt")
    ? file_get_contents("/var/www/pmta_data/pmta_queue.txt")
    : "";

$domainRaw = file_exists("/var/www/pmta_data/pmta_domains.txt")
    ? file_get_contents("/var/www/pmta_data/pmta_domains.txt")
    : "";

/* ===================== EMPTY CHECK ===================== */

if (!$statusRaw || strlen(trim($statusRaw)) === 0) {
    echo json_encode(["error" => "pmta_file_empty"]);
    exit;
}

/* ===================== PARSE STATUS ===================== */

preg_match("/Status\s+(running|stopped)/i", $statusRaw, $statusMatch);
$status = isset($statusMatch[1]) ? strtolower($statusMatch[1]) : "unknown";

preg_match("/Uptime\s+([0-9]+\s+[0-9:]+)/i", $statusRaw, $uptimeMatch);
$uptime = isset($uptimeMatch[1]) ? trim($uptimeMatch[1]) : "";

/* ===================== PARSE TRAFFIC (FULL) ===================== */

$traffic = [
    "total" => [],
    "last_hour" => [],
    "top_hour" => [],
    "last_min" => [],
    "top_min" => []
];

function parseTrafficLine($pattern, $statusRaw) {
    if (preg_match($pattern, $statusRaw, $m)) {
        return [
            "inbound_msgs" => (int)$m[2],
            "outbound_msgs" => (int)$m[4]
        ];
    }
    return ["inbound_msgs" => 0, "outbound_msgs" => 0];
}

// Total
$traffic["total"] = parseTrafficLine(
    '/Total\s+(\d+)\s+(\d+)\s+[\d\.]+\s+(\d+)\s+(\d+)/',
    $statusRaw
);

// Last Hour
$traffic["last_hour"] = parseTrafficLine(
    '/Last Hour\s+(\d+)\s+(\d+)\s+[\d\.]+\s+(\d+)\s+(\d+)/',
    $statusRaw
);

// Top Hour
$traffic["top_hour"] = parseTrafficLine(
    '/Top\/Hour\s+(\d+)\s+(\d+)\s+[\d\.]+\s+(\d+)\s+(\d+)/',
    $statusRaw
);

// Last Min
$traffic["last_min"] = parseTrafficLine(
    '/Last Min\.\s+(\d+)\s+(\d+)\s+[\d\.]+\s+(\d+)\s+(\d+)/',
    $statusRaw
);

// Top Min
$traffic["top_min"] = parseTrafficLine(
    '/Top\/Min\.\s+(\d+)\s+(\d+)\s+[\d\.]+\s+(\d+)\s+(\d+)/',
    $statusRaw
);

/* ===================== PARSE QUEUES (WITH ERROR) ===================== */

$queues = [];
$lines = explode("\n", $queueRaw);

foreach ($lines as $line) {
    $line = trim($line);

    if (!$line) continue;

    // skip headers
    if (
        strpos($line, "queue") !== false ||
        strpos($line, "----") !== false ||
        substr($line, 0, 1) === "*"
    ) {
        continue;
    }

    // regex parse (IMPORTANT)
    if (preg_match('/^(\S+)\s+(\d+)\s+([\d\.]+)\s+(\d+)\s+(\S+)\s+(.*)$/', $line, $m)) {

        $queues[] = [
            "queue"  => $m[1],
            "rcpt"   => (int)$m[2],
            "kb"     => (float)$m[3],
            "conn"   => (int)$m[4],
            "retry"  => $m[5],
            "error"  => trim($m[6])
        ];
    } 
    else {
        // handle simple P status lines (no error)
        $parts = preg_split('/\s+/', $line);

        if (count($parts) >= 4) {
            $queues[] = [
                "queue"  => $parts[0],
                "rcpt"   => (int)($parts[1] ?? 0),
                "kb"     => (float)($parts[2] ?? 0),
                "conn"   => (int)($parts[3] ?? 0),
                "retry"  => "",
                "error"  => ""
            ];
        }
    }
}

/* ===================== PARSE DOMAINS ===================== */

$domains = [];
$lines = explode("\n", $domainRaw);

foreach ($lines as $line) {
    $line = trim($line);

    if (!$line) continue;

    // skip headers
    if (
        strpos($line, "domain") !== false ||
        strpos($line, "----") !== false
    ) {
        continue;
    }

    // FULL PARSE (with error)
    if (preg_match('/^(\S+)\s+(\d+)\s+([\d\.]+)\s+(\d+)\s*(.*)$/', $line, $m)) {

        $domains[] = [
            "domain" => $m[1],
            "rcpt"   => (int)$m[2],
            "kb"     => (float)$m[3],
            "conn"   => (int)$m[4],
            "error"  => trim($m[5] ?? "")
        ];
    }
}

/* ===================== RESPONSE ===================== */

echo json_encode([
    "status" => $status,
    "uptime" => $uptime,
    "totalQueueRcpt" => array_sum(array_column($queues, "rcpt")),
    "queues" => $queues,
    "domains"=> $domains,

    "traffic" => $traffic,

    "timestamp" => date("Y-m-d H:i:s")
], JSON_PRETTY_PRINT);
