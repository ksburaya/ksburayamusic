<?php
$result = mail(
    'ksburaya@gmail.com',
    'Test from Beget',
    'Hello from journal',
    'From: journal@ksburayamusic.ru'
);
echo json_encode(['result' => $result]);

