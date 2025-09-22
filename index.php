<?php

require_once 'functions.php'; // Use require_once for essential files
require_once 'config.php'; // Use require_once for essential files


if (isset($_GET['action'])) {
    if ($_GET['action'] == 'set_webhook') {
        startWebhook(); // Call the function to set the webhook
        exit();         // And stop the script immediately after.
    }
}

// --- Regular Bot Logic for Telegram Updates ---
// This part only runs if the URL does not contain "?action=..."
$raw_update = file_get_contents("php://input");

// If there's no input from Telegram, do nothing.
// This prevents errors when the script is loaded in a browser.
if (empty($raw_update)) {
    exit();
}

logActivity("--- NEW REQUEST ---");
logActivity("Raw Update: " . $raw_update);

// ------ Connect to Database ------
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    logActivity("CRITICAL: Database connection failed: " . $conn->connect_error);
    exit("DB connection error.");
}

// ------ Decode and Route the Update ------
$update = json_decode($raw_update, TRUE);

if (isset($update["callback_query"])) {
    handleCallback($update["callback_query"]);
} elseif (isset($update["message"])) {
    handleMessage($update["message"]);
} else {
    logActivity("WARNING: Unhandled update type received.");
}

// ------ Close Database Connection ------
$conn->close();

?>