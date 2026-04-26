<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once 'config.php';

if (!defined('BOT_SECRET') || !BOT_SECRET) {
    http_response_code(500);
    echo json_encode(['error' => 'BOT_SECRET not configured']);
    exit;
}

$data        = json_decode(file_get_contents('php://input'), true) ?? [];
$telegram_id = (int)($data['telegram_id'] ?? 0);
$bot_secret  = $data['bot_secret'] ?? '';
$name        = trim($data['name'] ?? '');

if (!hash_equals(BOT_SECRET, $bot_secret)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid bot secret']);
    exit;
}

if (!$telegram_id) {
    http_response_code(400);
    echo json_encode(['error' => 'telegram_id required']);
    exit;
}

try {
    $db = getDB();

    // Добавляем колонку telegram_id если ещё нет
    try { $db->exec('ALTER TABLE users ADD COLUMN telegram_id BIGINT NULL UNIQUE'); }
    catch (PDOException $e) {}

    // Находим или создаём пользователя
    $stmt = $db->prepare('SELECT id, name FROM users WHERE telegram_id = ?');
    $stmt->execute([$telegram_id]);
    $user = $stmt->fetch();

    if (!$user) {
        $tg_name     = $name ?: ('tg_' . $telegram_id);
        $fake_email  = 'tg_' . $telegram_id . '@telegram.local';
        $db->prepare('INSERT INTO users (name, email, password_hash, telegram_id) VALUES (?, ?, ?, ?)')
           ->execute([$tg_name, $fake_email, '', $telegram_id]);
        $user_id   = (int)$db->lastInsertId();
        $user_name = $tg_name;
    } else {
        $user_id   = (int)$user['id'];
        $user_name = $user['name'];
    }

    // Берём существующий действующий токен или создаём новый
    $stmt = $db->prepare(
        'SELECT token FROM sessions WHERE user_id = ? AND expires_at > NOW()
         ORDER BY expires_at DESC LIMIT 1'
    );
    $stmt->execute([$user_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $token = $existing['token'];
    } else {
        $token = bin2hex(random_bytes(32));
        $db->prepare(
            'INSERT INTO sessions (user_id, token, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY))'
        )->execute([$user_id, $token]);
    }

    // Есть ли у пользователя записи?
    $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM entries WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $has_entries = (int)$stmt->fetch()['cnt'] > 0;

    echo json_encode([
        'token'       => $token,
        'has_entries' => $has_entries,
        'name'        => $user_name,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
