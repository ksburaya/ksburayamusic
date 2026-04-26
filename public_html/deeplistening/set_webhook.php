<?php
require_once __DIR__ . '/api/config.php';

$webhookUrl = 'https://ksburayamusic.ru/deeplistening/bot.php';

$ch = curl_init("https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/setWebhook");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['url' => $webhookUrl]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    "certificate"          => 'C:\Users\Asus\Documents\KSBURAYA\ksburayamusic.ru\public_html\deeplistening\tmp\cert.pem', // Path to your crt.pem
]);
$response = curl_exec($ch);
curl_close($ch);

header('Content-Type: text/plain; charset=utf-8');
echo "Webhook URL: {$webhookUrl}\n\n";
$result = json_decode($response, true);
echo $result['ok'] ? "OK: " . $result['description'] : "ERROR: " . $result['description'];
echo "\n\nРезультат: " . $response;
