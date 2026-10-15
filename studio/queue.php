<?php
/*
 * queue.php - STUDIO_AUTOPOST_2026-10-13, the queue's web endpoint.
 * Storage and decisions live in lib_queue.php (shared with cron.php).
 *
 * STUDIO_HANDSOFF_2026-10-15: a queued post is scheduled in OmniSocials the
 * moment it is queued (slots.json schedule_on_add). OmniSocials holds it
 * until its slot. Cancel on the review page stops it; a hand edit sent from
 * the Studio (replace:true) cancels the scheduled one and schedules the new
 * version. The approve and autoapprove actions stay for rows queued with
 * schedule_on_add off.
 *
 * Same login as the rest of the folder (studio/.htaccess). Storage is
 * studio/data/queue/, denied to the web by studio/data/.htaccess:
 *   <id>.json      the post: brand, platform, caption, slot, date, status
 *   <id>-<n>.png   its rendered frames, kept until a day after it posts
 *
 * Actions
 *   GET  ?action=list&brand=goude[&status=scheduled]  posts, by time, no images
 *   GET  ?action=img&id=<id>&n=0                       one frame as a PNG
 *   POST {action:"add", brand, platform, caption, images[], x_mode, thread,
 *         slot, slot_key, issue, date:"YYYY-MM-DD", colour, replace}
 *   POST {action:"approve", ids:[...]}   schedule pending rows (12 per call)
 *   POST {action:"reject",  ids:[...]}   drop pending rows, or clear failed/unknown ones
 *   POST {action:"cancel",  ids:[...]}   delete scheduled posts in OmniSocials
 *   POST {action:"autoapprove"}          approve pending rows inside the window
 *   POST {action:"notify",  brand}       one Slack line, if slack-webhook.txt exists
 *
 * Statuses: pending -> posting -> scheduled | failed | missed | unknown
 * (cut off mid-post: check OmniSocials). scheduled -> cancelled. pending ->
 * rejected.
 */

require __DIR__ . '/lib_queue.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$by = $_SERVER['REMOTE_USER'] ?? ($_SERVER['REDIRECT_REMOTE_USER'] ?? '');
if ($by === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not authenticated - the studio .htaccess auth block is missing or not applied. refusing.']);
    exit;
}

function q_out($a) { echo json_encode($a, JSON_UNESCAPED_SLASHES); exit; }
function q_err($m) { q_out(['ok' => false, 'error' => $m]); }

try { q_dir(); } catch (RuntimeException $e) { q_err($e->getMessage()); }

$action = $_GET['action'] ?? '';
$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) q_err('the request body is not JSON');
    $action = $in['action'] ?? '';
}

if ($action === 'list') {
    $brand = $_GET['brand'] ?? '';
    if ($brand !== '' && !in_array($brand, BRANDS, true)) q_err('unknown brand');
    $status = preg_replace('/[^a-z]/', '', $_GET['status'] ?? '');
    $rows = q_all($brand, $status);
    foreach ($rows as &$r) unset($r['request']);
    q_out(['ok' => true, 'count' => count($rows), 'posts' => $rows, 'now' => time(), 'schedule_on_add' => q_schedule_on_add()]);
}

if ($action === 'img') {
    $row = q_load($_GET['id'] ?? '');
    $n = (int)($_GET['n'] ?? 0);
    $p = $row ? (q_dir() . '/' . $row['id'] . '-' . $n . '.png') : '';
    if (!$row || !is_readable($p)) { http_response_code(404); q_err('no such frame'); }
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=3600');
    readfile($p);
    exit;
}

if ($action === 'add') {
    @set_time_limit(300);   // scheduling uploads the frames to OmniSocials
    q_out(q_add($in, $by));
}

if ($action === 'cancel') {
    $ids = $in['ids'] ?? [];
    if (!is_array($ids) || !$ids) q_err('no ids');
    $done = [];
    foreach (array_slice($ids, 0, 40) as $id) $done[] = q_cancel($id, $by);
    q_out(['ok' => true, 'results' => $done]);
}

if ($action === 'approve' || $action === 'reject') {
    $ids = $in['ids'] ?? [];
    if (!is_array($ids) || !$ids) q_err('no ids');
    @set_time_limit(300);   // each approval uploads frames and creates a post
    $done = [];
    foreach (array_slice($ids, 0, 12) as $id) $done[] = q_decide($id, $action, $by, 'approve');
    q_out(['ok' => true, 'results' => $done]);
}

if ($action === 'autoapprove') {
    @set_time_limit(300);
    q_out(['ok' => true] + q_autoapprove());
}

if ($action === 'notify') {
    $brand = $in['brand'] ?? '';
    if (!in_array($brand, BRANDS, true)) q_err('unknown brand');
    $hook = is_readable(__DIR__ . '/slack-webhook.txt') ? trim(file_get_contents(__DIR__ . '/slack-webhook.txt')) : '';
    if ($hook === '' || strpos($hook, 'https://hooks.slack.com/') !== 0) q_out(['ok' => true, 'sent' => false, 'why' => 'no slack-webhook.txt']);
    $n = count(q_all($brand, 'scheduled'));
    $name = $brand === 'gabes' ? '>gabes' : 'Goude';
    $text = $name . ': ' . $n . ' post' . ($n === 1 ? '' : 's') . ' scheduled. To stop one, cancel it: https://goudegroup.com/studio/approve.php?brand=' . $brand;
    $ch = curl_init($hook);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['text' => $text])]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    q_out(['ok' => true, 'sent' => $code === 200, 'scheduled' => $n]);
}

q_err('unknown action');
