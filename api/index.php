<?php

// Use Vercel's built-in logging by writing to stderr
function logActivity($message) {
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[" . $timestamp . "] - " . $message . "\n";
    file_put_contents('php://stderr', $log_entry);
}

// --- CONFIGURATION ---
// Load all secrets from Vercel Environment Variables
// Ensure these are set in your Vercel project settings!
$BOT_TOKEN = $_ENV['BOT_TOKEN'];
$ADMIN_USER_ID = $_ENV['ADMIN_USER_ID'];
$HOST_URL = $_ENV['HOST_URL'];

// Build the API URL (This is not a secret)
$API_URL = 'https://api.telegram.org/bot' . $BOT_TOKEN . '/';


/**
 * Sends a request to the Telegram Bot API using a passed API URL.
 * This function no longer uses a global variable.
 */
function apiRequest($method, $parameters, $apiUrl) {
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

    $url = $apiUrl . $method . '?' . http_build_query($parameters);
    $handle = curl_init($url);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($handle, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($handle);

    if ($response === false) {
        $error = curl_error($handle);
        logActivity("cURL Error for method '{$method}': " . $error);
        curl_close($handle);
        return false;
    }

    curl_close($handle);
    return $response;
}

/**
 * Sets the Telegram webhook.
 */
function startWebhook($hostUrl, $apiUrl) {
    logActivity("Attempting to set webhook to: " . $hostUrl);
    $response_json = apiRequest("setWebhook", ['url' => $hostUrl], $apiUrl);
    $response_data = json_decode($response_json, true);

    if ($response_data && $response_data['ok'] === true) {
        $log_message = "SUCCESS: Webhook set successfully. Response: " . $response_json;
        echo "<h1>Success!</h1><p>Webhook set successfully!</p>";
    } else {
        $log_message = "ERROR: Failed to set webhook. Response: " . $response_json;
        echo "<h1>Error!</h1><p>Failed to set webhook. Check the Logs on your Vercel dashboard.</p>";
    }
    logActivity($log_message);
}

function sendMessage($chat_id, $text, $apiUrl, $reply_markup = null) {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($reply_markup) {
        $params['reply_markup'] = $reply_markup;
    }
    apiRequest("sendMessage", $params, $apiUrl);
}

function handleMessage($message, $adminUserId, $apiUrl) {
    $chat_id = $message['chat']['id'];
    $text = isset($message['text']) ? $message['text'] : '';
    $user_id = $message['from']['id'];
    $username = isset($message['from']['username']) ? $message['from']['username'] : 'N/A';

    logActivity("Message from UserID: {$user_id} (@{$username}), ChatID: {$chat_id}, Text: '{$text}'");

    switch ($text) {
        case '/start':
            $reply = "Welcome! Use the /files command to see the available subjects.";
            sendMessage($chat_id, $reply, $apiUrl);
            break;
        default:
            if (substr($text, 0, 1) === '/') {
                 logActivity("User {$user_id} sent unknown command: {$text}");
                 sendMessage($chat_id, "I don't recognize that command. Try /files.", $apiUrl);
            }
            break;
    }
}

function handleCallback($callback_query, $adminUserId, $apiUrl) {
    $chat_id = $callback_query['message']['chat']['id'];
    $user_id = $callback_query['from']['id'];
    $username = isset($callback_query['from']['username']) ? $callback_query['from']['username'] : 'N/A';
    $callback_data = $callback_query['data'];

    logActivity("Callback from UserID: {$user_id} (@{$username}), Data: '{$callback_data}'");
    apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_query['id']], $apiUrl);
    // ... rest of your callback logic ...
}


// --- EXECUTION START ---

// 1. WEBHOOK SETUP LOGIC
// This part only runs if you visit "your-url.vercel.app/api/bot.php?action=set_webhook"
if (isset($_GET['action']) && $_GET['action'] == 'set_webhook') {
    echo "ahmed";
    startWebhook($HOST_URL, $API_URL);
    exit();
}


// 2. REGULAR BOT LOGIC (HANDLES UPDATES FROM TELEGRAM)
$raw_update = file_get_contents("php://input");
if (empty($raw_update)) {
    exit();
}

logActivity("--- NEW REQUEST ---");
logActivity("Raw Update: " . $raw_update);

$update = json_decode($raw_update, TRUE);

if (isset($update["callback_query"])) {
    handleCallback($update["callback_query"], $ADMIN_USER_ID, $API_URL);
} elseif (isset($update["message"])) {
    handleMessage($update["message"], $ADMIN_USER_ID, $API_URL);
} else {
    logActivity("WARNING: Unhandled update type received.");
}

?>