<?php

define("LOG_DIR", "/var/www/html/sender_logs");

function log_line($file, $msg, $ctx = []) {
    $line = date("c") . " | " . $msg;
    if (!empty($ctx)) {
        $line .= " | " . json_encode($ctx, JSON_UNESCAPED_SLASHES);
    }
    file_put_contents(LOG_DIR."/$file", $line."\n", FILE_APPEND);
}
