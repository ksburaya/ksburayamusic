<?php
// Telegram Bot webhook — Квантовое Ухо · Звуковой дневник
require_once __DIR__ . '/api/config.php';

define('TG_API', 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/');

// ── Telegram API ──────────────────────────────────────────────────────────────

function tgSend(string $method, array $params): array {
    $ch = curl_init(TG_API . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true) ?? [];
}

function sendMessage(int $chatId, string $text, array $extra = []): void {
    tgSend('sendMessage', array_merge([
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ], $extra));
}

// ── Keyboards ─────────────────────────────────────────────────────────────────

function kbDurations(): array {
    return ['inline_keyboard' => [[
        ['text' => '5 мин',  'callback_data' => 'dur_5'],
        ['text' => '10 мин', 'callback_data' => 'dur_10'],
        ['text' => '15 мин', 'callback_data' => 'dur_15'],
        ['text' => '20 мин', 'callback_data' => 'dur_20'],
    ]]];
}

function kbDone(): array {
    return ['keyboard' => [[['text' => '✅ Готово']]], 'resize_keyboard' => true, 'one_time_keyboard' => true];
}

function kbSkip(): array {
    return ['keyboard' => [[['text' => '⏭ Пропустить']]], 'resize_keyboard' => true, 'one_time_keyboard' => true];
}

function kbRemove(): array {
    return ['remove_keyboard' => true];
}

// ── Session (FSM state) ───────────────────────────────────────────────────────

function getSession(int $tgId): array {
    $stmt = getDB()->prepare('SELECT step, data FROM bot_sessions WHERE telegram_id = ?');
    $stmt->execute([$tgId]);
    $row = $stmt->fetch();
    if (!$row) return ['step' => 'idle', 'data' => []];
    return ['step' => $row['step'], 'data' => json_decode($row['data'] ?? '{}', true) ?: []];
}

function setSession(int $tgId, string $step, array $data): void {
    getDB()->prepare(
        'INSERT INTO bot_sessions (telegram_id, step, data) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE step = VALUES(step), data = VALUES(data)'
    )->execute([$tgId, $step, json_encode($data, JSON_UNESCAPED_UNICODE)]);
}

// ── Users & entries ───────────────────────────────────────────────────────────

function findOrCreateUser(int $tgId, string $name): int {
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE telegram_id = ?');
    $stmt->execute([$tgId]);
    $user = $stmt->fetch();
    if ($user) return (int)$user['id'];

    $db->prepare('INSERT INTO users (name, email, password_hash, telegram_id) VALUES (?, ?, ?, ?)')
       ->execute([$name, 'tg_' . $tgId . '@telegram.local', '', $tgId]);
    return (int)$db->lastInsertId();
}

function saveEntry(int $userId, array $d): int {
    $db = getDB();
    $db->prepare(
        'INSERT INTO entries (user_id, practice_id, duration_min, place, sounds, liked, disliked, notes, is_public, saved_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())'
    )->execute([
        $userId,
        'listen-space',
        (int)($d['duration'] ?? 0),
        $d['place']    ?? '',
        json_encode([
            'front' => $d['sounds']['front'] ?? '',
            'right' => $d['sounds']['right'] ?? '',
            'back'  => $d['sounds']['back']  ?? '',
            'left'  => $d['sounds']['left']  ?? '',
        ], JSON_UNESCAPED_UNICODE),
        $d['liked']    ?? '',
        $d['disliked'] ?? '',
        '',
    ]);
    return (int)$db->lastInsertId();
}

// ── Step prompts ──────────────────────────────────────────────────────────────

function promptChooseDuration(int $chatId): void {
    sendMessage($chatId,
        "🎧 <b>Слушание пространства</b>\n\n" .
        "Займите удобное положение, сделайте три глубоких вдоха и закройте глаза.\n" .
        "Слушайте пространство без попытки оценить происходящее.\n\n" .
        "Выберите длительность практики:",
        ['reply_markup' => kbDurations()]
    );
}

function promptWaitingDone(int $chatId, int $duration): void {
    sendMessage($chatId,
        "⏱ Установлено <b>{$duration} минут</b>.\n\n" .
        "Позвольте звукам приходить и уходить, просто замечая их.\n\n" .
        "Когда практика завершится — нажмите кнопку.",
        ['reply_markup' => kbDone()]
    );
}

function promptPlace(int $chatId): void {
    sendMessage($chatId,
        "📍 <b>Где вы находились?</b>\n\n<i>Например: парк, квартира, залив, крыша…</i>",
        ['reply_markup' => kbSkip()]
    );
}

function promptSound(int $chatId, string $dir): void {
    $labels = [
        'front' => '⬆️ СПЕРЕДИ',
        'right' => '➡️ СПРАВА',
        'back'  => '⬇️ СЗАДИ',
        'left'  => '⬅️ СЛЕВА',
    ];
    sendMessage($chatId,
        "🔊 Звуки <b>{$labels[$dir]}</b>\n\n<i>Перечислите через запятую или нажмите «Пропустить»</i>",
        ['reply_markup' => kbSkip()]
    );
}

function promptLiked(int $chatId): void {
    sendMessage($chatId,
        "❤️ <b>Что понравилось или привлекло внимание?</b>\n\n<i>Звуки, качества, ощущения…</i>",
        ['reply_markup' => kbSkip()]
    );
}

function promptDisliked(int $chatId): void {
    sendMessage($chatId,
        "💔 <b>Что мешало или казалось лишним?</b>",
        ['reply_markup' => kbSkip()]
    );
}

function promptPhoto(int $chatId): void {
    sendMessage($chatId,
        "📷 Хотите прикрепить фото места?\n\nПришлите фотографию или нажмите «Пропустить».",
        ['reply_markup' => kbSkip()]
    );
}

function promptDone(int $chatId, int $tgId, string $userName, array $data): void {
    $userId  = findOrCreateUser($tgId, $userName);
    $entryId = saveEntry($userId, $data);

    $sounds = $data['sounds'] ?? [];
    $soundLines = '';
    foreach (['front' => '⬆️', 'right' => '➡️', 'back' => '⬇️', 'left' => '⬅️'] as $dir => $arrow) {
        $v = trim($sounds[$dir] ?? '');
        if ($v && $v !== '—') $soundLines .= "\n  {$arrow} {$v}";
    }

    $text  = "✨ <b>Запись сохранена!</b>\n\n";
    $text .= "🎧 Слушание пространства · {$data['duration']} мин";
    if (!empty($data['place']))    $text .= "\n📍 {$data['place']}";
    if ($soundLines)               $text .= "\n\n🔊 <b>Звуки:</b>{$soundLines}";
    if (!empty($data['liked']))    $text .= "\n\n❤️ {$data['liked']}";
    if (!empty($data['disliked'])) $text .= "\n\n💔 {$data['disliked']}";
    $text .= "\n\n<i>Запись #{$entryId} добавлена в дневник.</i>";
    $text .= "\n\nНапишите /start чтобы начать новую практику.";

    setSession($tgId, 'idle', []);
    sendMessage($chatId, $text, ['reply_markup' => kbRemove()]);
}

// ── Webhook entry point ───────────────────────────────────────────────────────

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

try {

    // ── Inline callback (выбор длительности) ──────────────────────────────────
    if (!empty($update['callback_query'])) {
        $cq     = $update['callback_query'];
        $tgId   = (int)$cq['from']['id'];
        $chatId = (int)$cq['message']['chat']['id'];
        $name   = trim(($cq['from']['first_name'] ?? '') . ' ' . ($cq['from']['last_name'] ?? ''))
                  ?: ($cq['from']['username'] ?? 'User');

        tgSend('answerCallbackQuery', ['callback_query_id' => $cq['id']]);

        if (preg_match('/^dur_(\d+)$/', $cq['data'] ?? '', $m)) {
            $sess = getSession($tgId);
            if ($sess['step'] === 'choose_duration') {
                $duration = (int)$m[1];
                $sess['data']['duration'] = $duration;
                setSession($tgId, 'waiting_done', $sess['data']);
                promptWaitingDone($chatId, $duration);
            }
        }
        exit;
    }

    // ── Текстовое сообщение или фото ──────────────────────────────────────────
    if (empty($update['message'])) exit;

    $msg    = $update['message'];
    $tgId   = (int)$msg['from']['id'];
    $chatId = (int)$msg['chat']['id'];
    $text   = trim($msg['text'] ?? '');
    $photo  = $msg['photo'] ?? null;
    $name   = trim(($msg['from']['first_name'] ?? '') . ' ' . ($msg['from']['last_name'] ?? ''))
              ?: ($msg['from']['username'] ?? 'User');

    // /start и «начать» сбрасывают состояние
    if ($text === '/start' || mb_strtolower($text) === 'начать') {
        setSession($tgId, 'choose_duration', []);
        promptChooseDuration($chatId);
        exit;
    }

    $sess   = getSession($tgId);
    $step   = $sess['step'];
    $data   = $sess['data'];
    $isSkip = in_array(mb_strtolower($text), ['пропустить', '⏭ пропустить', '/skip'], true);

    switch ($step) {

        case 'idle':
            sendMessage($chatId, "Напишите /start чтобы начать практику.", ['reply_markup' => kbRemove()]);
            break;

        case 'choose_duration':
            sendMessage($chatId, "Выберите длительность кнопкой выше 👆", ['reply_markup' => kbDurations()]);
            break;

        case 'waiting_done':
            if ($text === '✅ Готово' || mb_strtolower($text) === 'готово') {
                setSession($tgId, 'ask_place', $data);
                promptPlace($chatId);
            } else {
                sendMessage($chatId, "Нажмите «✅ Готово» когда завершите практику.", ['reply_markup' => kbDone()]);
            }
            break;

        case 'ask_place':
            $data['place'] = $isSkip ? '' : $text;
            setSession($tgId, 'ask_front', $data);
            promptSound($chatId, 'front');
            break;

        case 'ask_front':
            $data['sounds']['front'] = $isSkip ? '' : $text;
            setSession($tgId, 'ask_right', $data);
            promptSound($chatId, 'right');
            break;

        case 'ask_right':
            $data['sounds']['right'] = $isSkip ? '' : $text;
            setSession($tgId, 'ask_back', $data);
            promptSound($chatId, 'back');
            break;

        case 'ask_back':
            $data['sounds']['back'] = $isSkip ? '' : $text;
            setSession($tgId, 'ask_left', $data);
            promptSound($chatId, 'left');
            break;

        case 'ask_left':
            $data['sounds']['left'] = $isSkip ? '' : $text;
            setSession($tgId, 'ask_liked', $data);
            promptLiked($chatId);
            break;

        case 'ask_liked':
            $data['liked'] = $isSkip ? '' : $text;
            setSession($tgId, 'ask_disliked', $data);
            promptDisliked($chatId);
            break;

        case 'ask_disliked':
            $data['disliked'] = $isSkip ? '' : $text;
            setSession($tgId, 'ask_photo', $data);
            promptPhoto($chatId);
            break;

        case 'ask_photo':
            if ($photo) {
                $data['photo_file_id'] = end($photo)['file_id'];
                promptDone($chatId, $tgId, $name, $data);
            } elseif ($isSkip || $text !== '') {
                promptDone($chatId, $tgId, $name, $data);
            }
            break;

        default:
            setSession($tgId, 'idle', []);
            sendMessage($chatId, "Напишите /start чтобы начать практику.");
    }

} catch (Throwable $e) {
    error_log('[bot.php] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}
