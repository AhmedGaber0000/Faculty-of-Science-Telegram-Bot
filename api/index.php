<?php

define('LOG_FILE', __DIR__ . '/bot_activity.log');

function logActivity($message) {
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[" . $timestamp . "] - " . $message . "\n";
    file_put_contents(LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Sends a request to the Telegram Bot API.
 */

$API_URL = 'https://api.telegram.org/bot' . $BOT_TOKEN . '/';

function apiRequest($method, $parameters) {
    global $API_URL; // Get the global API URL variable

    if (!is_string($method)) {
        logActivity("API Error: Method name must be a string");
        return false;
    }
    if (!$parameters) {
        $parameters = array();
    } elseif (!is_array($parameters)) {
        logActivity("API Error: Parameters must be an array");
        return false;
    }

    $url = $API_URL . $method . '?' . http_build_query($parameters);
    $handle = curl_init($url);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($handle, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($handle);
    
    if ($response === false) {
        $error = curl_error($handle);
        logActivity("cURL Error for method '{$method}': " . $error);
        curl_close($handle);
        return false; // Explicitly return false on error
    }
    
    curl_close($handle);
    return $response;

}

/**
 * Sets the Telegram webhook. This function should only be run manually by the admin.
 */
function startWebhook($Host_URL) {

    logActivity("Attempting to set webhook to: " . $Host_URL);
    $response_json = apiRequest("setWebhook", ['url' => $Host_URL]);
    $response_data = json_decode($response_json, true);

    if ($response_data && $response_data['ok'] === true) {
        $log_message = "SUCCESS: Webhook set successfully. Response: " . $response_json;
        echo "<h1>Success!</h1><p>Webhook set successfully!</p>";
    } else {
        $log_message = "ERROR: Failed to set webhook. Response: " . $response_json;
        echo "<h1>Error!</h1><p>Failed to set webhook. Check the `bot_activity.log` file for details.</p>";
    }
    logActivity($log_message);
}

function sendMessage($chat_id, $text, $reply_markup = null) {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($reply_markup) {
        $params['reply_markup'] = $reply_markup;
    }
    apiRequest("sendMessage", $params);
}

function forwardMessage($to_chat_id, $from_chat_id, $message_id) {
    apiRequest("forwardMessage", [
        'chat_id' => $to_chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id,
    ]);
}

function handleMessage($message, $ADMIN_USER_ID) {
    $chat_id = $message['chat']['id'];
    $text = isset($message['text']) ? $message['text'] : ''; // Handle cases with no text (e.g., file forwards)
    $user_id = $message['from']['id'];
    $username = isset($message['from']['username']) ? $message['from']['username'] : 'N/A';

    // --- LOGGING ---: Log the incoming message
    logActivity("Message from UserID: {$user_id} (@{$username}), ChatID: {$chat_id}, Text: '{$text}'");


    // --- USER COMMANDS ---
    switch ($text) {
        case '/start':
            $reply = "Welcome! Use the /files command to see the available subjects.";
            sendMessage($chat_id, $reply);
            break;


        default:
            if (substr($text, 0, 1) === '/') { // It's a command but not one we know
                 logActivity("User {$user_id} sent unknown command: {$text}");
                 sendMessage($chat_id, "I don't recognize that command. Try /files.");
            }
            break;
    }
}


// ------ FUNCTION TO HANDLE BUTTON CLICKS (CALLBACKS) ------
function handleCallback($callback_query, $ADMIN_USER_ID) {

    $chat_id = $callback_query['message']['chat']['id'];
    $user_id = $callback_query['from']['id'];
    $username = isset($callback_query['from']['username']) ? $callback_query['from']['username'] : 'N/A';
    $callback_data = $callback_query['data'];

    // --- LOGGING ---: Log the button press
    logActivity("Callback from UserID: {$user_id} (@{$username}), Data: '{$callback_data}'");
    
    apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_query['id']]);

    list($prefix, $data) = explode('_', $callback_data, 2);
}


require_once 'config.php'; // Use require_once for essential files

if (isset($_GET['action']) && $_GET['action'] == 'set_webhook') {
    // You need to pass the URL from your config file here
    startWebhook($Host_URL); 
    exit(); // Stop the script after setting the webhook.
}

$ADMIN_USER_ID = 5833709924; 

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
// $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
// if ($conn->connect_error) {
//     logActivity("CRITICAL: Database connection failed: " . $conn->connect_error);
//     exit("DB connection error.");
// }

// ------ Decode and Route the Update ------
$update = json_decode($raw_update, TRUE);

if (isset($update["callback_query"])) {
    handleCallback($update["callback_query"], $ADMIN_USER_ID);
} elseif (isset($update["message"])) {
    handleMessage($update["message"], $ADMIN_USER_ID);
} else {
    logActivity("WARNING: Unhandled update type received.");
}

// ------ Close Database Connection ------
// $conn->close();

?>