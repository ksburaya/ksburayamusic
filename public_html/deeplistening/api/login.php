<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

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

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

// Лог для дебага
file_put_contents(__DIR__ . '/login_debug.log',
    'raw: ' . $raw . PHP_EOL .
    'email: ' . ($data['email'] ?? 'null') . PHP_EOL .
    'pass_len: ' . strlen($data['password'] ?? '') . PHP_EOL,
    FILE_APPEND
);

$email = strtolower(trim($data['email'] ?? ''));
$pass  = $data['password'] ?? '';

if (!$email || !$pass) {
    http_response_code(400);
    echo json_encode(['error' => 'Заполните все поля']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    file_put_contents(__DIR__ . '/login_debug.log',
    'user_found: ' . ($user ? 'yes' : 'no') . PHP_EOL .
    'verify: ' . ($user ? var_export(password_verify($pass, $user['password_hash']), true) : 'n/a') . PHP_EOL,
    FILE_APPEND);


    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Пользователь не найден: ' . $email]);
        exit;
    }

    if (!password_verify($pass, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Неверный пароль']);
        exit;
    }

    file_put_contents(__DIR__ . '/login_debug.log',
    'trying to create session for user_id: ' . $user['id'] . PHP_EOL,
    FILE_APPEND);


    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare('INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY))');
    $stmt->execute([$user['id'], $token]);

    echo json_encode(['token' => $token, 'name' => $user['name'], 'email' => $user['email']]);
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/login_debug.log',
        'EXCEPTION: ' . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка сервера: ' . $e->getMessage()]);
}