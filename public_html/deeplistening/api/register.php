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

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$name  = trim($data['name'] ?? '');
$email = strtolower(trim($data['email'] ?? ''));
$pass  = $data['password'] ?? '';

if (!$name || !$email || !$pass) {
    http_response_code(400);
    echo json_encode(['error' => 'Заполните все поля']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Некорректный email']);
    exit;
}
if (mb_strlen($pass) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'Пароль минимум 6 символов']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Этот email уже зарегистрирован']);
        exit;
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
    $stmt->execute([$name, $email, $hash]);
    $user_id = $db->lastInsertId();

    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare('INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY))');
    $stmt->execute([$user_id, $token]);

    echo json_encode(['token' => $token, 'name' => $name, 'email' => $email]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка сервера: ' . $e->getMessage()]);
}
