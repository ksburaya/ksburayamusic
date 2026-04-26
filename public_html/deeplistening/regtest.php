<?php
header('Content-Type: application/json');
echo "php works" . PHP_VERSION;

require_once 'api/config.php';
try {
    $db = getDB();
    $token = bin2hex(random_bytes(32));
    $hash = password_hash('test123', PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
    $stmt->execute(['test', 'test@test.com', $hash]);
    echo json_encode(['ok' => true, 'token' => $token]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
