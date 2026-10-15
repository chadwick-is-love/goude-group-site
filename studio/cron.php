<?php
/*
 * cron.php - STUDIO_HANDSOFF_2026-10-15. The server's own clock.
 *
 * GitHub runs scheduled jobs hours late on this repo (Kemmet's audit,
 * 2026-10-15: the 15-minute job fired 5 times in 17 hours; the Monday
 * Briefing starts 5 to 8 hours late). Hostinger's cron runs on time, so it
 * drives the clock and GitHub only does the heavy work when asked:
 *
 *   1. Backstop: approve any pending post inside the auto-approve window
 *      (only matters if slots.json schedule_on_add is ever turned off).
 *   2. >gabes, weekdays 09:10 to 17:30 New York: if a slot today has nothing
 *      queued, start the Studio autoqueue workflow for gabes (at most every
 *      45 minutes).
 *   3. Goude, weekdays 06:00 to 17:00: if a slot left this week has nothing
 *      queued, start it for goude (at most every 3 hours).
 *   4. Monday 00:20 to 20:00: if goudegroup.com/briefing does not carry this
 *      Monday's date yet, start the Briefing workflow (at most every 3 hours).
 *
 * Command line only; the web gets a 403. Set up once in hPanel, Advanced,
 * Cron Jobs, every 15 minutes:
 *   php /home/u644675766/domains/goudegroup.com/public_html/studio/cron.php
 * (use the real path to this file on the host).
 *
 * Starting a workflow needs a GitHub fine-grained token for
 * chadwick-is-love/goude-group-site with Actions: Read and write, in
 * studio/github-dispatch-token.txt (gitignored, web-blocked). Without it
 * steps 2 to 4 log "no token" and only step 1 runs.
 *
 *   php cron.php --dry   prints what it would do and starts nothing
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('command line only'); }

require __DIR__ . '/lib_queue.php';

const GH_REPO = 'chadwick-is-love/goude-group-site';
$DRY = in_array('--dry', $argv ?? [], true);
$tz = new DateTimeZone(q_slots()['timezone'] ?? 'America/New_York');
$now = new DateTime('now', $tz);
$dow = (int)$now->format('N');          // 1 Monday .. 7 Sunday
$hm = $now->format('H:i');

function c_log($m) { echo gmdate('Y-m-d H:i') . 'Z ' . $m . "\n"; }
function c_state_path() { return q_dir() . '/../cron-state.json'; }
function c_state() { $j = @json_decode(@file_get_contents(c_state_path()), true); return is_array($j) ? $j : []; }
function c_state_save($s) { @file_put_contents(c_state_path(), json_encode($s, JSON_PRETTY_PRINT)); }
function c_token() {
    $p = __DIR__ . '/github-dispatch-token.txt';
    return is_readable($p) ? trim(file_get_contents($p)) : '';
}
function c_dispatch($workflow, array $inputs, $why) {
    global $DRY;
    $tok = c_token();
    if ($tok === '') { c_log("$workflow due ($why) but there is no github-dispatch-token.txt; not started"); return false; }
    if ($DRY) { c_log("dry: would start $workflow " . json_encode($inputs) . " ($why)"); return true; }
    $ch = curl_init('https://api.github.com/repos/' . GH_REPO . '/actions/workflows/' . $workflow . '/dispatches');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: goude-studio-cron', 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['ref' => 'main', 'inputs' => (object)$inputs])]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    c_log("started $workflow " . json_encode($inputs) . " ($why): HTTP $code" . ($code === 204 ? '' : ' ' . substr((string)$resp, 0, 200)));
    return $code === 204;
}
// Due and not started inside $gapMin minutes: start it and remember when.
function c_maybe($name, $workflow, array $inputs, $why, $gapMin) {
    $s = c_state();
    if (!empty($s[$name]) && $s[$name] > time() - $gapMin * 60) { c_log("$name due ($why), started " . round((time() - $s[$name]) / 60) . " min ago; waiting"); return; }
    if (c_dispatch($workflow, $inputs, $why)) { $s[$name] = time(); c_state_save($s); }
}
// The slots of $brand on $dates still ahead (20+ minutes) with no row queued.
function c_open_slots($brand, array $dates) {
    $cfg = q_slots(); $open = [];
    $rows = q_all($brand);
    foreach ($dates as $date) {
        foreach (array_keys($cfg[$brand]['slots'] ?? []) as $slot) {
            $at = q_slot_time($brand, $date, $slot);
            if ($at === null || $at < time() + 1200) continue;
            $has = false;
            foreach ($rows as $r) {
                if ($r['date'] === $date && $r['slot_key'] === $slot && $r['status'] !== 'missed') { $has = true; break; }
            }
            if (!$has) $open[] = $date . ' ' . $slot;
        }
    }
    return $open;
}

// 1. backstop
$a = q_autoapprove();
foreach ($a['results'] as $r) c_log('auto-approved ' . $r['brand'] . ' ' . $r['slot'] . ': ' . ($r['ok'] ? 'scheduled' : $r['error']));

// 2. >gabes, today
if ($dow <= 5 && $hm >= '09:10' && $hm <= '17:30') {
    $open = c_open_slots('gabes', [$now->format('Y-m-d')]);
    if ($open) c_maybe('gabes', 'studio-autoqueue.yml', ['brand' => 'gabes'], 'open: ' . implode(', ', $open), 45);
}

// 3. Goude, what is left of this week
if ($dow <= 5 && $hm >= '06:00' && $hm <= '17:00') {
    $dates = []; $d = clone $now;
    for ($i = $dow; $i <= 5; $i++) { $dates[] = $d->format('Y-m-d'); $d->modify('+1 day'); }
    $open = c_open_slots('goude', $dates);
    if ($open) c_maybe('goude', 'studio-autoqueue.yml', ['brand' => 'goude'], 'open: ' . implode(', ', $open), 180);
}

// 4. the Briefing, Monday
if ($dow === 1 && $hm >= '00:20' && $hm <= '20:00') {
    $page = @file_get_contents(__DIR__ . '/../briefing/index.html');
    $today = $now->format('F j, Y');
    // the masthead date, not any date in the copy
    $mast = ($page !== false && preg_match('/class="mast-meta">(.{0,200})/s', $page, $mm)) ? $mm[1] : '';
    if ($page !== false && strpos($mast, $today) === false) {
        c_maybe('briefing', 'briefing.yml', [], 'goudegroup.com/briefing does not carry ' . $today . ' yet', 180);
    }
}
