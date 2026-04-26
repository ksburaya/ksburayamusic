<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once 'config.php';

function get_token() {
    $auth = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) return trim($m[1]);
    return null;
}

function get_user($db) {
    $token = get_token();
    if (!$token) return null;
    $stmt = $db->prepare('SELECT u.* FROM users u JOIN sessions s ON s.user_id = u.id WHERE s.token = ? AND s.expires_at > NOW()');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

try {
    $db = getDB();
    $user = get_user($db);

    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Необходима авторизация', 'token_received' => get_token()]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare('SELECT * FROM entries WHERE user_id = ? ORDER BY saved_at DESC');
        $stmt->execute([$user['id']]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if (is_string($row['sounds'])) {
                $row['sounds'] = json_decode($row['sounds'], true);
            }
        }
        echo json_encode($rows);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $practice_id  = $data['practiceId'] ?? '';
        $duration_min = intval($data['durationMin'] ?? 0);
        $place        = trim($data['place'] ?? '');
        $sounds       = json_encode($data['sounds'] ?? []);
        $liked        = trim($data['liked'] ?? '');
        $disliked     = trim($data['disliked'] ?? '');
        $notes        = trim($data['notes'] ?? '');

        if (!$practice_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Не указана практика']);
            exit;
        }

        $stmt = $db->prepare('INSERT INTO entries (user_id, practice_id, duration_min, place, sounds, liked, disliked, notes, is_public, saved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())');
        $stmt->execute([$user['id'], $practice_id, $duration_min, $place, $sounds, $liked, $disliked, $notes]);
        echo json_encode(['id' => $db->lastInsertId(), 'status' => 'saved']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
