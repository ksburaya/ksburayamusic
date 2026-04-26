<?php
$log = __DIR__ . '/forgot_debug.log';
file_put_contents($log, '--- ' . date('Y-m-d H:i:s') . ' ---' . PHP_EOL, FILE_APPEND);
file_put_contents($log, 'step: started' . PHP_EOL, FILE_APPEND);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    file_put_contents($log, 'step: wrong method: ' . $_SERVER['REQUEST_METHOD'] . PHP_EOL, FILE_APPEND);
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

file_put_contents($log, 'step: POST received' . PHP_EOL, FILE_APPEND);

require_once 'config.php';

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
$email = strtolower(trim($data['email'] ?? ''));

file_put_contents($log, 'step: email = ' . $email . PHP_EOL, FILE_APPEND);

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    file_put_contents($log, 'step: invalid email' . PHP_EOL, FILE_APPEND);
    http_response_code(400);
    echo json_encode(['error' => 'Введите корректный email']);
    exit;
}

try {
    file_put_contents($log, 'step: connecting to DB' . PHP_EOL, FILE_APPEND);
    $db = getDB();
    file_put_contents($log, 'step: DB connected' . PHP_EOL, FILE_APPEND);

    $stmt = $db->prepare('SELECT id, name FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    file_put_contents($log, 'step: user found = ' . ($user ? $user['name'] : 'no') . PHP_EOL, FILE_APPEND);

    if (!$user) {
        file_put_contents($log, 'step: user not found, returning ok' . PHP_EOL, FILE_APPEND);
        echo json_encode(['ok' => true]);
        exit;
    }

    $stmt = $db->prepare('DELETE FROM password_resets WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    file_put_contents($log, 'step: old tokens deleted' . PHP_EOL, FILE_APPEND);

    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))');
    $stmt->execute([$user['id'], $token]);
    file_put_contents($log, 'step: new token created = ' . $token . PHP_EOL, FILE_APPEND);

    $reset_url = 'https://ksburayamusic.ru/journal/reset.html?token=' . $token;
    file_put_contents($log, 'step: reset_url = ' . $reset_url . PHP_EOL, FILE_APPEND);

    $to      = $email;
    $subject = 'Sbros parolya - Kvantovoe Uho';
    $message = "Zdravstvuyte, {$user['name']}!\r\n\r\n"
             . "Ssylka dlya sbros parolya:\r\n"
             . $reset_url . "\r\n\r\n"
             . "Ssylka deystvitelna 1 chas.\r\n\r\n"
             . "Kvantovoe Uho";
    $headers = "From: journal@ksburayamusic.ru\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

    file_put_contents($log, 'step: calling mail()' . PHP_EOL, FILE_APPEND);
    $result = mail($to, $subject, $message, $headers);
    file_put_contents($log, 'step: mail() result = ' . ($result ? 'true' : 'false') . PHP_EOL, FILE_APPEND);

    echo json_encode(['ok' => true, 'mail_result' => $result]);

} catch (Exception $e) {
    file_put_contents($log, 'step: EXCEPTION = ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка сервера: ' . $e->getMessage()]);
}

file_put_contents($log, 'step: done' . PHP_EOL, FILE_APPEND);

