<?php
/**
 * generate_card.php
 * POST { photo_file_id, sounds:{front,right,back,left}, place, liked, duration, telegram_bot_token }
 * Returns JSON { file, url, base64 }
 *
 * Fonts: download and place in api/fonts/
 *   Raleway-Light.ttf            — https://fonts.google.com/specimen/Raleway
 *   CormorantGaramond-LightItalic.ttf — https://fonts.google.com/specimen/Cormorant+Garamond
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// ── Constants ─────────────────────────────────────────────────────────────────
define('CW', 1080);
define('CH', 1920);

$FONT_DIR = __DIR__ . '/fonts/';
$TMP_DIR  = dirname(__DIR__) . '/tmp/';

// ── Input ─────────────────────────────────────────────────────────────────────
$body     = json_decode(file_get_contents('php://input'), true) ?: [];
$fileId   = $body['photo_file_id']      ?? '';
$sounds   = $body['sounds']             ?? [];
$place    = trim($body['place']         ?? '');
$liked    = trim($body['liked']         ?? '');
$duration = (int)($body['duration']     ?? 0);
$token    = $body['telegram_bot_token'] ?? TELEGRAM_BOT_TOKEN;

// ── Environment checks ────────────────────────────────────────────────────────
if (!extension_loaded('gd')) {
    echo json_encode(['error' => 'PHP GD extension not loaded']); exit;
}
if (!is_dir($TMP_DIR)) @mkdir($TMP_DIR, 0755, true);

// ── Fonts ─────────────────────────────────────────────────────────────────────
// Primary fonts (add to api/fonts/ — see file header for download links)
$fontRaleway   = $FONT_DIR . 'Raleway-Light.ttf';
$fontCormorant = $FONT_DIR . 'CormorantGaramond-LightItalic.ttf';

// Fallback to DejaVu (usually present on Linux shared hosting)
$fallback = '';
foreach ([
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
] as $f) {
    if (file_exists($f)) { $fallback = $f; break; }
}

if (!file_exists($fontRaleway))   $fontRaleway   = $fallback;
if (!file_exists($fontCormorant)) $fontCormorant = $fontRaleway;

if (!$fontRaleway) {
    echo json_encode(['error' => 'No usable TTF font found. Add Raleway-Light.ttf to api/fonts/']); exit;
}

// ── Telegram photo download ───────────────────────────────────────────────────
function downloadTgPhoto(string $fid, string $tok): ?string {
    global $TMP_DIR;
    $ch = curl_init("https://api.telegram.org/bot{$tok}/getFile?file_id={$fid}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $r    = curl_exec($ch); curl_close($ch);
    $path = json_decode($r, true)['result']['file_path'] ?? null;
    if (!$path) return null;

    $ch = curl_init("https://api.telegram.org/file/bot{$tok}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $img = curl_exec($ch); curl_close($ch);
    if (!$img) return null;

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'jpg';
    $tmp = $TMP_DIR . 'tg_' . md5($fid) . '.' . $ext;
    file_put_contents($tmp, $img);
    return $tmp;
}

// ── Color helpers ─────────────────────────────────────────────────────────────
// css_a: 0.0=transparent … 1.0=opaque  →  GD alpha: 127=transparent … 0=opaque
function ca($img, int $r, int $g, int $b, float $a): int {
    return imagecolorallocatealpha($img, $r, $g, $b, (int)round(127 * (1.0 - $a)));
}
function c($img, int $r, int $g, int $b): int {
    return imagecolorallocate($img, $r, $g, $b);
}

// ── TTF helpers ───────────────────────────────────────────────────────────────
function ttfw(float $sz, string $font, string $text): int {
    if ($text === '') return 0;
    $b = imagettfbbox($sz, 0, $font, $text);
    return max(1, abs($b[2] - $b[0]));
}

// Returns ascent (pixels above baseline) for given font/size
function ttfAscent(float $sz, string $font): int {
    $b = imagettfbbox($sz, 0, $font, 'Hy');
    return abs($b[7]); // upper-left y is negative (above baseline)
}

// Draw text at visual top-left position (auto converts to GD baseline)
function drawAt($img, float $sz, string $font, string $text, int $x, int $topY, $color): void {
    $asc = ttfAscent($sz, $font);
    imagettftext($img, $sz, 0, $x, $topY + $asc, $color, $font, $text);
}

// Draw horizontally centred text; returns bottom Y
function drawCentered($img, float $sz, string $font, string $text, int $topY, $color, float $spacing = 0.0): int {
    if ($spacing > 0.0) {
        // Letter-spacing: draw char by char
        $chars = mb_str_split($text);
        $totalW = 0;
        $ws = [];
        foreach ($chars as $ch) { $w = ttfw($sz, $font, $ch); $ws[] = $w; $totalW += $w + $spacing; }
        $totalW -= $spacing;
        $x = (int)((CW - $totalW) / 2);
        $asc = ttfAscent($sz, $font);
        foreach ($chars as $i => $ch) {
            imagettftext($img, $sz, 0, $x, $topY + $asc, $color, $font, $ch);
            $x += $ws[$i] + (int)$spacing;
        }
    } else {
        $x = (int)((CW - ttfw($sz, $font, $text)) / 2);
        drawAt($img, $sz, $font, $text, $x, $topY, $color);
    }
    $b = imagettfbbox($sz, 0, $font, $text);
    return $topY + abs($b[7]) - $b[1]; // topY + full line height
}

// Draw wrapped text centred; returns Y after last line
function drawWrappedCentered($img, float $sz, string $font, string $text, int $topY, $color, int $maxW, int $lineH): int {
    $words = preg_split('/\s+/u', trim($text));
    $lines = []; $cur = '';
    foreach ($words as $w) {
        $test = $cur !== '' ? "$cur $w" : $w;
        if (ttfw($sz, $font, $test) > $maxW && $cur !== '') { $lines[] = $cur; $cur = $w; }
        else $cur = $test;
    }
    if ($cur !== '') $lines[] = $cur;
    $y = $topY;
    foreach ($lines as $l) {
        $x = (int)((CW - ttfw($sz, $font, $l)) / 2);
        drawAt($img, $sz, $font, $l, $x, $y, $color);
        $y += $lineH;
    }
    return $y;
}

// ── Gradient overlay (scanlines) ─────────────────────────────────────────────
// stops: [t => css_alpha] where t is fraction 0..1 over [y0, y1]
function drawGradientOverlay($img, int $y0, int $y1, array $stops): void {
    $range = $y1 - $y0;
    if ($range <= 0) return;
    $keys = array_keys($stops);
    for ($y = $y0; $y <= $y1; $y++) {
        $t = ($y - $y0) / $range;
        $a = 0.0;
        for ($i = 0; $i < count($keys) - 1; $i++) {
            $t0 = $keys[$i]; $t1 = $keys[$i + 1];
            if ($t >= $t0 && $t <= $t1) {
                $a = $stops[$t0] + ($t - $t0) / ($t1 - $t0) * ($stops[$t1] - $stops[$t0]);
                break;
            }
        }
        $col = ca($img, 10, 10, 10, min(1.0, max(0.0, $a)));
        imageline($img, 0, $y, CW - 1, $y, $col);
    }
}

// ── Dashed arc ────────────────────────────────────────────────────────────────
function drawDashedArc($img, int $cx, int $cy, int $r, $color, float $dashDeg = 4.0, float $gapDeg = 7.0): void {
    for ($a = 0.0; $a < 360.0; $a += $dashDeg + $gapDeg) {
        imagearc($img, $cx, $cy, $r * 2, $r * 2, (int)$a, (int)min(359, $a + $dashDeg), $color);
    }
}

// ── Filled sector (triangle): center + two corner points ─────────────────────
function drawSectorFill($img, int $cx, int $cy, int $c1x, int $c1y, int $c2x, int $c2y, $fill, $border): void {
    imagefilledpolygon($img, [$cx, $cy, $c1x, $c1y, $c2x, $c2y], $fill);
    imageline($img, $cx, $cy, $c1x, $c1y, $border);
    imageline($img, $cx, $cy, $c2x, $c2y, $border);
}

// ── Directional arrow (filled triangle) ──────────────────────────────────────
function drawArrow($img, int $cx, int $cy, string $dir, $color, int $s = 26): void {
    [$pts] = match($dir) {
        'up'    => [[[$cx, $cy - $s, $cx - $s, $cy + $s, $cx + $s, $cy + $s]]],
        'right' => [[[$cx + $s, $cy, $cx - $s, $cy - $s, $cx - $s, $cy + $s]]],
        'down'  => [[[$cx, $cy + $s, $cx - $s, $cy - $s, $cx + $s, $cy - $s]]],
        'left'  => [[[$cx - $s, $cy, $cx + $s, $cy - $s, $cx + $s, $cy + $s]]],
    };
    imagefilledpolygon($img, $pts, $color);
}

// ═══════════════════════════════════════════════════════════════════════════════
// BUILD THE IMAGE
// ═══════════════════════════════════════════════════════════════════════════════

// 1. Download photo
$photoPath = null;
if ($fileId) $photoPath = downloadTgPhoto($fileId, $token);

// 2. Create canvas
$img = imagecreatetruecolor(CW, CH);
imagealphablending($img, true);
imagesavealpha($img, true);

// 3. Dark background
imagefilledrectangle($img, 0, 0, CW - 1, CH - 1, c($img, 10, 10, 10));

// 4. Photo background
if ($photoPath && file_exists($photoPath)) {
    $ext = strtolower(pathinfo($photoPath, PATHINFO_EXTENSION));
    $src = match($ext) {
        'png'  => @imagecreatefrompng($photoPath),
        'webp' => @imagecreatefromwebp($photoPath),
        default => @imagecreatefromjpeg($photoPath),
    };
    if ($src) {
        $sw = imagesx($src);  $sh = imagesy($src);
        $cr = CW / CH;        $ir = $sw / $sh;
        if ($ir > $cr) { $cw = (int)($sh * $cr); $ch2 = $sh; $cx = (int)(($sw - $cw) / 2); $cy = 0; }
        else           { $cw = $sw; $ch2 = (int)($sw / $cr); $cx = 0; $cy = (int)(($sh - $ch2) / 2); }
        imagecopyresampled($img, $src, 0, 0, $cx, $cy, CW, CH, $cw, $ch2);
        imagedestroy($src);
    }
    @unlink($photoPath);

    // Base dark overlay: rgba(10,10,10, 0.45)
    imagefilledrectangle($img, 0, 0, CW - 1, CH - 1, ca($img, 10, 10, 10, 0.45));

    // Gradient: bottom (from 25% down) 0→75%→97%
    drawGradientOverlay($img, (int)(CH * 0.25), CH - 1, [0.0 => 0.0, 0.4 => 0.75, 1.0 => 0.97]);

    // Gradient: top (0 to 25%) 60%→0%
    drawGradientOverlay($img, 0, (int)(CH * 0.25), [0.0 => 0.60, 1.0 => 0.0]);
}

// ── Palette ───────────────────────────────────────────────────────────────────
$cGold       = c($img, 201, 168, 76);
$cGoldDim    = ca($img, 201, 168, 76, 0.45);   // logo
$cGoldDim2   = ca($img, 201, 168, 76, 0.22);   // sector borders
$cGoldFill   = ca($img, 201, 168, 76, 0.07);   // sector fill
$cGoldDash   = ca($img, 201, 168, 76, 0.08);   // inner dashed circle
$cGoldArrow  = ca($img, 201, 168, 76, 0.15);   // empty sector arrow
$cBorder     = ca($img, 201, 168, 76, 0.25);   // outer circle
$cAxis       = ca($img, 201, 168, 76, 0.20);   // axis lines
$cLine       = ca($img, 201, 168, 76, 0.15);   // divider line
$cText       = c($img, 240, 235, 224);
$cTextAlpha  = ca($img, 240, 235, 224, 0.88);
$cCenterBg   = ca($img, 10, 10, 10, 0.95);
$cHeart      = ca($img, 220, 80, 80, 0.90);

// ── Map geometry ──────────────────────────────────────────────────────────────
$mapCX   = CW / 2;         // 540
$mapCY   = CH / 2 + 100;   // 1060
$mapR    = 440;

$extR = (int)($mapR * 1.2); // 528  — corner distance
$corners = [
    'top'    => [$mapCX,          $mapCY - $extR],   // (540, 532)
    'right'  => [$mapCX + $extR,  $mapCY],            // (1068, 1060)
    'bottom' => [$mapCX,          $mapCY + $extR],   // (540, 1588)
    'left'   => [$mapCX - $extR,  $mapCY],            // (12, 1060)
];

// Sector definitions: which 2 corners bound each sector
$sectorMap = [
    'front' => ['top',    'right'],
    'right' => ['right',  'bottom'],
    'back'  => ['bottom', 'left'],
    'left'  => ['left',   'top'],
];

// Parse sound words per direction
$soundWords = [];
foreach (['front', 'right', 'back', 'left'] as $d) {
    $soundWords[$d] = array_values(array_filter(array_map('trim', explode(',', $sounds[$d] ?? ''))));
}

// ── Sectors ───────────────────────────────────────────────────────────────────
foreach ($sectorMap as $dir => [$c1k, $c2k]) {
    if (empty($soundWords[$dir])) continue;
    [$c1x, $c1y] = $corners[$c1k];
    [$c2x, $c2y] = $corners[$c2k];
    drawSectorFill($img, $mapCX, $mapCY, $c1x, $c1y, $c2x, $c2y, $cGoldFill, $cGoldDim2);
}

// ── Axes ──────────────────────────────────────────────────────────────────────
imageline($img, $mapCX, $mapCY - $extR, $mapCX, $mapCY + $extR, $cAxis);
imageline($img, $mapCX - $extR, $mapCY, $mapCX + $extR, $mapCY, $cAxis);

// ── Outer circle ──────────────────────────────────────────────────────────────
imagesetthickness($img, 2);
imageellipse($img, $mapCX, $mapCY, $mapR * 2, $mapR * 2, $cBorder);

// ── Inner dashed circle ───────────────────────────────────────────────────────
imagesetthickness($img, 1);
$innerR = (int)($mapR * 0.45); // 198
drawDashedArc($img, $mapCX, $mapCY, $innerR, $cGoldDash);

// ── Center medallion ──────────────────────────────────────────────────────────
imagefilledellipse($img, $mapCX, $mapCY, 72, 72, $cCenterBg);
imagesetthickness($img, 2);
imageellipse($img, $mapCX, $mapCY, 72, 72, $cGold);
imagesetthickness($img, 1);
$jaSize = 20;
$jaW    = ttfw($jaSize, $fontRaleway, 'я');
$jaAsc  = ttfAscent($jaSize, $fontRaleway);
imagettftext($img, $jaSize, 0, $mapCX - (int)($jaW / 2), $mapCY + (int)($jaAsc / 2), $cGold, $fontRaleway, 'я');

// ── Arrows ────────────────────────────────────────────────────────────────────
$arrowDist = $mapR + 56; // 496
$arrowPos = [
    'front' => ['x' => $mapCX,               'y' => $mapCY - $arrowDist, 'dir' => 'up'],
    'right' => ['x' => $mapCX + $arrowDist,  'y' => $mapCY,              'dir' => 'right'],
    'back'  => ['x' => $mapCX,               'y' => $mapCY + $arrowDist, 'dir' => 'down'],
    'left'  => ['x' => $mapCX - $arrowDist,  'y' => $mapCY,              'dir' => 'left'],
];
foreach ($arrowPos as $dir => $pos) {
    $col = empty($soundWords[$dir]) ? $cGoldArrow : $cGold;
    drawArrow($img, $pos['x'], $pos['y'], $pos['dir'], $col);
}

// ── Sound words ───────────────────────────────────────────────────────────────
$wordSz  = 30;
$wordLH  = 40;
$zoneR   = (int)($mapR * 0.58); // 255

foreach (['front', 'back', 'right', 'left'] as $dir) {
    $ws = array_slice($soundWords[$dir], 0, 3);
    if (empty($ws)) continue;
    $totalH = count($ws) * $wordLH;

    if ($dir === 'front' || $dir === 'back') {
        // Vertical sector: centred text
        $zoneCY  = $mapCY + ($dir === 'front' ? -$zoneR : $zoneR);
        $startY  = $zoneCY - (int)($totalH / 2);
        foreach ($ws as $i => $word) {
            $x = (int)((CW - ttfw($wordSz, $fontRaleway, $word)) / 2);
            drawAt($img, $wordSz, $fontRaleway, $word, $x, $startY + $i * $wordLH, $cText);
        }
        if (count($soundWords[$dir]) > 3) {
            $extra = '+' . (count($soundWords[$dir]) - 3);
            $ex    = (int)((CW - ttfw(22, $fontRaleway, $extra)) / 2);
            drawAt($img, 22, $fontRaleway, $extra, $ex, $startY + count($ws) * $wordLH, $cGoldDim);
        }
    } else {
        // Horizontal sector: left/right aligned
        $zoneCX  = $mapCX + ($dir === 'right' ? $zoneR : -$zoneR);
        $startY  = $mapCY - (int)($totalH / 2);
        foreach ($ws as $i => $word) {
            $ww = ttfw($wordSz, $fontRaleway, $word);
            $x  = $dir === 'right'
                ? $zoneCX - 60          // left-aligned (text goes right)
                : $zoneCX + 60 - $ww;   // right-aligned (text ends at anchor)
            drawAt($img, $wordSz, $fontRaleway, $word, $x, $startY + $i * $wordLH, $cText);
        }
        if (count($soundWords[$dir]) > 3) {
            $extra = '+' . (count($soundWords[$dir]) - 3);
            $ew = ttfw(22, $fontRaleway, $extra);
            $ex = $dir === 'right' ? $zoneCX - 60 : $zoneCX + 60 - $ew;
            drawAt($img, 22, $fontRaleway, $extra, $ex, $startY + count($ws) * $wordLH, $cGoldDim);
        }
    }
}

// ── Logo ──────────────────────────────────────────────────────────────────────
drawCentered($img, 24, $fontRaleway, 'КВАНТОВОЕ УХО', 68, $cGoldDim, 8.0);

// ── Practice label ────────────────────────────────────────────────────────────
$label = 'СЛУШАНИЕ ПРОСТРАНСТВА' . ($duration > 0 ? ' · ' . $duration . ' МИН' : '');
drawCentered($img, 18, $fontRaleway, $label, 118, $cGoldDim, 2.0);

// ── Place name ────────────────────────────────────────────────────────────────
if ($place !== '') {
    drawWrappedCentered($img, 44, $fontCormorant, $place, 164, $cGold, CW - 200, 54);
}

// ── Liked text ────────────────────────────────────────────────────────────────
if ($liked !== '') {
    $botY = $mapCY + $mapR + 100; // 1600

    // Divider line
    imageline($img, 200, $botY, CW - 200, $botY, $cLine);

    // Heart symbol
    $heartSz = 34;
    $heartAsc = ttfAscent($heartSz, $fontRaleway);
    $heartW   = ttfw($heartSz, $fontRaleway, '♥');
    imagettftext($img, $heartSz, 0, (int)((CW - $heartW) / 2), $botY + 56 + $heartAsc, $cHeart, $fontRaleway, '♥');

    // Liked text (wrapped, italic Cormorant)
    drawWrappedCentered($img, 30, $fontCormorant, $liked, $botY + 110, $cTextAlpha, CW - 200, 42);
}

// ── Save & return ─────────────────────────────────────────────────────────────
$outFile = $TMP_DIR . 'card_' . uniqid('', true) . '.png';
imagepng($img, $outFile, 8);
imagedestroy($img);

$b64 = 'data:image/png;base64,' . base64_encode(file_get_contents($outFile));
echo json_encode([
    'file'   => $outFile,
    'url'    => 'tmp/' . basename($outFile),
    'base64' => $b64,
], JSON_UNESCAPED_UNICODE);
