<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']); exit;
}

$data       = json_decode(file_get_contents('php://input'), true) ?? [];
$bot_secret = $data['bot_secret'] ?? '';

if (!hash_equals(BOT_SECRET, $bot_secret)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']); exit;
}

try {
    $db   = getDB();
    $stmt = $db->query('SELECT telegram_id FROM users WHERE telegram_id IS NOT NULL AND telegram_id != 0');
    $ids  = array_map('intval', array_column($stmt->fetchAll(), 'telegram_id'));
    echo json_encode(['chat_ids' => $ids]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
