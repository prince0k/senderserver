<?php

// Load from environment (recommended)
function getInternalKey() {
    $key = getenv("SENDER_INTERNAL_KEY");

    if (!$key) {
        // Fallback for local development
        return "super_secret_global_key";
    }

    return $key;
}
