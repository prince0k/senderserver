<?php

// Load from environment (recommended)
function getInternalKey() {
    $key = getenv("SENDER_INTERNAL_KEY");

    if (!$key) {
        // Enforce secure setup, prevent silent fallbacks
        http_response_code(500);
        exit(json_encode(["error" => "internal_server_error", "message" => "CRITICAL: SENDER_INTERNAL_KEY environment variable is not configured."]));
    }

    return $key;
}
