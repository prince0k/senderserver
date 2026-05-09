<?php

require __DIR__ . "/logger.php";
require __DIR__ . "/config/security.php";

header("Content-Type: application/json");

ini_set("display_errors", 0);
ini_set("log_errors", 1);
error_reporting(E_ALL);

/* =========================================================
   SECURITY
========================================================= */

$INTERNAL_KEY = getInternalKey();

if (
    empty($_SERVER["HTTP_X_INTERNAL_KEY"]) ||
    !hash_equals($INTERNAL_KEY, $_SERVER["HTTP_X_INTERNAL_KEY"])
) {
    http_response_code(403);
    echo json_encode(["error" => "forbidden"]);
    exit;
}

/* =========================================================
   FILE CHECK
========================================================= */

if (!isset($_FILES["image"])) {
    http_response_code(400);
    echo json_encode(["error" => "file_missing"]);
    exit;
}

$file = $_FILES["image"];

if ($file["error"] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["error" => "upload_failed"]);
    exit;
}

/* =========================================================
   VALIDATE EXTENSION
========================================================= */

$allowed = ["jpg","jpeg","png","gif","webp"];

$ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));

if (!in_array($ext, $allowed, true)) {
    http_response_code(400);
    echo json_encode(["error" => "invalid_file_type"]);
    exit;
}

/* =========================================================
   USE ORIGINAL FILE NAME (SANITIZED)
========================================================= */

$originalName = basename($file["name"]);

/* remove dangerous characters */
$originalName = preg_replace("/[^a-zA-Z0-9._-]/", "_", $originalName);

$safeName = $originalName;

/* avoid overwrite */
if (file_exists(__DIR__ . "/images/" . $safeName)) {
    $safeName = time() . "_" . $safeName;
}

/* =========================================================
   UPLOAD DIR
========================================================= */

$uploadDir = "/var/www/html/images/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

/* =========================================================
   USE ORIGINAL FILE NAME (SANITIZED)
========================================================= */

$originalName = basename($file["name"]);
$originalName = preg_replace("/[^a-zA-Z0-9._-]/", "_", $originalName);

$safeName = $originalName;

/* avoid overwrite */
if (file_exists($uploadDir . $safeName)) {
    $safeName = time() . "_" . $safeName;
}

$target = $uploadDir . $safeName;

/* =========================================================
   MOVE FILE
========================================================= */

if (!move_uploaded_file($file["tmp_name"], $target)) {

    http_response_code(500);

    echo json_encode([
        "error" => "save_failed"
    ]);

    exit;
}

/* =========================================================
   LOG
========================================================= */

log_line("upload.log", "IMAGE_UPLOADED", [
    "file" => $safeName,
    "ip"   => $_SERVER["REMOTE_ADDR"] ?? "unknown"
]);

/* =========================================================
   RESPONSE
========================================================= */

echo json_encode([
    "status" => "success",
    "file"   => $safeName,
    "url"    => "/images/" . $safeName
]);

exit;
