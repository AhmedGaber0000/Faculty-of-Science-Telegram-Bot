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

function handleMessage($message) {
    global $conn, $ADMIN_USER_ID;

    $chat_id = $message['chat']['id'];
    $text = isset($message['text']) ? $message['text'] : ''; // Handle cases with no text (e.g., file forwards)
    $user_id = $message['from']['id'];
    $username = isset($message['from']['username']) ? $message['from']['username'] : 'N/A';

    // --- LOGGING ---: Log the incoming message
    logActivity("Message from UserID: {$user_id} (@{$username}), ChatID: {$chat_id}, Text: '{$text}'");

    // --- ADMIN: Adding a file ---
    if ($user_id == $ADMIN_USER_ID && isset($message['forward_from_chat'])) {
        logActivity("Admin action: Received forwarded message for file addition.");
        $caption = isset($message['caption']) ? $message['caption'] : '';
        if ($caption && strpos($caption, ';') !== false) {
            list($subject, $file_name) = explode(';', $caption, 2);
            $subject = trim($subject);
            $file_name = trim($file_name);
            
            $forwarded_msg_id = $message['forward_from_message_id'];
            $forwarded_chat_id = $message['forward_from_chat']['id'];

            $stmt = $conn->prepare("INSERT INTO files (subject, file_name, message_id, chat_id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssii", $subject, $file_name, $forwarded_msg_id, $forwarded_chat_id);
            
            if ($stmt->execute()) {
                $log_msg = "SUCCESS: Admin added file '{$file_name}' to subject '{$subject}'.";
                sendMessage($chat_id, "✅ File added successfully.");
            } else {
                $log_msg = "ERROR: Failed to add file to database. Error: " . $stmt->error;
                sendMessage($chat_id, "❌ Database error while adding file.");
            }
            logActivity($log_msg);
            $stmt->close();
        } else {
            logActivity("Admin error: Forwarded file is missing a valid caption.");
            sendMessage($chat_id, "⚠️ Please forward the file with a caption in the format: Subject; File Name");
        }
        return;
    }

    // --- USER COMMANDS ---
    switch ($text) {
        case '/start':
            $reply = "Welcome! Use the /files command to see the available subjects.";
            sendMessage($chat_id, $reply);
            break;

        case '/files':
            $result = $conn->query("SELECT DISTINCT subject FROM files ORDER BY subject ASC");
            if (!$result) {
                logActivity("ERROR: Database query for subjects failed: " . $conn->error);
                sendMessage($chat_id, "Sorry, there was an error fetching subjects.");
                return;
            }
            $subjects = [];
            while ($row = $result->fetch_assoc()) {
                $subjects[] = $row['subject'];
            }

            if (empty($subjects)) {
                sendMessage($chat_id, "Sorry, no files have been organized yet.");
                logActivity("Info: User requested files, but none are in the database.");
                return;
            }

            $keyboard = [];
            foreach ($subjects as $subject) {
                $keyboard[][] = ['text' => $subject, 'callback_data' => 'subj_' . $subject];
            }
            
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            sendMessage($chat_id, "Please select a subject:", $reply_markup);
            logActivity("Displayed subject list to UserID: {$user_id}");
            break;

        // Add a default case for unknown commands
        default:
            if (substr($text, 0, 1) === '/') { // It's a command but not one we know
                 logActivity("User {$user_id} sent unknown command: {$text}");
                 sendMessage($chat_id, "I don't recognize that command. Try /files.");
            }
            break;
    }
}


// ------ FUNCTION TO HANDLE BUTTON CLICKS (CALLBACKS) ------
function handleCallback($callback_query) {
    global $conn;

    $chat_id = $callback_query['message']['chat']['id'];
    $user_id = $callback_query['from']['id'];
    $username = isset($callback_query['from']['username']) ? $callback_query['from']['username'] : 'N/A';
    $callback_data = $callback_query['data'];

    // --- LOGGING ---: Log the button press
    logActivity("Callback from UserID: {$user_id} (@{$username}), Data: '{$callback_data}'");
    
    apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_query['id']]);

    list($prefix, $data) = explode('_', $callback_data, 2);

    if ($prefix == 'subj') {
        $subject = $data;
        $stmt = $conn->prepare("SELECT id, file_name FROM files WHERE subject = ? ORDER BY file_name ASC");
        $stmt->bind_param("s", $subject);
        $stmt->execute();
        $result = $stmt->get_result();
        // ... (rest of the callback logic from previous example)
    } elseif ($prefix == 'file') {
        $file_id_in_db = (int)$data;
        $stmt = $conn->prepare("SELECT message_id, chat_id FROM files WHERE id = ?");
        $stmt->bind_param("i", $file_id_in_db);
        $stmt->execute();
        $result = $stmt->get_result();
        $file = $result->fetch_assoc();

        if ($file) {
            logActivity("Forwarding file (DB ID: {$file_id_in_db}) to UserID: {$user_id}");
            forwardMessage($chat_id, $file['chat_id'], $file['message_id']);
        } else {
            logActivity("ERROR: User {$user_id} requested a file (DB ID: {$file_id_in_db}) that was not found.");
            sendMessage($chat_id, "Sorry, I couldn't find that file.");
        }
    } elseif ($prefix == 'back') {
        logActivity("User {$user_id} clicked 'Back to Subjects'");
        handleMessage(['chat' => ['id' => $chat_id], 'text' => '/files', 'from' => ['id' => $user_id]]);
    }
}


require_once 'config.php'; // Use require_once for essential files

startWebhook($Host_URL)
$API_URL = 'https://api.telegram.org/bot' . $BOT_TOKEN . '/';
$ADMIN_USER_ID = 5833709924; 


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
// $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
// if ($conn->connect_error) {
//     logActivity("CRITICAL: Database connection failed: " . $conn->connect_error);
//     exit("DB connection error.");
// }

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
// $conn->close();

?>