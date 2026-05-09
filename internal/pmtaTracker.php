<?php
date_default_timezone_set("UTC");

/* ==============================
   CONFIG
============================== */

$files = glob("/var/log/pmta/acct-*.csv");

if (!$files) {
    echo "❌ No PMTA acct files found\n";
    exit;
}

usort($files, function ($a, $b) {
    return filemtime($b) - filemtime($a);
});

$API_URL = "http://localhost:3001/api/campaigns/updatePmtaStats";
$INTERNAL_KEY = "super_secret_global_key";

echo "Using file: $ACCT_FILE\n";


/* ==============================
   HELPERS
============================== */

function detect_isp($domain)
{
    $domain = strtolower($domain);

    if (strpos($domain, "gmail.com") !== false) return "gmail";
    if (strpos($domain, "googlemail.") !== false) return "gmail";
    if (strpos($domain, "yahoo.") !== false) return "yahoo";
    if (strpos($domain, "hotmail.") !== false) return "hotmail";
    if (strpos($domain, "outlook.") !== false) return "outlook";
    if (strpos($domain, "live.") !== false) return "live";
    if (strpos($domain, "comcast.net") !== false) return "comcast";
    if (strpos($domain, "aol.") !== false) return "aol";
    if (strpos($domain, "icloud.") !== false) return "icloud";

    return "other";
}

function extract_offer_id($messageId)
{
    if (!$messageId) return null;

    // remove < >
    $messageId = trim($messageId, "<>");

    // split by -
    $parts = explode("-", $messageId);

    // find base64 part (usually 3rd part)
    foreach ($parts as $part) {

        // remove domain if present
        $part = explode("@", $part)[0];

        // try base64 decode
        $decoded = base64_decode(strtr($part, '-_', '+/'), true);

        if ($decoded !== false && preg_match('/^[a-zA-Z0-9_]+$/', $decoded)) {
            return trim($decoded);
        }
    }

    return null;
}

/* ==============================
   PROCESS (MULTI-FILE FIXED)
============================== */

$stats = [];

foreach ($files as $ACCT_FILE) {

    echo "📂 Processing: $ACCT_FILE\n";

    $fp = fopen($ACCT_FILE, "r");
    if (!$fp) {
        echo "❌ Cannot open $ACCT_FILE\n";
        continue;
    }

    while (($line = fgets($fp)) !== false) {

        $row = str_getcsv($line);

        if (count($row) < 10) continue;

        $type = trim($row[0] ?? "");
        $rcpt = trim($row[5] ?? "");

        $vmta = isset($row[19]) ? trim($row[19]) : "";
        $ip   = isset($row[15]) ? trim($row[15]) : "";

        // fallback
        if ($vmta === "" || $vmta === "unknown") {
            $vmta = $ip;
        }

        if (!$vmta) continue;

        $messageId = trim($row[22] ?? "");
        $messageId = str_replace("\r", "", $messageId);
        if (!$messageId) continue;

        $offerId = extract_offer_id($messageId);

        if (!$offerId) {
            echo "⚠️ OfferId missing for: $messageId\n";
            continue;
        }

        $domain = substr(strrchr($rcpt, "@"), 1);
        $isp = detect_isp($domain);

        if (!isset($stats[$offerId])) {
            $stats[$offerId] = [
                "delivered_vmta" => [],
                "delivered_isp" => [],
                "hard_vmta" => [],
                "soft_vmta" => [],
                "hard_isp" => [],
                "soft_isp" => [],
                "hardEmails" => []
            ];
        }

        /* ==============================
           DELIVERED
        ============================== */

        if ($type === "d") {

            $stats[$offerId]["delivered_vmta"][$vmta] =
                ($stats[$offerId]["delivered_vmta"][$vmta] ?? 0) + 1;

            $stats[$offerId]["delivered_isp"][$isp] =
                ($stats[$offerId]["delivered_isp"][$isp] ?? 0) + 1;

        }

        /* ==============================
           BOUNCES
        ============================== */

        elseif ($type === "b" || $type === "f") {

            $dsnStatus = strtolower(trim($row[8] ?? ""));
            $dsnDiag   = strtolower(trim($row[9] ?? ""));
            $reason = $dsnStatus . " " . $dsnDiag;

            $isHard = false;

            if (
                strpos($reason, "mailbox full") !== false ||
                strpos($reason, "quota exceeded") !== false ||
                strpos($reason, "5.2.2") !== false ||

                strpos($reason, "invalid recipient") !== false ||
                strpos($reason, "user unknown") !== false ||
                strpos($reason, "recipient address rejected") !== false ||
                strpos($reason, "mailbox unavailable") !== false ||

                strpos($reason, "mailbox disabled") !== false ||
                strpos($reason, "account disabled") !== false ||
                strpos($reason, "554.30") !== false ||

                strpos($reason, "5.1.1") !== false ||
                strpos($reason, "5.4.4") !== false ||
                strpos($reason, "unable to route") !== false
            ) {
                $isHard = true;
            }

            if ($isHard) {

                $stats[$offerId]["hard_vmta"][$vmta] =
                    ($stats[$offerId]["hard_vmta"][$vmta] ?? 0) + 1;

                $stats[$offerId]["hard_isp"][$isp] =
                    ($stats[$offerId]["hard_isp"][$isp] ?? 0) + 1;

                $stats[$offerId]["hardEmails"][$rcpt] = true;

            } else {

                $stats[$offerId]["soft_vmta"][$vmta] =
                    ($stats[$offerId]["soft_vmta"][$vmta] ?? 0) + 1;

                $stats[$offerId]["soft_isp"][$isp] =
                    ($stats[$offerId]["soft_isp"][$isp] ?? 0) + 1;
            }
        }
    }

    fclose($fp);
}

/* ==============================
   STATS DEBUG
============================== */

echo "\n===== STATS =====\n";
print_r($stats);

/* ==============================
   SEND TO BACKEND
============================== */

foreach ($stats as $offerId => $data) {

    if (empty($data["delivered_vmta"])) continue;

    $payload = json_encode([
        "runtimeOfferId" => $offerId,
        "delivered_vmta" => $data["delivered_vmta"] ?? [],
        "hard_vmta" => $data["hard_vmta"] ?? [],
        "soft_vmta" => $data["soft_vmta"] ?? [],
        "delivered_isp" => $data["delivered_isp"] ?? [],
        "hard_isp" => $data["hard_isp"] ?? [],
        "soft_isp" => $data["soft_isp"] ?? [],
        "hardEmails" => array_keys($data["hardEmails"] ?? [])
    ]);

    echo "\nSending payload:\n$payload\n";

    $ch = curl_init($API_URL);

    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-Internal-Key: " . $INTERNAL_KEY
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo "❌ CURL ERROR: " . curl_error($ch) . "\n";
    }

    echo "API Response: $response\n";

    curl_close($ch);
}

echo "\n✅ Script finished\n";
