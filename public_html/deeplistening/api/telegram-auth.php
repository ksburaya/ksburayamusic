<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once 'config.php';

// Set your Telegram bot token in config.php: define('TELEGRAM_BOT_TOKEN', 'YOUR_BOT_TOKEN');
if (!defined('TELEGRAM_BOT_TOKEN') || !TELEGRAM_BOT_TOKEN) {
    http_response_code(500);
    echo json_encode(['error' => 'Telegram bot token not configured']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
$initData = $data['initData'] ?? '';

if (!$initData) {
    http_response_code(400);
    echo json_encode(['error' => 'initData отсутствует']);
    exit;
}

// Validate Telegram initData signature
parse_str($initData, $params);
$hash = $params['hash'] ?? '';
unset($params['hash']);

ksort($params);
$dataCheckString = implode("\n", array_map(
    fn($k, $v) => "$k=$v",
    array_keys($params),
    array_values($params)
));

$secretKey = hash_hmac('sha256', TELEGRAM_BOT_TOKEN, 'WebAppData', true);
$expectedHash = bin2hex(hash_hmac('sha256', $dataCheckString, $secretKey, true));

if (!hash_equals($expectedHash, $hash)) {
    http_response_code(401);
    echo json_encode(['error' => 'Подпись Telegram недействительна']);
    exit;
}

// Check auth_date (reject data older than 24 hours)
$authDate = (int)($params['auth_date'] ?? 0);
if (time() - $authDate > 86400) {
    http_response_code(401);
    echo json_encode(['error' => 'Данные Telegram устарели']);
    exit;
}

$user = json_decode($params['user'] ?? '{}', true);
$tgId = (int)($user['id'] ?? 0);
if (!$tgId) {
    http_response_code(400);
    echo json_encode(['error' => 'Не удалось получить Telegram user ID']);
    exit;
}

$tgName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if (!$tgName) $tgName = $user['username'] ?? ('tg_' . $tgId);

try {
    $db = getDB();

    // Find existing user by telegram_id
    $stmt = $db->prepare('SELECT * FROM users WHERE telegram_id = ?');
    $stmt->execute([$tgId]);
    $dbUser = $stmt->fetch();

    if (!$dbUser) {
        // Create new user (no email, no password)
        $stmt = $db->prepare('INSERT INTO users (name, email, password_hash, telegram_id) VALUES (?, ?, ?, ?)');
        $fakeEmail = 'tg_' . $tgId . '@telegram.local';
        $stmt->execute([$tgName, $fakeEmail, '', $tgId]);
        $userId = $db->lastInsertId();
        $userName = $tgName;
        $userEmail = $fakeEmail;
    } else {
        $userId = $dbUser['id'];
        $userName = $dbUser['name'];
        $userEmail = $dbUser['email'];
    }

    $stmt = $db->prepare('SELECT token FROM sessions WHERE user_id = ? AND expires_at > NOW() ORDER BY expires_at DESC LIMIT 1');
    $stmt->execute([$userId]);
    $existing = $stmt->fetch();
    if ($existing) {
        $token = $existing['token'];
    } else {
        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare('INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY))');
        $stmt->execute([$userId, $token]);
    }

    echo json_encode(['token' => $token, 'name' => $userName, 'email' => $userEmail]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка сервера: ' . $e->getMessage()]);
}
