<?php

// Load from environment (recommended)
function getInternalKey() {
    $key = getenv("SENDER_INTERNAL_KEY");

    if (!$key) {
        error_log("SENDER_INTERNAL_KEY not set");
        exit(json_encode(["error" => "server_config_error"]));
    }

    return $key;
}
