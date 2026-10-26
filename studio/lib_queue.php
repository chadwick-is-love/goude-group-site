<?php
/*
 * lib_queue.php - the queue's storage and decisions, shared by queue.php
 * (the web endpoint) and cron.php (the server's own clock).
 *
 * STUDIO_HANDSOFF_2026-10-15. Kemmet's audit of the first live day found the
 * approve step never ran on time: GitHub fired the 15-minute job about every
 * 4 to 5 hours, so four >gabes posts and six Goude posts expired unposted.
 * Since then a queued post is scheduled in OmniSocials the moment it is
 * queued (slots.json schedule_on_add, default true). OmniSocials holds it
 * until its slot; Cancel on the review page is the stop button, and
 * "Change in the Studio" sends a new version that replaces the scheduled one.
 *
 * Every decision on a row takes a lock on that row and marks it "posting"
 * before anything reaches OmniSocials, so two requests can never post the
 * same row twice, and a request killed mid-post leaves the row marked
 * instead of open to a second attempt.
 */

require_once __DIR__ . '/lib_post.php';

function q_dir() {
    $d = __DIR__ . '/data/queue';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    if (!is_file(__DIR__ . '/data/.htaccess')) @file_put_contents(__DIR__ . '/data/.htaccess', "Require all denied\n");
    if (!is_dir($d)) throw new RuntimeException('the queue folder could not be created under studio/data/. check it is writable.');
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
// An exclusive lock on one row (or one slot key), held until q_unlock.
function q_lock($name) {
    $f = @fopen(q_dir() . '/.lock-' . substr(sha1($name), 0, 16), 'c');
    if (!$f) return null;
    if (!flock($f, LOCK_EX | LOCK_NB)) { fclose($f); return false; }
    return $f;
}
function q_unlock($f) { if ($f) { flock($f, LOCK_UN); fclose($f); } }

function q_slots() {
    $p = __DIR__ . '/slots.json';
    $j = is_readable($p) ? json_decode(file_get_contents($p), true) : null;
    return is_array($j) ? $j : [];
}
function q_schedule_on_add() {
    $cfg = q_slots();
    return !array_key_exists('schedule_on_add', $cfg) || $cfg['schedule_on_add'] !== false;
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
        if (!is_array($j) || empty($j['id'])) continue;
        if ($brand !== '' && ($j['brand'] ?? '') !== $brand) continue;
        // a pending post whose slot has gone by is missed, never sent late
        if (($j['status'] ?? '') === 'pending' && (int)$j['publish_at'] < time() + 600) {
            $j['status'] = 'missed'; $j['updated_at'] = gmdate('c'); q_save($j); q_drop_images($j);
        }
        // a row left "posting" for 15 minutes was cut off mid-post: it may or
        // may not be in OmniSocials, so it is never retried on its own
        if (($j['status'] ?? '') === 'posting' && strtotime($j['updated_at'] ?? '') < time() - 900) {
            $j['status'] = 'unknown';
            $j['error'] = 'the request was cut off while posting. Check the OmniSocials calendar for this slot before queueing it again.';
            $j['updated_at'] = gmdate('c'); q_save($j);
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

/*
 * Queue one post. $in is the Studio's add body. With replace true (a hand
 * edit sent from the Studio) a scheduled post for the same slot is cancelled
 * in OmniSocials and replaced; without it (a week run) the slot is left
 * alone. Returns ['ok'=>bool, 'error'=>..., 'post'=>row].
 */
function q_add(array $in, $by) {
    $brand = $in['brand'] ?? '';
    $date  = (string)($in['date'] ?? '');
    $slot  = (string)($in['slot_key'] ?? '');
    $replace = !empty($in['replace']);
    $publishAt = q_slot_time($brand, $date, $slot);
    if ($publishAt === null) return ['ok' => false, 'error' => 'no slot time for ' . $brand . ' ' . $date . ' ' . $slot . ' in slots.json'];
    if ($publishAt < time() + 900) return ['ok' => false, 'error' => 'the ' . $date . ' ' . $slot . ' slot has passed or is under 15 minutes away; not queued.'];
    if ($publishAt > time() + 10 * 86400) return ['ok' => false, 'error' => 'the ' . $date . ' ' . $slot . ' slot is more than 10 days out; not queued.'];
    $req = [
        'brand' => $brand, 'platform' => $in['platform'] ?? '', 'caption' => $in['caption'] ?? '',
        'images' => $in['images'] ?? [], 'x_mode' => $in['x_mode'] ?? '', 'thread' => $in['thread'] ?? null,
        'slot' => $in['slot'] ?? '', 'issue' => $in['issue'] ?? '',
    ];
    try {
        $dry = studio_post($req, $by, ['dry_run' => true]);
    } catch (StudioFail $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    // one post per brand, platform, date and slot; the slot lock keeps two
    // runs from queueing the same slot at once
    $key = $brand . '|' . $req['platform'] . '|' . $date . '|' . $slot;
    $lk = q_lock('slot|' . $key);
    if ($lk === false) return ['ok' => false, 'error' => $dry['label'] . ' for ' . $date . ' ' . $slot . ' is being queued by another request right now.'];
    try {
        foreach (q_all($brand) as $old) {
            if (($old['key'] ?? '') !== $key) continue;
            $st = $old['status'];
            if ($st === 'posting' || $st === 'unknown') {
                return ['ok' => false, 'error' => $dry['label'] . ' for ' . $date . ' ' . $slot . ' is ' . ($st === 'posting' ? 'being posted right now' : 'in an unknown state (check OmniSocials, then reject it on the review page)') . '; not replaced.'];
            }
            if ($st === 'scheduled') {
                if (!$replace) {
                    return ['ok' => false, 'error' => $dry['label'] . ' for ' . $date . ' ' . $slot . ' is already scheduled as post ' . ($old['result']['post_id'] ?? '?') . '; left alone.'];
                }
                $c = q_cancel($old['id'], $by);
                if (!$c['ok']) return ['ok' => false, 'error' => 'the scheduled ' . $dry['label'] . ' post could not be cancelled, so it was not replaced: ' . $c['error']];
                $old = q_load($old['id']); $old['replaced'] = true; q_save($old);
            }
            if ($st === 'pending' || $st === 'failed') {
                q_drop_images($old); @unlink(q_dir() . '/' . $old['id'] . '.json');
            }
        }
        $id = $brand . '-' . $date . '-' . strtolower($slot) . '-' . preg_replace('/[^a-z_]/', '', $req['platform']) . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
        $images = is_array($req['images']) ? $req['images'] : [];
        foreach ($images as $n => $dataUrl) {
            $bin = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
            if (@file_put_contents(q_dir() . '/' . $id . '-' . $n . '.png', $bin) === false) return ['ok' => false, 'error' => 'could not store frame ' . ($n + 1) . ' on the server.'];
        }
        $req['images'] = [];   // frames live in the PNG files, not in the JSON
        $row = [
            'id' => $id, 'key' => $key, 'brand' => $brand, 'platform' => $req['platform'], 'label' => $dry['label'],
            'kind' => $dry['kind'], 'images' => count($images), 'caption' => $dry['caption'],
            'date' => $date, 'slot_key' => $slot, 'slot' => $req['slot'], 'issue' => $req['issue'],
            'colour' => mb_substr((string)($in['colour'] ?? ''), 0, 40),
            'statement' => mb_substr((string)($in['statement'] ?? ''), 0, 300),
            'publish_at' => $publishAt, 'publish_at_iso' => gmdate('c', $publishAt),
            'status' => 'pending', 'created_by' => $by, 'created_at' => gmdate('c'), 'updated_at' => gmdate('c'),
            'via' => mb_substr((string)($in['via'] ?? 'studio'), 0, 20),
            'request' => $req,
        ];
        q_save($row);
    } finally {
        q_unlock($lk);
    }
    if (q_schedule_on_add()) {
        $d = q_decide($id, 'approve', $by, 'queue');
        $row = q_load($id) ?: $row;
        if (!$d['ok']) { unset($row['request']); return ['ok' => false, 'error' => 'queued but not scheduled: ' . ($d['error'] ?? '?'), 'post' => $row]; }
    }
    unset($row['request']);
    return ['ok' => true, 'post' => $row];
}

// One decision on one queued post. Shared by the approve and reject buttons,
// the auto-approve run and q_add. The row lock plus the "posting" mark mean
// a second request on the same row gets "already posting", never a second post.
function q_decide($id, $action, $by, $via = 'approve') {
    $lk = q_lock('row|' . $id);
    if ($lk === false) return ['id' => $id, 'ok' => false, 'error' => 'already being decided by another request'];
    try {
        $row = q_load($id);
        if (!$row) return ['id' => $id, 'ok' => false, 'error' => 'not found'];
        // a failed or unknown row can be cleared with reject, so its slot can be queued again
        $clearable = ($action === 'reject' && in_array($row['status'], ['failed', 'unknown'], true));
        if ($row['status'] !== 'pending' && !$clearable) return ['id' => $id, 'ok' => false, 'error' => 'already ' . $row['status']];
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
        $row['status'] = 'posting'; $row['updated_at'] = gmdate('c'); q_save($row);
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
    } finally {
        q_unlock($lk);
    }
}

// STUDIO_CANCEL_2026-10-14: stop a scheduled post. Deletes it in OmniSocials
// (DELETE /posts/{id}, 204; a 404 means it is already gone) and marks the row
// cancelled, frames kept, so the same slot can be queued again.
function q_cancel($id, $by) {
    $row = q_load($id);
    if (!$row) return ['id' => $id, 'ok' => false, 'error' => 'not found'];
    if ($row['status'] !== 'scheduled') return ['id' => $id, 'ok' => false, 'error' => 'not scheduled (' . $row['status'] . ')'];
    if ((int)$row['publish_at'] < time() + 120) return ['id' => $id, 'ok' => false, 'error' => 'it posts in under two minutes; cancel it in OmniSocials'];
    $pid = $row['result']['post_id'] ?? '';
    if ($pid === '') return ['id' => $id, 'ok' => false, 'error' => 'no OmniSocials post id on record'];
    $key = omnisocials_key($row['brand']);
    if ($key === '') return ['id' => $id, 'ok' => false, 'error' => 'no OmniSocials key for ' . $row['brand']];
    $r = omnisocials_request('DELETE', '/posts/' . rawurlencode((string)$pid), $key);
    $st = $r['status'] ?? 0;
    if (!$r['ok'] && $st !== 404) {
        return ['id' => $id, 'ok' => false, 'error' => 'OmniSocials refused the delete (' . $st . '): ' . substr((string)($r['raw'] ?? ($r['error'] ?? '')), 0, 160)];
    }
    $row['status'] = 'cancelled'; $row['cancelled_by'] = $by; $row['updated_at'] = gmdate('c');
    $row['cancelled_post_id'] = $pid;
    q_save($row);
    return ['id' => $id, 'ok' => true, 'status' => 'cancelled', 'post_id' => $pid, 'already_gone' => ($st === 404)];
}

// ONE_POST_A_DAY_2026-10-26: a post whose slot has been taken out of
// slots.json is retired. Scheduled ones are deleted in OmniSocials through
// q_cancel; pending ones never reached OmniSocials and are just closed.
// Runs inside q_autoapprove, so the hourly GitHub job and the Hostinger cron
// both clean up after a slot is removed.
function q_retire_orphans() {
    $cfg = q_slots(); $done = [];
    foreach (q_all() as $row) {
        $brand = $row['brand'] ?? ''; $slot = (string)($row['slot_key'] ?? '');
        if ($slot === '' || !isset($cfg[$brand]['slots']) || array_key_exists($slot, $cfg[$brand]['slots'])) continue;
        if ((int)$row['publish_at'] < time() + 120) continue;
        if ($row['status'] === 'scheduled') {
            $res = q_cancel($row['id'], 'slot retired');
        } elseif ($row['status'] === 'pending') {
            $row['status'] = 'cancelled'; $row['cancelled_by'] = 'slot retired'; $row['updated_at'] = gmdate('c');
            q_save($row); q_drop_images($row);
            $res = ['id' => $row['id'], 'ok' => true, 'status' => 'cancelled'];
        } else continue;
        $res['brand'] = $brand; $res['slot'] = $row['date'] . ' ' . $slot . ' ' . $row['platform'] . ' retired';
        $done[] = $res;
        if (count($done) >= 40) break;
    }
    return $done;
}

// Approve every pending post inside the window. With schedule_on_add on,
// nothing should be pending; this stays as the backstop for rows that were
// queued with it off.
function q_autoapprove() {
    $cfg = q_slots();
    $win = max(15, min(720, (int)($cfg['auto_approve_minutes'] ?? 90))) * 60;
    $done = q_retire_orphans();
    foreach (q_all('', 'pending') as $row) {
        if ((int)$row['publish_at'] > time() + $win) continue;
        if (count($done) >= 30) break;
        $res = q_decide($row['id'], 'approve', 'auto', 'auto');
        $res['brand'] = $row['brand']; $res['slot'] = $row['date'] . ' ' . $row['slot_key'] . ' ' . $row['platform'];
        $done[] = $res;
    }
    return ['window_minutes' => $win / 60, 'results' => $done];
}
