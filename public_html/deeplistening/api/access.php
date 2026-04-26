<?php
require_once 'config.php';

// Проверка доступа пользователя к платному контенту
// Используется для марафонов: GET /api/access.php?key=marathon_march_2026
$user = get_user_from_token();
if (!$user) json_response(['error' => 'Необходима авторизация'], 401);

$key = trim($_GET['key'] ?? '');
if (!$key) {
    // Вернуть все доступы пользователя
    $db = getDB();
    $stmt = $db->prepare('SELECT access_key, expires_at FROM user_access WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW())');
    $stmt->execute([$user['id']]);
    json_response(['access' => $stmt->fetchAll()]);
}

$db = getDB();
$stmt = $db->prepare('SELECT id FROM user_access WHERE user_id = ? AND access_key = ? AND (expires_at IS NULL OR expires_at > NOW())');
$stmt->execute([$user['id'], $key]);
json_response(['has_access' => (bool)$stmt->fetch()]);
