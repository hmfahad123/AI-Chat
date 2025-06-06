<?php
require_once __DIR__ . '/vendor/autoload.php';

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.log');

// Configuration
define('TELEGRAM_TOKEN', getenv('TELEGRAM_TOKEN') ?: '7959528364:AAEj5j4R3r4BU7jRi_8TmLnnjOcwtJSoJoQ');
define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: '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');
define('CHANNEL_USERNAME', '@hmfahad61814');
define('CHANNEL_ID', '-1001234567890');
define('DB_PATH', __DIR__ . '/botdata.db');
define('FREE_DAILY_LIMIT', 100);
define('MAX_HISTORY', 15);

// Initialize database connection
try {
    $conn = new PDO("sqlite:" . DB_PATH);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create tables if they don't exist
    $conn->exec("
        CREATE TABLE IF NOT EXISTS users (
            user_id INTEGER PRIMARY KEY,
            username TEXT,
            messages_sent INTEGER DEFAULT 0,
            premium INTEGER DEFAULT 0,
            last_reset DATE
        )
    ");
    
    $conn->exec("
        CREATE TABLE IF NOT EXISTS chat_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            role TEXT,
            content TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed");
}

// Handle webhook setup
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['setwebhook'])) {
    $webhookUrl = $_GET['url'] ?? '';
    if ($webhookUrl) {
        $result = setTelegramWebhook(TELEGRAM_TOKEN, $webhookUrl);
        header('Content-Type: application/json');
        echo json_encode($result, JSON_PRETTY_PRINT);
    } else {
        echo "Please provide a URL parameter";
    }
    exit;
}

// Handle incoming updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input) {
        processUpdate($input, $conn);
    }
    http_response_code(200);
    exit;
}

// If no specific action, show basic info
echo "Telegram Bot is running!";

/**
 * Set Telegram webhook
 */
function setTelegramWebhook($token, $url) {
    $apiUrl = "https://api.telegram.org/bot{$token}/setWebhook";
    $params = ['url' => $url];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Process Telegram update
 */
function processUpdate($update, $conn) {
    if (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $userId = $message['from']['id'];
        $username = $message['from']['username'] ?? '';
        
        // Handle commands
        if (strpos($text, '/') === 0) {
            $command = strtok($text, ' ');
            switch ($command) {
                case '/start':
                    handleStartCommand($conn, $chatId, $userId, $username);
                    break;
                default:
                    sendMessage($chatId, "Unknown command. Type /start to begin.");
            }
        } else {
            // Handle regular messages
            handleUserMessage($conn, $chatId, $userId, $username, $text);
        }
    }
}

/**
 * Handle /start command
 */
function handleStartCommand($conn, $chatId, $userId, $username) {
    $user = getUser($conn, $userId, $username);
    
    if (!isUserJoinedChannel($userId)) {
        sendMessage($chatId, "❌ You must join our channel " . CHANNEL_USERNAME . " to use this bot.");
        return;
    }

    sendMessage($chatId, "✅ Welcome! You're verified.\nAsk me anything!");
}

/**
 * Handle regular user messages
 */
function handleUserMessage($conn, $chatId, $userId, $username, $text) {
    $user = getUser($conn, $userId, $username);
    
    if (!isUserJoinedChannel($userId)) {
        sendMessage($chatId, "❌ You must join our channel " . CHANNEL_USERNAME . " to continue using this bot.");
        return;
    }

    if (!checkUserLimit($conn, $userId)) {
        sendMessage($chatId, "🚫 Daily free limit reached.");
        return;
    }

    saveMessage($conn, $userId, 'user', $text);
    $history = getChatHistory($conn, $userId, MAX_HISTORY);

    try {
        $reply = openrouterChat($history, $text);
    } catch (Exception $e) {
        error_log("OpenRouter error: " . $e->getMessage());
        $reply = "⚠️ Sorry, I couldn't process your request right now.";
    }

    saveMessage($conn, $userId, 'assistant', $reply);
    incrementMessageCount($conn, $userId);
    sendMessage($chatId, $reply);
}

/**
 * Check if user has joined channel
 */
function isUserJoinedChannel($userId) {
    $apiUrl = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/getChatMember";
    $params = [
        'chat_id' => CHANNEL_ID,
        'user_id' => $userId
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    return isset($data['result']['status']) && 
           in_array($data['result']['status'], ['member', 'administrator', 'creator']);
}

/**
 * Get user from database or create new
 */
function getUser($conn, $userId, $username = null) {
    $stmt = $conn->prepare("SELECT user_id, username, messages_sent, premium, last_reset FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $stmt = $conn->prepare("INSERT INTO users(user_id, username) VALUES (?, ?)");
        $stmt->execute([$userId, $username]);
        return [
            'user_id' => $userId,
            'username' => $username,
            'messages_sent' => 0,
            'premium' => 0,
            'last_reset' => null
        ];
    }
    
    // Check for daily reset
    $today = date('Y-m-d');
    if ($user['last_reset'] != $today) {
        $stmt = $conn->prepare("UPDATE users SET messages_sent = 0, last_reset = ? WHERE user_id = ?");
        $stmt->execute([$today, $userId]);
        $user['messages_sent'] = 0;
        $user['last_reset'] = $today;
    }
    
    return $user;
}

/**
 * Increment user message count
 */
function incrementMessageCount($conn, $userId) {
    $stmt = $conn->prepare("UPDATE users SET messages_sent = messages_sent + 1 WHERE user_id = ?");
    $stmt->execute([$userId]);
}

/**
 * Save message to chat history
 */
function saveMessage($conn, $userId, $role, $content) {
    $stmt = $conn->prepare("INSERT INTO chat_history(user_id, role, content) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $role, $content]);
}

/**
 * Get chat history
 */
function getChatHistory($conn, $userId, $limit = MAX_HISTORY) {
    $stmt = $conn->prepare("SELECT role, content FROM chat_history WHERE user_id = ? ORDER BY id DESC LIMIT ?");
    $stmt->execute([$userId, $limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $history = [];
    foreach (array_reverse($rows) as $row) {
        $history[] = [
            'role' => $row['role'],
            'content' => $row['content']
        ];
    }
    return $history;
}

/**
 * Check user message limit
 */
function checkUserLimit($conn, $userId) {
    $stmt = $conn->prepare("SELECT messages_sent, premium FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        return false;
    }
    
    return $result['premium'] || $result['messages_sent'] < FREE_DAILY_LIMIT;
}

/**
 * Call OpenRouter API
 */
function openrouterChat($history, $userText) {
    $messages = array_merge($history, [['role' => 'user', 'content' => $userText]]);
    
    $headers = [
        "Authorization: Bearer " . OPENROUTER_API_KEY,
        "Content-Type: application/json"
    ];
    
    $payload = [
        "model" => "openai/gpt-3.5-turbo",
        "messages" => $messages
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://openrouter.ai/api/v1/chat/completions");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("OpenRouter API error: " . $response);
        throw new Exception("API request failed");
    }
    
    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'];
}

/**
 * Send Telegram message
 */
function sendMessage($chatId, $text) {
    $apiUrl = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/sendMessage";
    $params = [
        'chat_id' => $chatId,
        'text' => $text
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}