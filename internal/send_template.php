<?php
date_default_timezone_set("America/Los_Angeles");

/* =========================================================
   LOAD STATIC CONFIG
========================================================= */

require __DIR__ . "/offer_config.php";
if (file_exists(__DIR__ . "/dba_config.php")) {
    require __DIR__ . "/dba_config.php";
} else {
    $DBA_EMAIL = "";
}
$MODE = getenv("MODE") ?: "TEST";
$FINAL_DATA_FILE_URL = getenv("FINAL_DATA_FILE_URL");
$SENT_FILE = null;
$FAILED_FILE = null;
if ($MODE === "LIVE") {

    if (!$FINAL_DATA_FILE_URL) {
        die("FINAL_DATA_FILE_URL missing");
    }

    $DATA_FILE_PATH = __DIR__ . "/runtime_data.txt";
    $SENT_FILE = __DIR__ . "/sent.txt";
    $FAILED_FILE = __DIR__ . "/failed.txt";

    if (!file_exists($DATA_FILE_PATH) || filesize($DATA_FILE_PATH) == 0) {

    $fileContent = file_get_contents($FINAL_DATA_FILE_URL);

    if ($fileContent === false) {
        die("Failed to download final data file");
    }

    file_put_contents($DATA_FILE_PATH, $fileContent);
}
}
file_put_contents(__DIR__."/debug.txt", "SCRIPT STARTED\n", FILE_APPEND);
    
/* =========================================================
   RUNTIME CONFIG
========================================================= */

$MODE = getenv("MODE") ?: "TEST";

$ROUTES = json_decode(base64_decode(getenv("ROUTES")), true) ?: [];
$ALIASES = json_decode(base64_decode(getenv("ALIASES")), true) ?: [];

/* =========================================================
   RUNTIME SUBJECT / FROM (LIVE UPDATE SUPPORT)
========================================================= */

$CAMPAIGN_NAME = getenv("CAMPAIGN_NAME");

$subjectFile = __DIR__ . "/subject.php";
$fromFile    = __DIR__ . "/from.php";

$DEFAULT_SUBJECT = "Default Subject";
$DEFAULT_FROM_NAME = "Default Sender";

if (file_exists($subjectFile)) {
    include $subjectFile;
    if (!empty($SUBJECT_LINE)) {
        $DEFAULT_SUBJECT = $SUBJECT_LINE;
    }
}

if (file_exists($fromFile)) {
    include $fromFile;
    if (!empty($FROM_NAME)) {
        $DEFAULT_FROM_NAME = $FROM_NAME;
    }
}

/* =========================================================
   IMAGE HOST REPLACE
========================================================= */

$ALIAS_FILE = __DIR__ . "/aliases.txt";

if (!empty($ALIASES)) {
    file_put_contents($ALIAS_FILE, implode("\n",$ALIASES));
}
$SEEDS  = json_decode(base64_decode(getenv("SEEDS")), true) ?: [];
$TEST_ROUTES = getenv("TEST_ROUTES") ? (int)getenv("TEST_ROUTES") : null;
$TEST_SEEDS = getenv("TEST_SEEDS") ? (int)getenv("TEST_SEEDS") : null;
$TEST_TOTAL_SEND = getenv("TEST_TOTAL_SEND") ? (int)getenv("TEST_TOTAL_SEND") : null;

$TOTAL_SEND = (int)(getenv("TOTAL_SEND") ?: 0);

$SEND_IN_SECONDS = getenv("SEND_IN_SECONDS") !== false
    ? (int)getenv("SEND_IN_SECONDS")
    : null;

$SEND_IN_MINUTES = getenv("SEND_IN_MINUTES") !== false
    ? (int)getenv("SEND_IN_MINUTES")
    : null;

$SEND_IN_HOURS = getenv("SEND_IN_HOURS") !== false
    ? (int)getenv("SEND_IN_HOURS")
    : null;


$TRACKING_MODE = getenv("TRACKING_MODE") ?: "from";
$FIXED_TRACKING_DOMAIN = getenv("TRACK_DOMAIN") ?: "track.buylevitrageneric.com";

$SEED_AFTER = (int)(getenv("SEED_AFTER") ?: 0);
$SEED_MODE  = getenv("SEED_MODE") ?: "round";

$CONTENT_MODE  = getenv("CONTENT_MODE") ?: "multipart";
$TEXT_ENCODING = getenv("TEXT_ENCODING") ?: "base64";
$HTML_ENCODING = getenv("HTML_ENCODING") ?: "base64";



$ENVELOPE_MODE = getenv("ENVELOPE_MODE") ?: "route";
$ENVELOPE_CUSTOM_TYPE = getenv("ENVELOPE_CUSTOM_TYPE") ?: "pattern";
$ENVELOPE_CUSTOM_EMAIL = getenv("ENVELOPE_CUSTOM_EMAIL") ?: null;
$ENVELOPE_CUSTOM_DOMAIN = getenv("ENVELOPE_CUSTOM_DOMAIN") ?: "";
$ENVELOPE_PATTERN_BLOCKS = (int)(getenv("ENVELOPE_PATTERN_BLOCKS") ?: 3);
$ENVELOPE_PATTERN_LENGTH = (int)(getenv("ENVELOPE_PATTERN_LENGTH") ?: 5);

$HEADER_MODE = getenv("HEADER_MODE") ?: "route";
$HEADER_CUSTOM_TYPE = getenv("HEADER_CUSTOM_TYPE") ?: "pattern";
$HEADER_CUSTOM_EMAIL = getenv("HEADER_CUSTOM_EMAIL") ?: null;
$HEADER_CUSTOM_DOMAIN = getenv("HEADER_CUSTOM_DOMAIN") ?: "";
$HEADER_PATTERN_BLOCKS = (int)(getenv("HEADER_PATTERN_BLOCKS") ?: 3);
$HEADER_PATTERN_LENGTH = (int)(getenv("HEADER_PATTERN_LENGTH") ?: 5);

$HEADER_BLOCK_MODE = getenv("HEADER_BLOCK_MODE") ?: "default"; 
$CUSTOM_HEADER_BLOCK = getenv("CUSTOM_HEADER_BLOCK")
    ? base64_decode(getenv("CUSTOM_HEADER_BLOCK"))
    : null;

$TEMP_FILE = __DIR__ . "/runtime_data_tmp.txt";
$tempHandle = fopen($TEMP_FILE, "w");
// CUSTOM_HEADER_BLOCK=
// Date: {date}\r\n
// From: {fromName} <{headerFromEmail}>\r\n
// To: <{email}>\r\n
// Reply-To: {from}\r\n
// Subject: {subject}\r\n
// Message-ID: {mid}\r\n
// MIME-Version: 1.0\r\n
// X-Priority: 1\r\n
// X-Custom: MyHeader\r\n

/* =========================================================
   VALIDATE ROUTES
========================================================= */

if (!is_array($ROUTES) || count($ROUTES) === 0) {
    file_put_contents(__DIR__."/status.json",json_encode([
        "status"=>"FAILED",
        "reason"=>"routes_missing",
        "at"=>date("Y-m-d H:i:s")
    ]));
    exit;
}

/* =========================================================
   AUTO RATE CALC (DO NOT TOUCH)
========================================================= */

if ($SEND_IN_SECONDS !== null) {
    $TOTAL_SECONDS = $SEND_IN_SECONDS;
}
elseif ($SEND_IN_MINUTES !== null) {
    $TOTAL_SECONDS = $SEND_IN_MINUTES * 60;
}
elseif ($SEND_IN_HOURS !== null) {
    $TOTAL_SECONDS = $SEND_IN_HOURS * 3600;
}
else {
    $TOTAL_SECONDS = 0;
}

if ($MODE === "LIVE" && $TOTAL_SEND > 0 && $TOTAL_SECONDS > 0) {
    $MAILS_PER_SECOND = $TOTAL_SEND / $TOTAL_SECONDS;
    $SEND_GAP_MICROSECONDS = ($MAILS_PER_SECOND > 0)
        ? (int)(1000000 / $MAILS_PER_SECOND)
        : 0;
} else {
    $SEND_GAP_MICROSECONDS = 0;
}

/* =========================================================
   HELPERS
========================================================= */

function create_token(){ return bin2hex(random_bytes(32)); }

function rand_str($l,$c){
    $o=''; $m=strlen($c)-1;
    for($i=0;$i<$l;$i++) $o.=$c[random_int(0,$m)];
    return $o;
}

function tracking_domain($from,$fixed,$mode){
    return ($mode==="from") ? $from : $fixed;
}

function register_token_async($token,$type,$offer,$email,$rl=null,$list=null,$route=null){

    $payload = json_encode([
        "token"=>$token,
        "type"=>$type,
        "offer_id"=>$offer,
        "email"=>$email,
        "rl"=>$rl,
        "list_id"=>$list,
        "send_domain"=>$route["domain"] ?? null,
        "vmta"=>$route["vmta"] ?? null
    ]);

    $fp = fopen(__DIR__."/token_queue.log", "a");

    if ($fp) {
        fwrite($fp, $payload."\n");
        fclose($fp);
    }
}

function get_file_line_count($file) {
    if (!file_exists($file)) return 0;

    $lines = 0;
    $handle = fopen($file, "r");

    while (!feof($handle)) {
        fgets($handle);
        $lines++;
    }

    fclose($handle);
    return $lines;
}

$STATE_FILE = __DIR__ . "/state.json";


function load_state($file) {
    if (!file_exists($file)) return null;

    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function save_state($file, $state) {
    file_put_contents($file, json_encode($state));
}

function track($type,$email,$rl=null,$list=null,$route=null){
    global $OFFER_ID;

    $payload = [
        "type" => $type,
        "offer_id" => $OFFER_ID,
        "email" => $email,
        "rl" => $rl,
        "list_id" => $list,
        "send_domain" => $route["domain"] ?? null,
        "vmta" => $route["vmta"] ?? null
    ];

    $token = encrypt_token($payload);
    if (!$token) {
    file_put_contents(__DIR__."/token_error.log",
        "TOKEN FAIL: ".json_encode($payload)."\n",
        FILE_APPEND
    );
    return ""; // ya fallback
}
    file_put_contents(
    __DIR__."/token_debug.log",
    "PAYLOAD: ".json_encode($payload)." | TOKEN: $token\n",
    FILE_APPEND
);

    return $token;
}
function load_alias_pool() {

    $file = __DIR__ . "/aliases.txt";

    if (!file_exists($file)) {
        return [];
    }

    $aliases = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if (!$aliases) {
        return [];
    }

    return array_map("trim", $aliases);
}

$ALIAS_POOL = load_alias_pool();
$ALIAS_INDEX = 0; 
function load_aliases(){

    $file = __DIR__."/aliases.txt";

    if(!file_exists($file)){
        return [];
    }

    $aliases = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return $aliases ?: [];
}

function generate_from_email($route){

    global $ALIAS_INDEX, $ALIAS_POOL;

    if(!empty($ALIAS_POOL)){

        $alias = $ALIAS_POOL[$ALIAS_INDEX % count($ALIAS_POOL)];
        $ALIAS_INDEX++;

        return $alias."@".$route["domain"];
    }

    return $route["from_user"]."@".$route["domain"];
}
function encrypt_token($payload){

    $key = "9f3a8c7d6e5b4a3c2d1e0f9a8b7c6d5e"; // 32 bytes exact

    if (strlen($key) !== 32) {
        return null;
    }

    $iv = random_bytes(16);

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

    $encrypted = openssl_encrypt(
        $json,
        "AES-256-CBC",
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($encrypted === false) {
        return null;
    }

    $final = $iv . $encrypted;

    // base64url
    return rtrim(strtr(base64_encode($final), '+/', '-_'), '=');
}
function notify_node_status($runtimeOfferId, $status){

    $senderKey = "super_secret_global_key";

    if (!$senderKey) {
        file_put_contents(
            __DIR__."/status_api_error.log",
            date("Y-m-d H:i:s") . " MISSING SENDER_INTERNAL_KEY\n",
            FILE_APPEND
        );
        return false;
    }

    $payload = json_encode([
        "runtimeOfferId" => $runtimeOfferId,
        "status" => $status
    ]);

    $ch = curl_init("http://localhost:3001/api/campaigns/updateStatus");

    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-Internal-Key: " . $senderKey
        ],
        CURLOPT_POSTFIELDS => $payload
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    file_put_contents(
        __DIR__."/status_api_debug.log",
        date("Y-m-d H:i:s") .
        " STATUS: $status" .
        " HTTP: $httpCode" .
        " RESPONSE: $response\n",
        FILE_APPEND
    );

    if ($response === false) {
        file_put_contents(
            __DIR__."/status_api_error.log",
            date("Y-m-d H:i:s") . " CURL ERROR: " . curl_error($ch) . "\n",
            FILE_APPEND
        );
    }

    curl_close($ch);

    return ($httpCode === 200);
}

if (!function_exists('notify_total_sent')) {
    function notify_total_sent($runtimeOfferId, $count) {

        $senderKey = "super_secret_global_key";

        $payload = json_encode([
            "runtimeOfferId" => $runtimeOfferId,
            "count" => $count
        ]);

        $ch = curl_init("http://localhost:3001/api/campaigns/updateTotalSent");

        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "X-Internal-Key: " . $senderKey
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 3
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            file_put_contents(
                __DIR__."/total_sent_error.log",
                date("Y-m-d H:i:s") . " CURL ERROR: " . curl_error($ch) . "\n",
                FILE_APPEND
            );
        }

        curl_close($ch);
    }
}


/* =========================================================
   BUILD MESSAGE WITH r/1.php r/2.php r/3.php r/4.php
========================================================= */

function build_message($email,$trackBase,$listType,$route){

    // 🔥 HAR MAIL PE FRESH CREATIVE LOAD
    include __DIR__ . "/creative_offer.php";

    global $DBA_EMAIL;

    // fallback safety
    if (!isset($CREATIVE_HTML)) {
        return "";
    }

    if (!isset($DBA_EMAIL) || !$DBA_EMAIL) {
        $DBA_EMAIL = getenv("DBA_EMAIL") ?: "";
    }

    // 🔥 APPLY DBA HERE (live)
    $msg = str_replace("{dba}", $DBA_EMAIL, $CREATIVE_HTML);

     global $ROUTES;

    $host = $ROUTES[0]["domain"] ?? "localhost";
    $IMAGE_HOST = "https://" . $host;

    $msg = str_replace("{{IMAGE_HOST}}", $IMAGE_HOST, $msg);

    // OPEN
    $o = track("open",$email,null,$listType,$route);
    $msg = str_replace(
    "{impression}",
    $trackBase . "/r/1.php?k=" . rawurlencode($o),
    $msg
);

    // CLICKS
    for ($rl=1;$rl<=2;$rl++) {
        $t = track("click",$email,$rl,$listType,$route);
        $msg = str_replace(
    "{redirection$rl}",
    $trackBase . "/r/2.php?k=" . rawurlencode($t),
    $msg
);
    }

    // OPTOUT
    $opt = track("optout",$email,null,$listType,$route);
    $msg = str_replace(
    "{optout}",
    $trackBase . "/r/3.php?k=" . rawurlencode($opt),
    $msg
);

    // UNSUB
    $un = track("unsub",$email,null,$listType,$route);
    $msg = str_replace(
    "{uns}",
    $trackBase . "/r/4.php?k=" . rawurlencode($un),
    $msg
);

    return $msg;
}

function generate_message_id($email, $offerId, $fromDomain)
{
    // 5 char random
    $random = rand_str(5, "abcdefghijklmnopqrstuvwxyz0123456789");

    // md5 of email (lowercase for consistency)
    $emailHash = md5(strtolower(trim($email)));

    // base64 of offer id (URL safe)
    $offerBase64 = rtrim(strtr(base64_encode($offerId), '+/', '-_'), '=');

    return "<{$random}-{$emailHash}-{$offerBase64}@{$fromDomain}>";
}


function smtp_expect($socket, $expectedCodes = []) {
    $response = fgets($socket, 1024);

    if ($response === false) {
        return [false, "no_response"];
    }

    $code = substr($response, 0, 3);

    if (!in_array($code, $expectedCodes)) {
        return [false, trim($response)];
    }

    return [true, trim($response)];
}


/* =========================================================
   SMTP SEND
========================================================= */
function sendmails($email,$from,$fromName,$subject,$body,$vmta,$mid){
    // Generate random values
    $add1 = rand_str(4, "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_");
    $add2 = rand_str(5, "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_");
    $add3 = rand_str(4, "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_");
    $add4 = rand_str(5, "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_");
    $add5 = rand_str(4, "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_");
    $add16 = rand_str(5, "abcdef0123456789");

    // Replace inside message body
    $body = str_replace(
        ["{add1}", "{add16}", "{add2}","{add3}", "{add4}", "{add5}"],
        [$add1, $add16, $add2, $add3, $add4, $add5],
        $body
    );

    $s = fsockopen("127.0.0.1",2525,$e,$r,5);
    if(!$s){
        file_put_contents(__DIR__."/debug.txt","SMTP CONNECT FAIL\n",FILE_APPEND);
        return;
    }


    $fromDomain = substr(strrchr($from,"@"),1);

    $unToken = track("unsub", $email, null, null, [
    "domain" => $fromDomain,
    "vmta" => $vmta
]);
    $listUnsubUrl = "https://$fromDomain/r/4.php?k=" . rawurlencode($unToken);

    global
        $ENVELOPE_MODE,
        $ENVELOPE_CUSTOM_TYPE,
        $ENVELOPE_CUSTOM_EMAIL,
        $ENVELOPE_CUSTOM_DOMAIN,
        $ENVELOPE_PATTERN_BLOCKS,
        $ENVELOPE_PATTERN_LENGTH,

        $HEADER_MODE,
        $HEADER_CUSTOM_TYPE,
        $HEADER_CUSTOM_EMAIL,
        $HEADER_CUSTOM_DOMAIN,
        $HEADER_PATTERN_BLOCKS,
        $HEADER_PATTERN_LENGTH;

    $routeDomain = substr(strrchr($from,"@"),1);

    /* =========================================================
    ENVELOPE DECISION (MAIL FROM)
    ========================================================= */

    if ($ENVELOPE_MODE === "route") {

        $envelopeFrom = $from;

    }
    elseif ($ENVELOPE_MODE === "random") {

        $envelopeFrom = rand_str(6,"abcdef0123456789") . "@$routeDomain";

    }
    elseif ($ENVELOPE_MODE === "custom") {

        if ($ENVELOPE_CUSTOM_TYPE === "fixed" && !empty($ENVELOPE_CUSTOM_EMAIL)) {

            $envelopeFrom = $ENVELOPE_CUSTOM_EMAIL;

        }
        elseif ($ENVELOPE_CUSTOM_TYPE === "pattern") {

            $blocks = [];
            for ($i = 0; $i < $ENVELOPE_PATTERN_BLOCKS; $i++) {
                $blocks[] = rand_str($ENVELOPE_PATTERN_LENGTH, "abcdef0123456789");
            }

            $local = implode("-", $blocks);
            $envelopeFrom = $local . "@$ENVELOPE_CUSTOM_DOMAIN";

        }
        else {

            $envelopeFrom = $from; // fallback

        }

    }
    else {

        $envelopeFrom = $from;

    }


    /* =========================================================
    HEADER DECISION (From:)
    ========================================================= */

    if ($HEADER_MODE === "route") {

        $headerFromEmail = $from;

    }
    elseif ($HEADER_MODE === "random") {

        $headerFromEmail = rand_str(6,"abcdef0123456789") . "@$routeDomain";

    }
    elseif ($HEADER_MODE === "custom") {

        if ($HEADER_CUSTOM_TYPE === "fixed" && !empty($HEADER_CUSTOM_EMAIL)) {

            $headerFromEmail = $HEADER_CUSTOM_EMAIL;

        }
        elseif ($HEADER_CUSTOM_TYPE === "pattern") {

            $blocks = [];
            for ($i = 0; $i < $HEADER_PATTERN_BLOCKS; $i++) {
                $blocks[] = rand_str($HEADER_PATTERN_LENGTH, "abcdef0123456789");
            }

            $local = implode("-", $blocks);
            $headerFromEmail = $local . "@$HEADER_CUSTOM_DOMAIN";

        }
        else {

            $headerFromEmail = $from; // fallback

        }

    }
    else {

        $headerFromEmail = $from;

    }

    $date = date("r");

    global $CONTENT_MODE, $TEXT_ENCODING, $HTML_ENCODING;

    $boundary = "b1_" . md5(uniqid());

    // ===== AUTO GENERATE TEXT VERSION FROM HTML =====
    $textVersion = $body;

    // Convert links: Anchor text - URL
$textVersion = preg_replace(
    '/<a\s+href="([^"]+)"[^>]*>(.*?)<\/a>/is',
    '$2 ($1)',
    $body
);

$textVersion = strip_tags($textVersion);
$textVersion = html_entity_decode($textVersion, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// normalize whitespace properly
$textVersion = preg_replace('/[ \t]+/', ' ', $textVersion);
$textVersion = preg_replace('/\s*\r?\n\s*/', "\r\n", $textVersion);
$textVersion = trim($textVersion);


    // ===== ENCODING LOGIC =====

    // TEXT
    if ($TEXT_ENCODING === "base64") {
        $encodedText = chunk_split(base64_encode($textVersion));
    } elseif ($TEXT_ENCODING === "quoted-printable") {
        $encodedText = quoted_printable_encode($textVersion);
    } else {
        $encodedText = $textVersion;
    }

    // HTML
    if ($HTML_ENCODING === "base64") {
        $encodedHtml = chunk_split(base64_encode($body));
    } elseif ($HTML_ENCODING === "quoted-printable") {
        $encodedHtml = quoted_printable_encode($body);
    } else {
        $encodedHtml = $body;
    }

    // ===== SMTP START =====
    list($ok,$resp) = smtp_expect($s,[220]);
    if(!$ok){
        file_put_contents(__DIR__."/debug.txt","HELO FAIL: $resp\n",FILE_APPEND);
        fclose($s);
        return false;
    }

    fputs($s,"HELO localhost\r\n");
    list($ok,$resp) = smtp_expect($s,[250]);
    if(!$ok){
        file_put_contents(__DIR__."/debug.txt","HELO REJECT: $resp\n",FILE_APPEND);
        fclose($s);
        return false;
    }

    fputs($s,"MAIL FROM:<$envelopeFrom>\r\n");
    list($ok,$resp) = smtp_expect($s,[250]);
    if(!$ok){
        file_put_contents(__DIR__."/debug.txt","MAIL FROM FAIL: $resp\n",FILE_APPEND);
        fclose($s);
        return false;
    }

    fputs($s,"RCPT TO:<$email>\r\n");
    list($ok,$resp) = smtp_expect($s,[250,251]);
    if(!$ok){
        file_put_contents(__DIR__."/debug.txt","RCPT FAIL: $email | $resp\n",FILE_APPEND);
        fclose($s);
        return false;
    }

    fputs($s,"DATA\r\n");
    list($ok,$resp) = smtp_expect($s,[354]);
    if(!$ok){
        file_put_contents(__DIR__."/debug.txt","DATA FAIL: $resp\n",FILE_APPEND);
        fclose($s);
        return false;
    }


    global $HEADER_BLOCK_MODE, $CUSTOM_HEADER_BLOCK;

    // ===== HEADERS =====
    if ($HEADER_BLOCK_MODE === "custom" && !empty($CUSTOM_HEADER_BLOCK)) {

        $headerBlock = str_replace(
            ["{date}", "{fromName}", "{headerFromEmail}", "{email}", "{from}", "{subject}", "{mid}"],
            [$date, $fromName, $headerFromEmail, $email, $from, $subject, $mid],
            $CUSTOM_HEADER_BLOCK
        );

        $headerBlock = str_replace("\n", "\r\n", $headerBlock);

        fputs($s, $headerBlock . "\r\n");

    }else {

        // DEFAULT HEADER
        fputs($s,"Date: $date\r\n");
        fputs($s,"From: $fromName <$headerFromEmail>\r\n");
        fputs($s,"To: <$email>\r\n");
        fputs($s,"Reply-To: $from\r\n");
        fputs($s,"Subject: $subject\r\n");
        fputs($s,"Message-ID: $mid\r\n");
        fputs($s,"MIME-Version: 1.0\r\n");
        fputs($s,"List-Unsubscribe: <$listUnsubUrl>\r\n");
        fputs($s,"List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n");
    }


    if ($CONTENT_MODE === "html") {

        fputs($s,"Content-Type: text/html; charset=UTF-8\r\n");
        fputs($s,"Content-Transfer-Encoding: $HTML_ENCODING\r\n");
        fputs($s,"X-virtual-MTA: $vmta\r\n");
        fputs($s,"\r\n");

        fputs($s,$encodedHtml."\r\n");

    } elseif ($CONTENT_MODE === "multipart") {

        fputs($s,"Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n");
        fputs($s,"X-virtual-MTA: $vmta\r\n");
        fputs($s,"\r\n");

        // ===== TEXT PART =====
        fputs($s,"--$boundary\r\n");
        fputs($s,"Content-Type: text/plain; charset=UTF-8\r\n");
        fputs($s,"Content-Transfer-Encoding: $TEXT_ENCODING\r\n\r\n");
        fputs($s,$encodedText."\r\n");

        // ===== HTML PART =====
        fputs($s,"--$boundary\r\n");
        fputs($s,"Content-Type: text/html; charset=UTF-8\r\n");
        fputs($s,"Content-Transfer-Encoding: $HTML_ENCODING\r\n\r\n");
        fputs($s,$encodedHtml."\r\n");

        // CLOSE
        fputs($s,"--$boundary--\r\n");
    }

    fputs($s,"\r\n.\r\n");
    list($ok,$resp) = smtp_expect($s,[250]);
    if(!$ok){
        file_put_contents(__DIR__."/debug.txt","FINAL SEND FAIL: $resp\n",FILE_APPEND);
        fclose($s);
        return false;
    }

    fputs($s,"QUIT\r\n");
    fclose($s);
    return true;
}


/* =========================================================
   ROUTE PICK
========================================================= */

$routeIndex = 0;

function pick_route($routes, &$i){
    $r = $routes[$i];
    return $r;
}

/* =========================================================
   TEST MODE
========================================================= */
file_put_contents(__DIR__."/debug.txt", "SEEDS RAW: ".print_r($SEEDS,true)."\n", FILE_APPEND);

if ($MODE === "TEST") {
        $TEST_SENT = 0;

    /* ===== LIMIT SEEDS ===== */

    if ($TEST_SEEDS && count($SEEDS) > $TEST_SEEDS) {
        $SEEDS = array_slice($SEEDS, 0, $TEST_SEEDS);
    }

    /* ===== LIMIT ROUTES ===== */

    file_put_contents(
        __DIR__."/debug.txt",
        "TEST LIMITS => routes:".count($ROUTES).
        " seeds:".count($SEEDS).
        " total:".$TEST_TOTAL_SEND."\n",
        FILE_APPEND
    );

    if ($TEST_ROUTES && count($ROUTES) > $TEST_ROUTES) {
        $ROUTES = array_slice($ROUTES, 0, $TEST_ROUTES);
    }

    foreach ($SEEDS as $seed) {

        if (!filter_var($seed, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        foreach ($ROUTES as $route) {

            if (!isset($route["from_user"], $route["domain"], $route["vmta"])) {
                continue;
            }

            $from = generate_from_email($route);
            $vmta = $route["vmta"];

            $trackBase = "https://".tracking_domain(
                $route["domain"],
                $FIXED_TRACKING_DOMAIN,
                $TRACKING_MODE
            );

            $msg = build_message($seed,$trackBase,"seed",$route);

            $mid = generate_message_id(
                $seed,
                $OFFER_ID,
                $route["domain"]
            );

            $seedOk = sendmails(
                $seed,
                $from,
                $DEFAULT_FROM_NAME,
                $DEFAULT_SUBJECT,
                $msg,
                $vmta,
                $mid
            );

            if(!$seedOk){
                file_put_contents(
                    __DIR__."/debug.txt",
                    "SEED SEND FAIL: $seed via ".$route["domain"]."\n",
                    FILE_APPEND
                );
            }
            $TEST_SENT++;

            if ($TEST_TOTAL_SEND && $TEST_SENT >= $TEST_TOTAL_SEND) {
                break 2;
            }
            echo "Seed sent → $seed via ".$route["domain"]."\n";
        }
    }

    file_put_contents(__DIR__."/status.json",json_encode([
        "status"=>"COMPLETED",
        "mode"=>"TEST",
        "completed_at"=>date("Y-m-d H:i:s"),
        "offer_id"=>$OFFER_ID
    ]));

    exit;
}

/* =========================================================
   LIVE MODE
========================================================= */

$FAILURES = 0;
$MAX_FAILURES = 50;

/* =========================================================
   LIVE MODE
========================================================= */

if ($MODE === "LIVE") {

    notify_node_status($OFFER_ID, "RUNNING");  // 🔥 MOVE HERE

    if ($TOTAL_SEND <= 0) {
        die("TOTAL_SEND must be > 0 in LIVE mode");
    }
}

$handle = fopen($DATA_FILE_PATH, "r");
$nextSendTime = microtime(true);
if (!$handle) die("DATA FILE NOT OPENING");
// 🔥 FORCE LOAD LATEST CONFIG BEFORE LOOP START
$controlConfigFile = __DIR__ . "/runtime_config.json";

if (file_exists($controlConfigFile)) {
    $cfg = json_decode(file_get_contents($controlConfigFile), true);

    if (isset($cfg["totalSend"])){
        $TOTAL_SEND = (int)$cfg["totalSend"];
    }

    if (!empty($cfg["sendInMinutes"])) {
        $TOTAL_SECONDS = (int)$cfg["sendInMinutes"] * 60;
    }

    if ($TOTAL_SEND > 0 && $TOTAL_SECONDS > 0) {
        $MAILS_PER_SECOND = $TOTAL_SEND / $TOTAL_SECONDS;

        $SEND_GAP_MICROSECONDS = ($MAILS_PER_SECOND > 0)
            ? (int)(1000000 / $MAILS_PER_SECOND)
            : 0;
    }
    
}
$SENT_SET = [];

if ($SENT_FILE && file_exists($SENT_FILE)) {
    $lines = file($SENT_FILE, FILE_IGNORE_NEW_LINES);

    foreach ($lines as $l) {
            $emailKey = trim($l); // no explode
            $SENT_SET[$emailKey] = true;
        }
}
$START_TIME = microtime(true);
$PAUSED_TIME = 0;
$PAUSE_STARTED_AT = null;
$LAST_TOTAL = $TOTAL_SEND;
$LAST_SECONDS = $TOTAL_SECONDS;
$state = load_state($STATE_FILE);

if ($state) {
    $START_TIME   = $state["start_time"];
    $PAUSED_TIME  = $state["paused_time"];
    $SENT         = $state["total_sent"];
    $LAST_TOTAL   = $state["last_total"];
    $LAST_SECONDS = $state["last_seconds"];
    $RESUME_POINTER = $state["file_pointer"] ?? 0;
} else {
    $START_TIME   = microtime(true);
    $PAUSED_TIME  = 0;
    $SENT         = count($SENT_SET);
    $LAST_TOTAL   = $TOTAL_SEND;
    $LAST_SECONDS = $TOTAL_SECONDS;
    $RESUME_INDEX = 0;

    save_state($STATE_FILE, [
        "index" => 0,
        "total_sent" => $SENT,
        "start_time" => $START_TIME,
        "paused_time" => 0,
        "last_total" => $TOTAL_SEND,
        "last_seconds" => $TOTAL_SECONDS
    ]);
}

$PAUSE_STARTED_AT = null;
$LAST_REPORTED_STATUS = "RUNNING";
// $SENT = count($SENT_SET);
$INITIAL_SENT = $SENT;
$TARGET_TOTAL = $INITIAL_SENT + $TOTAL_SEND;
$SEED_INDEX = 0;

$TOTAL_SENT_BUFFER = 0;
$LAST_TOTAL_FLUSH = time();

$remainingLines = [];

$CONTROL_LAST_CHECK = microtime(true);
$CONTROL_CACHE = "RUNNING";
$STATUS_LAST_WRITE = time();
$nextSendTime = microtime(true);


if (!empty($RESUME_POINTER)) {
    fseek($handle, $RESUME_POINTER);
}

while (($line = fgets($handle)) !== false) {

    $DEFAULT_SUBJECT = "Default Subject";
    $DEFAULT_FROM_NAME = "Default Sender";
    $controlConfigFile = __DIR__ . "/runtime_config.json";

    static $lastConfigLoad = 0;
static $cfg = null;

// config reload
if ((microtime(true) - $lastConfigLoad) > 1) {

    if (file_exists($controlConfigFile)) {
        $newCfg = json_decode(file_get_contents($controlConfigFile), true);

        if (is_array($newCfg)) {
            $cfg = $newCfg;
        }
    }

    $lastConfigLoad = microtime(true);
}

// apply config
if (isset($cfg["totalSend"])) {
    $TOTAL_SEND = (int)$cfg["totalSend"];
}

if (!empty($cfg["sendInMinutes"])) {
    $TOTAL_SECONDS = (int)$cfg["sendInMinutes"] * 60;
}

// 🔥 ADD THIS BLOCK HERE
if ($TOTAL_SEND != $LAST_TOTAL || $TOTAL_SECONDS != $LAST_SECONDS) {

    // 🔥 DO NOT RESET SENT
    $INITIAL_SENT = $SENT;

    // 🔥 NEW TARGET BASED ON CURRENT PROGRESS
    $TARGET_TOTAL = $INITIAL_SENT + $TOTAL_SEND;

    // 🔥 RESET TIMING ONLY
    $START_TIME = microtime(true);
    $PAUSED_TIME = 0;
    $PAUSE_STARTED_AT = null;
    $nextSendTime = microtime(true);

    $LAST_TOTAL = $TOTAL_SEND;
    $LAST_SECONDS = $TOTAL_SECONDS;

    save_state($STATE_FILE, [
        "file_pointer" => ftell($handle),
        "total_sent" => $SENT,
        "start_time" => $START_TIME,
        "paused_time" => 0,
        "last_total" => $TOTAL_SEND,
        "last_seconds" => $TOTAL_SECONDS
    ]);
}

if ($TOTAL_SEND > 0 && $TOTAL_SECONDS > 0) {
    $MAILS_PER_SECOND = $TOTAL_SEND / $TOTAL_SECONDS;

    $SEND_GAP_MICROSECONDS = ($MAILS_PER_SECOND > 0)
        ? (int)(1000000 / $MAILS_PER_SECOND)
        : 0;
}
if ($SENT >= $TARGET_TOTAL) {
    break;
}
    if (file_exists($subjectFile)) {
        unset($SUBJECT_LINE);
        include $subjectFile;

        if (!empty($SUBJECT_LINE)) {
            $DEFAULT_SUBJECT = $SUBJECT_LINE;
        }
    }

    if (file_exists($fromFile)) {
        unset($FROM_NAME);
        include $fromFile;

        if (!empty($FROM_NAME)) {
            $DEFAULT_FROM_NAME = $FROM_NAME;
        }
    }
    /* =====================================
       1️⃣ CONTROL CHECK FIRST (CRITICAL)
    ===================================== */

    if ((microtime(true) - $CONTROL_LAST_CHECK) >= 0.05) {

        $controlFile = __DIR__ . "/control.json";

        if (file_exists($controlFile)) {
            $control = json_decode(file_get_contents($controlFile), true);
            $CONTROL_CACHE = $control["status"] ?? "RUNNING";
        } else {
            $CONTROL_CACHE = "RUNNING";
        }

        $CONTROL_LAST_CHECK = microtime(true);
    }

    if ($CONTROL_CACHE === "STOPPED") {

        if ($TOTAL_SENT_BUFFER > 0) {
            notify_total_sent($OFFER_ID, $TOTAL_SENT_BUFFER);
        }

        fclose($handle);
        fclose($tempHandle);

        // 🔥 CLEAN EVERYTHING
        file_put_contents($DATA_FILE_PATH, "");

        if (file_exists($TEMP_FILE)) {
            unlink($TEMP_FILE);
        }

        if (file_exists($STATE_FILE)) {
            unlink($STATE_FILE);
        }

        file_put_contents(__DIR__ . "/status.json", json_encode([
            "status" => "STOPPED",
            "at" => date("Y-m-d H:i:s"),
            "sent" => $SENT
        ]));

        notify_node_status($OFFER_ID, "STOPPED");
        exit;
    }

    if ($CONTROL_CACHE === "PAUSED") {
        if ($PAUSE_STARTED_AT === null) {
            $PAUSE_STARTED_AT = microtime(true);
        }
        // 🛑 CURRENT LINE KO BHI SAFE KAR (VERY IMPORTANT)
        // current line
        if (!empty($line)) {
            fwrite($tempHandle, $line);
        }

        // remaining file direct write (NO ARRAY)
        // 🔥 JUST SAVE POINTER — DO NOT REWRITE FILE
        save_state($STATE_FILE, [
            "file_pointer" => ftell($handle),
            "total_sent" => $SENT,
            "start_time" => $START_TIME,
            "paused_time" => $PAUSED_TIME,
            "last_total" => $TOTAL_SEND,
            "last_seconds" => $TOTAL_SECONDS
        ]);

        fclose($handle);
        fclose($tempHandle);
        // replace original safely
        // fclose($tempHandle);
        // rename($TEMP_FILE, $DATA_FILE_PATH);

        file_put_contents(__DIR__."/pause_debug.log",
            "PAUSED SAFE | remaining: ".count($remainingLines)." | sent: $SENT\n",
            FILE_APPEND
        );

        if ($LAST_REPORTED_STATUS !== "PAUSED") {
            notify_node_status($OFFER_ID, "PAUSED");
            $LAST_REPORTED_STATUS = "PAUSED";
        }

        // ⏸️ WAIT LOOP
        while (true) {
            usleep(500000);

            $control = file_exists(__DIR__."/control.json")
                ? json_decode(file_get_contents(__DIR__."/control.json"), true)
                : ["status"=>"RUNNING"];

            if (($control["status"] ?? "RUNNING") !== "PAUSED") {
                break;
            }
        }

        // 🔁 REOPEN FILE
        $handle = fopen($DATA_FILE_PATH, "r");
        if ($PAUSE_STARTED_AT !== null) {
            $PAUSED_TIME += microtime(true) - $PAUSE_STARTED_AT;
            $PAUSE_STARTED_AT = null;
        }

        // 🔥 RESET TIMING (VERY IMPORTANT)
        $nextSendTime = microtime(true);
        save_state($STATE_FILE, [
            "file_pointer" => ftell($handle),
            "total_sent" => $SENT,
            "start_time" => $START_TIME,
            "paused_time" => $PAUSED_TIME,
            "last_total" => $TOTAL_SEND,
            "last_seconds" => $TOTAL_SECONDS
        ]);
        continue;
    }

    if ($CONTROL_CACHE === "RUNNING" && $LAST_REPORTED_STATUS === "PAUSED") {
        notify_node_status($OFFER_ID, "RUNNING");
        $LAST_REPORTED_STATUS = "RUNNING";
    }

    /* =====================================
       2️⃣ LIVE STATUS FILE UPDATE
    ===================================== */

    if ((time() - $STATUS_LAST_WRITE) >= 2) {
    $now = microtime(true);

    // safety guard
    if ($PAUSE_STARTED_AT !== null) {
        $currentPaused = microtime(true) - $PAUSE_STARTED_AT;
    } else {
        $currentPaused = 0;
    }

    $elapsed = ($now - $START_TIME) - ($PAUSED_TIME + $currentPaused);

    if ($elapsed < 0) {
        $elapsed = 0;
    }

    $remaining = ($TARGET_TOTAL > 0)
        ? max(0, $TARGET_TOTAL - $SENT)
        : 0;

    $speed = ($elapsed > 0 && $SENT > 0)
        ? $SENT / $elapsed
        : 0;

    $eta = ($speed > 0)
        ? $remaining / $speed
        : 0;

    $progressPercent = ($TARGET_TOTAL > 0)
        ? round(($SENT / $TARGET_TOTAL) * 100, 2)
        : 0;

    $actualRemaining = get_file_line_count($DATA_FILE_PATH);
    
    file_put_contents(__DIR__ . "/status.json", json_encode([
        "status" => "RUNNING",
        "mode" => "LIVE",
        "offer_id" => $OFFER_ID,

        "sent" => $SENT,
        "remaining" => $remaining, // target based
        "remaining_data" => $actualRemaining, // 🔥 real file data
        "total_target" => $TARGET_TOTAL,

        "elapsed_seconds" => round($elapsed),
        "remaining_seconds" => max(0, round($eta)), // 🔥 safety fix

        "speed_per_sec" => round($speed, 2),
        "progress_percent" => $progressPercent,

        "updated_at" => date("Y-m-d H:i:s")
    ]));

    $STATUS_LAST_WRITE = time();
}

    /* =====================================
       3️⃣ NORMAL SEND FLOW
    ===================================== */

    $row = explode("|", trim($line));

    if (!isset($row[1])) {
        fwrite($tempHandle, $line);
        continue;
    }

    $email = trim($row[1]);
    $emailKey = md5(strtolower(trim($email)));

    if (isset($SENT_SET[$emailKey])) {
        fwrite($tempHandle, $line); // 🔥 preserve
        continue;
    }
    /* LIST ID FROM DATA FILE (3rd COLUMN) */
    $listId = isset($row[2]) ? strtolower(trim($row[2])) : "unknown";

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fwrite($tempHandle, $line);
        continue;
    }

    $route = pick_route($ROUTES, $routeIndex);
    if (!isset($route["from_user"], $route["domain"], $route["vmta"])) {
        continue;
    }

    $from = generate_from_email($route);
    $vmta = $route["vmta"];

    $trackBase = "https://" . tracking_domain(
        $route["domain"],
        $FIXED_TRACKING_DOMAIN,
        $TRACKING_MODE
    );

    $msg = build_message($email, $trackBase, $listId, $route);
    $mid = generate_message_id($email, $OFFER_ID, $route["domain"]);


    /* ===============================
    ⏱️ SMART SCHEDULING (FIXED)
    =============================== */

    // wait BEFORE sending (main fix)
    $now = microtime(true);

    // HARD sync (no drift allowed)
    if ($nextSendTime <= 0) {
        $nextSendTime = $now;
    }

    // wait only exact gap (no accumulation)
    $diff = $nextSendTime - $now;

    if ($diff > 0) {
        usleep((int)($diff * 1000000));
    } else {
        // 🔥 catchup mode (important)
        $nextSendTime = $now;
    }

    /* ===============================
    📤 SEND MAIL
    =============================== */
    $sentOk = sendmails(
        $email,
        $from,
        $DEFAULT_FROM_NAME,
        $DEFAULT_SUBJECT,
        $msg,
        $vmta,
        $mid
    );

    // schedule next send (fixed timing)
    $nextSendTime += ($SEND_GAP_MICROSECONDS / 1000000);


    /* ===============================
    🔁 ROUTE ROTATION
    =============================== */
    $routeIndex++;
    if ($routeIndex >= count($ROUTES)) {
        $routeIndex = 0;
    }


    /* ===============================
    ❌ FAILURE HANDLING
    =============================== */
    if (!$sentOk) {
    if ($SENT_FILE && $FAILED_FILE) {
    file_put_contents($FAILED_FILE, $line, FILE_APPEND);
}
       $FAILURES++;
        fwrite($tempHandle, $line);

        usleep(50000); // small cooldown

        if ($FAILURES >= $MAX_FAILURES) {
            break;
        }

    } else {
    $FAILURES = 0;
    $SENT++;
    // $currentIndex++;

    save_state($STATE_FILE, [
        "file_pointer" => ftell($handle),
        "total_sent" => $SENT,
        "start_time" => $START_TIME,
        "paused_time" => $PAUSED_TIME,
        "last_total" => $TOTAL_SEND,
        "last_seconds" => $TOTAL_SECONDS
    ]);
    $TOTAL_SENT_BUFFER++;
    $SENT_SET[$emailKey] = true;
    // 🔥 SAVE SENT
    if ($SENT_FILE && $FAILED_FILE) {
    $emailKey = md5(strtolower(trim($email)));
    file_put_contents($SENT_FILE, $emailKey."\n", FILE_APPEND);
}
}


    /* ===============================
    🌱 SEED SEND (UPDATED FIX)
    =============================== */
    if ($SEED_AFTER > 0 && count($SEEDS) > 0 && $SENT % $SEED_AFTER === 0) {

        $seed = ($SEED_MODE === "round")
            ? $SEEDS[$SEED_INDEX++ % count($SEEDS)]
            : $SEEDS[array_rand($SEEDS)];

        $mid = generate_message_id($seed, $OFFER_ID, $route["domain"]);
        $seedMsg = build_message($seed, $trackBase, "seed", $route);

        // seed scheduling (same logic apply)
        $now = microtime(true);

        // HARD sync (no drift allowed)
        if ($nextSendTime <= 0) {
            $nextSendTime = $now;
        }

        // wait only exact gap (no accumulation)
        $diff = $nextSendTime - $now;

        if ($diff > 0) {
            usleep((int)($diff * 1000000));
        } else {
            // 🔥 catchup mode (important)
            $nextSendTime = $now;
        }

        sendmails(
            $seed,
            $from,
            $DEFAULT_FROM_NAME,
            $DEFAULT_SUBJECT,
            $seedMsg,
            $vmta,
            $mid
        );

        // DO NOT affect main timing
        // optional small delay only
        // usleep(50000); // 50ms
    }

    /* ===============================
    📤 TOTAL SENT FLUSH
    =============================== */
    if ((time() - $LAST_TOTAL_FLUSH) >= 5) {

        if ($TOTAL_SENT_BUFFER > 0) {
            notify_total_sent($OFFER_ID, $TOTAL_SENT_BUFFER);
            $TOTAL_SENT_BUFFER = 0;
        }

        $LAST_TOTAL_FLUSH = time();
    }
}

/* =====================================
   LOOP FINISHED (COMPLETED)
===================================== */

fclose($handle);

if ($TOTAL_SENT_BUFFER > 0) {
    notify_total_sent($OFFER_ID, $TOTAL_SENT_BUFFER);
}

fclose($tempHandle);
rename($TEMP_FILE, $DATA_FILE_PATH);

file_put_contents(__DIR__ . "/status.json", json_encode([
    "status" => "COMPLETED",
    "mode" => "LIVE",
    "completed_at" => date("Y-m-d H:i:s"),
    "offer_id" => $OFFER_ID,
    "failures" => $FAILURES,
    "total_sent" => $SENT
]));
if (file_exists($STATE_FILE)) {
    unlink($STATE_FILE);
}

$result = notify_node_status($OFFER_ID, "COMPLETED");

file_put_contents(
    __DIR__."/final_status_debug.log",
    date("Y-m-d H:i:s") . " FINAL STATUS SENT\n",
    FILE_APPEND
);

sleep(1);

