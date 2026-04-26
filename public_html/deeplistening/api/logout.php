<?php
require_once 'config.php';
cors();

$token = $_COOKIE['qs_token'] ?? '';
if ($token) {
    $stmt = db()->prepare('DELETE FROM sessions WHERE token = ?');
    $stmt->execute([$token]);
}

setcookie('qs_token', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
json_out(['ok' => true]);
