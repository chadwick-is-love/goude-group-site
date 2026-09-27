<?php
/*
 * log.php - reads the Studio post log back (STUDIO_LOG_2026-09-26).
 * Same login as push.php. Returns the newest entries for one brand so the
 * queue can mark the rows that already went out. Never a general file reader.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
$remoteUser = $_SERVER['REMOTE_USER'] ?? ($_SERVER['REDIRECT_REMOTE_USER'] ?? '');
if ($remoteUser === '') { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'not authenticated']); exit; }
$brand = $_GET['brand'] ?? '';
if (!in_array($brand, ['goude', 'gabes'], true)) { echo json_encode(['ok' => false, 'error' => 'unknown brand']); exit; }
$limit = max(1, min(400, (int)($_GET['limit'] ?? 200)));
$file = __DIR__ . '/data/posts.jsonl';
$out = [];
if (is_readable($file)) {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
            $row = json_decode($lines[$i], true);
            if (is_array($row) && ($row['brand'] ?? '') === $brand) $out[] = $row;
        }
    }
}
echo json_encode(['ok' => true, 'brand' => $brand, 'count' => count($out), 'posts' => $out], JSON_UNESCAPED_SLASHES);
