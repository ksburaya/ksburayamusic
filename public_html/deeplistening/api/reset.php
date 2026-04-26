<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

require_once 'config.php';

$data     = json_decode(file_get_contents('php://input'), true);
$token    = trim($data['token'] ?? '');
$password = $data['password'] ?? '';

if (!$token) { http_response_code(400); echo json_encode(['error' => 'Токен не указан']); exit; }
if (mb_strlen($password) < 6) { http_response_code(400); echo json_encode(['error' => 'Пароль минимум 6 символов']); exit; }

try {
    $db = getDB();

    $stmt = $db->prepare('SELECT user_id FROM password_resets WHERE token = ? AND expires_at > NOW()');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(400);
        echo json_encode(['error' => 'Ссылка недействительна или истекла']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([$hash, $row['user_id']]);

    // Удаляем использованный токен
    $stmt = $db->prepare('DELETE FROM password_resets WHERE token = ?');
    $stmt->execute([$token]);

    // Инвалидируем все сессии
    $stmt = $db->prepare('DELETE FROM sessions WHERE user_id = ?');
    $stmt->execute([$row['user_id']]);

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка сервера: ' . $e->getMessage()]);
}

