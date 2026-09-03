<?php
/*
 * push.php - posts the CURRENT card (whatever format/platform is on screen
 * in the studio) to OmniSocials, SCHEDULED 30 minutes out rather than a
 * permanent draft - Kemmet's explicit call, "the closest thing to posting
 * at the push of a button." This is a real behavior change from a plain
 * draft: a scheduled post PUBLISHES ITSELF when the time hits unless a
 * human actively cancels it first. The 30-minute window is the only safety
 * margin, not a guarantee - do not describe this to anyone as "nothing
 * goes live automatically," that stopped being true the moment scheduling
 * replaced publish_now:false.
 *
 * Unlike gabes-social-studio's push.php (which generates 5 platform-tuned
 * captions per signal via Claude), this app's caption is ONE shared field
 * across every format/platform - GABES.caption() in index.html builds it
 * once, independent of S.format, and the user is expected to check the
 * on-screen "N / max on <platform>" counter before switching formats. So
 * this endpoint posts ONE platform at a time, mirroring the existing
 * download() button (not downloadAll(), which iterates the queue, not
 * formats). Both brands now: GD_FORMATS (the Goude/Briefing side) carries
 * the same platform-named ids as GB_FORMATS plus threads, so one endpoint
 * serves both. The Goude side's X caption is a separate short-close variant
 * (GOUDE.captionX) - the full Briefing caption cannot fit 280.
 *
 * PROVEN 2026-08-28 against the real Goude Group / Gabes workspace:
 * - draft posts 100010052 (x) / 100010053 (ig_story), fired via a
 *   standalone script mirroring this exact upload->create shape:
 *   publish_now:false correctly returns status:"draft", published_urls:{}.
 *   type:"story" confirmed correct for Instagram stories the same way.
 * - post 100010067 (x, far-future scheduled_at, deleted immediately after):
 *   omitting publish_now and setting scheduled_at instead returns
 *   status:"scheduled" with schedule_at echoing the value sent - matches
 *   Brother Holiday's proven production card_upload.py pattern exactly
 *   (scheduled_at set, publish_now never sent). NOT separately proven: that
 *   a scheduled post actually fires and goes public at its time on THIS
 *   workspace - inherited from BH's own production history on a different
 *   workspace, not re-verified here (that would mean actually publishing
 *   something).
 */

require __DIR__ . '/omnisocials.php';

header('Content-Type: application/json');

/*
 * This endpoint schedules posts to live social accounts and takes no
 * credential of its own - it relies entirely on the Basic auth block in
 * studio/.htaccess covering this whole folder. goude-group-site is a PUBLIC
 * repository, so the request shape below is readable by anyone; without that
 * auth block an anonymous POST here puts something on The Goude Group's real
 * feeds 30 minutes later. If REMOTE_USER is empty, the auth block is missing
 * or not being applied, and this must not run.
 */
$remoteUser = $_SERVER['REMOTE_USER'] ?? ($_SERVER['REDIRECT_REMOTE_USER'] ?? '');
if ($remoteUser === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'this endpoint is not authenticated - the studio .htaccess auth block is missing or not being applied. refusing to post.']);
    exit;
}

// Superset of GB_FORMATS and GD_FORMATS in index.html - keep these in sync
// if either list ever changes. 'threads' is Goude-side only for now; gabes
// has no Threads format, which is harmless, the maps are supersets.
const PLATFORM_LABELS = [
    'x' => 'X', 'linkedin' => 'LinkedIn', 'ig_feed' => 'Instagram feed',
    'ig_story' => 'Instagram story', 'facebook' => 'Facebook',
    'threads' => 'Threads',
];
const PLATFORM_MAX_CHARS = [
    'x' => 280, 'linkedin' => 1300, 'ig_feed' => 900,
    'ig_story' => 140, 'facebook' => 1000, 'threads' => 500,
];
// ig_feed and ig_story share ONE Instagram channel id (confirmed live: GET
// /accounts returns a single instagram account carrying
// content_types:["post","story","reel"]) - the channel id alone cannot tell
// them apart, so the post TYPE does. Both values PROVEN 2026-08-28.
const PLATFORM_POST_TYPE = [
    'x' => 'post', 'linkedin' => 'post', 'ig_feed' => 'post',
    'ig_story' => 'story', 'facebook' => 'post', 'threads' => 'post',
];
const BRANDS = ['goude', 'gabes'];

function fail($msg, $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$key = omnisocials_key();
if ($key === '') {
    fail('no omnisocials key on the server. add omnisocials-key.txt next to push.php.');
}
$channels = omnisocials_channels();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$brand = $in['brand'] ?? '';
$k = $in['platform'] ?? '';
$label = PLATFORM_LABELS[$k] ?? $k;
$caption = trim($in['caption'] ?? '');
$dataUrl = $in['image'] ?? '';

// ---- server-side checks gate. Never trust the client-side pass alone.
if (!in_array($brand, BRANDS, true)) {
    fail('unknown brand: ' . $brand);
}
if (!array_key_exists($k, PLATFORM_LABELS)) {
    fail('unknown platform: ' . $k);
}
// channels.json is brand-scoped: brand -> platform key -> channel id. Every
// account in workspace 1000120 belongs to The Goude Group; the gabes map is
// deliberately empty until gabes.ai's own accounts are connected, so the
// gabes button fails here with a specific message rather than silently
// posting gabes work to Goude Group's feeds.
$channelId = trim($channels[$brand][$k] ?? '');
if ($channelId === '') {
    fail('no channel id configured for ' . $label . ' on the ' . $brand . ' side in channels.json');
}
if ($caption === '') {
    fail('empty caption');
}
// Count CHARACTERS, not bytes. strlen() counted bytes, so a caption using
// the Briefing's typographic punctuation (- and the middot in every kicker
// are multi-byte in UTF-8) could pass the client's character count and then
// be rejected here for a length the user cannot see. mb_strlen matches what
// the on-screen counter shows.
$max = PLATFORM_MAX_CHARS[$k];
$len = function_exists('mb_strlen') ? mb_strlen($caption, 'UTF-8') : strlen($caption);
if ($len > $max) {
    fail('caption is ' . $len . ' characters, over the ' . $max . ' limit for ' . $label . ' - shorten it before posting.');
}
if (!is_string($dataUrl) || strpos($dataUrl, 'data:image/png;base64,') !== 0) {
    fail('no rendered card image');
}
$bin = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
if ($bin === false || strlen($bin) === 0) {
    fail('card image failed to decode');
}

/*
 * STUDIO_DUP_GUARD_PATCH - refuse an identical card to the same platform
 * inside a short window.
 *
 * The client-side in-flight lock added the same day is the first line of
 * defence, but it lives in one page's memory: a reload, a second tab, or a
 * second person clears it. On 2026-09-03 six identical LinkedIn posts were
 * created in 11 seconds and NOTHING refused them, because this endpoint had
 * no concept of having just done the same thing.
 *
 * Ten minutes, because the real failure mode is not a double-click - it is
 * someone pressing again several minutes later since the post still is not
 * visible on the platform, which is exactly what scheduling 30 minutes out
 * guarantees. The refusal names the existing post id and is phrased as
 * "already queued", because that is what it is.
 */
$dupWindow = 600;
$dupFile = sys_get_temp_dir() . '/studio-post-' . md5($brand . '|' . $k . '|' . $caption) . '.json';
if (is_readable($dupFile)) {
    $prev = json_decode(@file_get_contents($dupFile), true);
    if (is_array($prev) && isset($prev['at']) && (time() - (int)$prev['at']) < $dupWindow) {
        $ago = time() - (int)$prev['at'];
        fail('this exact card was already queued to ' . $label . ' ' . $ago . ' seconds ago as post '
            . ($prev['post_id'] ?? '?') . '. It is scheduled and will publish on time - it does not appear on '
            . $label . ' until then, so nothing is wrong. If you really want a second copy, cancel that one '
            . 'in your OmniSocials calendar first.');
    }
}

// ---- 1) upload the card image
$tmp = tempnam(sys_get_temp_dir(), 'card') . '.png';
file_put_contents($tmp, $bin);
$upload = omnisocials_request('POST', '/media/upload', $key, [
    'file' => new CURLFile($tmp, 'image/png', $k . '.png'),
], true);
@unlink($tmp);
if (!$upload['ok'] || !isset($upload['data']['data']['id'])) {
    fail('upload failed: ' . ($upload['data']['error']['message'] ?? ($upload['error'] ?? 'unknown error')));
}
$mediaId = $upload['data']['data']['id'];

// ---- 2) create the post, scheduled 30 minutes out (not a permanent draft -
// this WILL publish itself when the time hits unless someone cancels it from
// the OmniSocials calendar first). publish_now is deliberately omitted, not
// set to false - combining it with scheduled_at was never tested and BH's
// proven pattern never sends it at all.
$scheduledAt = gmdate('Y-m-d\TH:i:s.000\Z', time() + 1800);
$create = omnisocials_request('POST', '/posts/create', $key, [
    'content' => $caption,
    'channels' => [$channelId],
    'type' => PLATFORM_POST_TYPE[$k],
    'media_ids' => [$mediaId],
    'scheduled_at' => $scheduledAt,
]);
if (!$create['ok'] || !isset($create['data']['data']['id'])) {
    fail('post create failed (media uploaded, id ' . $mediaId . '): '
        . ($create['data']['error']['message'] ?? ($create['error'] ?? 'unknown error')));
}

// STUDIO_DUP_GUARD_PATCH: remember this one so an immediate repeat is refused.
@file_put_contents($dupFile, json_encode([
    'at' => time(),
    'post_id' => $create['data']['data']['id'],
]));

echo json_encode([
    'ok' => true,
    'by' => $remoteUser,
    'brand' => $brand,
    'label' => $label,
    'post_id' => $create['data']['data']['id'],
    'media_id' => $mediaId,
    'status' => $create['data']['data']['status'] ?? null,
    'scheduled_at' => $scheduledAt,
]);
