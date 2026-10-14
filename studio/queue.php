<?php
/*
 * queue.php - STUDIO_AUTOPOST_2026-10-13. The approval queue.
 *
 * The Studio (or the headless run) renders a week and ADDS each post here
 * instead of posting it. Nothing reaches OmniSocials until a person approves
 * it on approve.php; approval schedules it for its slot time from slots.json
 * through the same studio_post() the Post button uses. Since 2026-10-14 a
 * post nobody rejects approves itself inside the auto-approve window (CK:
 * up to us to stop it, not start it). Every check runs twice: once on add
 * (dry run), once on approval (for real).
 *
 * Same login as the rest of the folder (studio/.htaccess). Storage is
 * studio/data/queue/, denied to the web by studio/data/.htaccess:
 *   <id>.json      the post: brand, platform, caption, slot, date, status
 *   <id>-<n>.png   its rendered frames, deleted once OmniSocials has them
 *
 * Actions
 *   GET  ?action=list&brand=goude[&status=pending]   posts, newest first, no images
 *   GET  ?action=img&id=<id>&n=0                      one frame as a PNG
 *   POST {action:"add", brand, platform, caption, images[], x_mode, thread,
 *         slot, issue, date:"YYYY-MM-DD", colour}      queue one post
 *   POST {action:"approve", ids:[...]}                schedule them at their slots (12 per call)
 *   POST {action:"reject",  ids:[...]}                drop them
 *   POST {action:"autoapprove"}                       approve every pending post
 *         whose time is inside slots.json auto_approve_minutes (default 90)
 *   POST {action:"notify",  brand, count}             one Slack line, if a
 *         webhook URL sits in slack-webhook.txt next to this file (web-blocked)
 *
 * Statuses: pending -> scheduled | rejected | failed | missed (the slot time
 * passed before anyone approved it). A re-add of the same brand, platform,
 * date and slot replaces a pending one and is refused once scheduled.
 */

require __DIR__ . '/lib_post.php';

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

function q_dir() {
    $d = __DIR__ . '/data/queue';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    if (!is_file(__DIR__ . '/data/.htaccess')) @file_put_contents(__DIR__ . '/data/.htaccess', "Require all denied\n");
    if (!is_dir($d)) q_err('the queue folder could not be created under studio/data/. check it is writable.');
    return $d;
}
function q_valid_id($id) { return is_string($id) && preg_match('/^[a-z0-9_-]{8,80}$/', $id); }
function q_load($id) {
    if (!q_valid_id($id)) return null;
    $p = q_dir() . '/' . $id . '.json';
    if (!is_readable($p)) return null;
    $j = json_decode(file_get_contents($p), true);
    return is_array($j) ? $j : null;
}
function q_save($row) {
    $p = q_dir() . '/' . $row['id'] . '.json';
    file_put_contents($p . '.tmp', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    rename($p . '.tmp', $p);
}
function q_drop_images($row) {
    for ($n = 0; $n < (int)($row['images'] ?? 0); $n++) @unlink(q_dir() . '/' . $row['id'] . '-' . $n . '.png');
}
function q_slots() {
    $p = __DIR__ . '/slots.json';
    $j = is_readable($p) ? json_decode(file_get_contents($p), true) : null;
    return is_array($j) ? $j : [];
}
// The slot time for a date and slot label, as unix time, from slots.json.
function q_slot_time($brand, $date, $slot) {
    $cfg = q_slots();
    $tz = new DateTimeZone($cfg['timezone'] ?? 'America/New_York');
    $slots = $cfg[$brand]['slots'] ?? [];
    $hm = $slots[$slot] ?? null;
    if (!$hm || !preg_match('/^\d{2}:\d{2}$/', $hm)) return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    $dt = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $hm, $tz);
    return $dt ? $dt->getTimestamp() : null;
}
function q_all($brand = '', $status = '') {
    $out = [];
    foreach (glob(q_dir() . '/*.json') ?: [] as $f) {
        $j = json_decode(file_get_contents($f), true);
        if (!is_array($j)) continue;
        if ($brand !== '' && ($j['brand'] ?? '') !== $brand) continue;
        // a pending post whose slot has gone by is missed, never sent late
        if (($j['status'] ?? '') === 'pending' && (int)$j['publish_at'] < time() + 600) {
            $j['status'] = 'missed'; $j['updated_at'] = gmdate('c'); q_save($j); q_drop_images($j);
        }
        // frames of a scheduled post go a day after it published
        if (!empty($j['frames_kept']) && (int)$j['publish_at'] < time() - 86400) {
            q_drop_images($j); $j['frames_kept'] = false; q_save($j);
        }
        if ($status !== '' && ($j['status'] ?? '') !== $status) continue;
        $out[] = $j;
    }
    usort($out, function ($a, $b) { return [(int)$a['publish_at'], $a['platform']] <=> [(int)$b['publish_at'], $b['platform']]; });
    return $out;
}

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
    q_out(['ok' => true, 'count' => count($rows), 'posts' => $rows, 'now' => time()]);
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
    $brand = $in['brand'] ?? '';
    $date  = (string)($in['date'] ?? '');
    $slot  = (string)($in['slot_key'] ?? '');
    $publishAt = q_slot_time($brand, $date, $slot);
    if ($publishAt === null) q_err('no slot time for ' . $brand . ' ' . $date . ' ' . $slot . ' in slots.json');
    if ($publishAt < time() + 900) q_err('the ' . $date . ' ' . $slot . ' slot has passed or is under 15 minutes away; not queued.');
    if ($publishAt > time() + 10 * 86400) q_err('the ' . $date . ' ' . $slot . ' slot is more than 10 days out; not queued.');
    $req = [
        'brand' => $brand, 'platform' => $in['platform'] ?? '', 'caption' => $in['caption'] ?? '',
        'images' => $in['images'] ?? [], 'x_mode' => $in['x_mode'] ?? '', 'thread' => $in['thread'] ?? null,
        'slot' => $in['slot'] ?? '', 'issue' => $in['issue'] ?? '',
    ];
    try {
        $dry = studio_post($req, $by, ['dry_run' => true]);
    } catch (StudioFail $e) {
        q_err($e->getMessage());
    }
    // one post per brand, platform, date and slot
    $key = $brand . '|' . $req['platform'] . '|' . $date . '|' . $slot;
    foreach (q_all($brand) as $old) {
        if (($old['key'] ?? '') !== $key) continue;
        if (in_array($old['status'], ['scheduled'], true)) {
            q_err($dry['label'] . ' for ' . $date . ' ' . $slot . ' is already scheduled as post ' . ($old['result']['post_id'] ?? '?') . '. Cancel it in OmniSocials to replace it.');
        }
        if ($old['status'] === 'pending') {
            q_drop_images($old); @unlink(q_dir() . '/' . $old['id'] . '.json');
        }
    }
    $id = $brand . '-' . $date . '-' . strtolower($slot) . '-' . preg_replace('/[^a-z_]/', '', $req['platform']) . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
    $images = is_array($req['images']) ? $req['images'] : [];
    foreach ($images as $n => $dataUrl) {
        $bin = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
        if (@file_put_contents(q_dir() . '/' . $id . '-' . $n . '.png', $bin) === false) q_err('could not store frame ' . ($n + 1) . ' on the server.');
    }
    $req['images'] = [];   // frames live in the PNG files, not in the JSON
    $row = [
        'id' => $id, 'key' => $key, 'brand' => $brand, 'platform' => $req['platform'], 'label' => $dry['label'],
        'kind' => $dry['kind'], 'images' => count($images), 'caption' => $dry['caption'],
        'date' => $date, 'slot_key' => $slot, 'slot' => $req['slot'], 'issue' => $req['issue'],
        'colour' => mb_substr((string)($in['colour'] ?? ''), 0, 40),
        'statement' => mb_substr((string)($in['statement'] ?? ''), 0, 300),
        'publish_at' => $publishAt, 'publish_at_iso' => gmdate('c', $publishAt),
        'status' => 'pending', 'created_by' => $by, 'created_at' => gmdate('c'), 'via' => mb_substr((string)($in['via'] ?? 'studio'), 0, 20),
        'request' => $req,
    ];
    q_save($row);
    unset($row['request']);
    q_out(['ok' => true, 'post' => $row]);
}

// One decision on one queued post. Shared by the approve and reject buttons
// and by the auto-approve run.
function q_decide($id, $action, $by, $via = 'approve') {
    $row = q_load($id);
    if (!$row) return ['id' => $id, 'ok' => false, 'error' => 'not found'];
    if ($row['status'] !== 'pending') return ['id' => $id, 'ok' => false, 'error' => 'already ' . $row['status']];
    if ($action === 'reject') {
        $row['status'] = 'rejected'; $row['decided_by'] = $by; $row['updated_at'] = gmdate('c');
        q_save($row); q_drop_images($row);
        return ['id' => $id, 'ok' => true, 'status' => 'rejected'];
    }
    $req = $row['request'];
    $req['images'] = [];
    for ($n = 0; $n < (int)$row['images']; $n++) {
        $p = q_dir() . '/' . $row['id'] . '-' . $n . '.png';
        if (!is_readable($p)) { $req['images'] = null; break; }
        $req['images'][] = 'data:image/png;base64,' . base64_encode(file_get_contents($p));
    }
    if ($req['images'] === null) return ['id' => $id, 'ok' => false, 'error' => 'a frame is missing on the server; re-queue it'];
    try {
        $r = studio_post($req, $by, ['scheduled_at' => (int)$row['publish_at'], 'via' => $via, 'queue_id' => $row['id']]);
        $row['status'] = 'scheduled'; $row['result'] = $r;
        $row['decided_by'] = $by; $row['updated_at'] = gmdate('c');
        // STUDIO_APPROVE_READ_2026-10-14: the frames stay until a day after
        // the post time, so a scheduled slot keeps its card and quick read.
        $row['frames_kept'] = true;
        if ($via === 'auto') $row['auto_approved'] = true;
        q_save($row);
        return ['id' => $id, 'ok' => true, 'status' => 'scheduled', 'post_id' => $r['post_id'], 'scheduled_at' => $r['scheduled_at']];
    } catch (StudioFail $e) {
        $msg = $e->getMessage();
        $missed = strpos($msg, 'passed or is under 10 minutes') !== false;
        $row['status'] = $missed ? 'missed' : 'failed'; $row['error'] = $msg;
        $row['decided_by'] = $by; $row['updated_at'] = gmdate('c');
        q_save($row);
        if ($missed) q_drop_images($row);
        return ['id' => $id, 'ok' => false, 'status' => $row['status'], 'error' => $msg];
    }
}

if ($action === 'approve' || $action === 'reject') {
    $ids = $in['ids'] ?? [];
    if (!is_array($ids) || !$ids) q_err('no ids');
    @set_time_limit(300);   // each approval uploads frames and creates a post
    $done = [];
    foreach (array_slice($ids, 0, 12) as $id) $done[] = q_decide($id, $action, $by, 'approve');
    q_out(['ok' => true, 'results' => $done]);
}

// STUDIO_AUTOAPPROVE_2026-10-14. CK: "it should be up to us to stop it, not
// start it." Every pending post whose time is inside the window
// (slots.json auto_approve_minutes, default 90) approves itself; a person
// stops a post by rejecting it before then. Called by the scheduled
// GitHub job every 15 minutes with the Studio login. POST {action:"autoapprove"}
if ($action === 'autoapprove') {
    @set_time_limit(300);
    $cfg = q_slots();
    $win = max(15, min(720, (int)($cfg['auto_approve_minutes'] ?? 90))) * 60;
    $done = [];
    foreach (q_all('', 'pending') as $row) {
        if ((int)$row['publish_at'] > time() + $win) continue;
        if (count($done) >= 30) break;
        $res = q_decide($row['id'], 'approve', 'auto', 'auto');
        $res['brand'] = $row['brand']; $res['slot'] = $row['date'] . ' ' . $row['slot_key'] . ' ' . $row['platform'];
        $done[] = $res;
    }
    q_out(['ok' => true, 'window_minutes' => $win / 60, 'results' => $done]);
}

if ($action === 'notify') {
    $brand = $in['brand'] ?? '';
    if (!in_array($brand, BRANDS, true)) q_err('unknown brand');
    $hook = is_readable(__DIR__ . '/slack-webhook.txt') ? trim(file_get_contents(__DIR__ . '/slack-webhook.txt')) : '';
    if ($hook === '' || strpos($hook, 'https://hooks.slack.com/') !== 0) q_out(['ok' => true, 'sent' => false, 'why' => 'no slack-webhook.txt']);
    $n = count(q_all($brand, 'pending'));
    $name = $brand === 'gabes' ? '>gabes' : 'Goude';
    $text = $name . ': ' . $n . ' post' . ($n === 1 ? '' : 's') . ' waiting for approval. https://goudegroup.com/studio/approve.php?brand=' . $brand;
    $ch = curl_init($hook);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['text' => $text])]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    q_out(['ok' => true, 'sent' => $code === 200, 'pending' => $n]);
}

q_err('unknown action');
